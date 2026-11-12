from __future__ import annotations
import argparse, json, sqlite3, zipfile
from datetime import datetime, timezone
from pathlib import Path

REGIONS = ("profile","stems","chords","lyrics")
TEXT_SUFFIXES = {".json",".txt",".log",".csv",".tsv"}

def rows(c, sql, params=()):
    cur=c.execute(sql,params)
    cols=[d[0] for d in cur.description]
    return [dict(zip(cols,r)) for r in cur.fetchall()]

def decode(r):
    o=dict(r)
    for k,v in list(o.items()):
        if k.endswith("_json") and isinstance(v,str):
            try:o[k[:-5]]=json.loads(v)
            except Exception:pass
    return o

def safe(v):
    import re
    return re.sub(r"[^A-Za-z0-9._-]+","-",v.strip()).strip("-._") or "item"

def tables(c):
    return {r[0] for r in c.execute("select name from sqlite_master where type='table'")}

def preset_payload(c, t, run_ids):
    req={
        "preset_module_revisions",
        "preset_phase_revisions",
        "preset_phase_revision_modules",
        "preset_phase_revision_parents",
        "scientific_run_preset",
        "preset_phase_baselines",
    }
    if not req.issubset(t):
        return {
            "schema":"ezstudio.preset.export.v1",
            "available":False,
            "run_links":[],
            "phase_revisions":[],
            "phase_parents":[],
            "phase_modules":[],
            "module_revisions":[],
            "baselines":[],
        }

    if run_ids:
        marks=",".join("?" for _ in run_ids)
        run_links=[decode(r) for r in rows(
            c,
            f"""select sg.*,rr.phase,rr.revision_ref,rr.fingerprint
                from scientific_run_preset sg
                join preset_phase_revisions rr on rr.id=sg.phase_revision_id
                where sg.run_id in ({marks})
                order by sg.run_id""",
            tuple(run_ids)
        )]
    else:
        run_links=[]

    phases=sorted({str(x["phase"]) for x in run_links if x.get("phase")})
    if not phases:
        return {
            "schema":"ezstudio.preset.export.v1",
            "available":True,
            "run_links":run_links,
            "phase_revisions":[],
            "phase_parents":[],
            "phase_modules":[],
            "module_revisions":[],
            "baselines":[],
        }

    rmarks=",".join("?" for _ in phases)
    phase_revisions=[decode(r) for r in rows(
        c,
        f"""select * from preset_phase_revisions
            where phase in ({rmarks})
            order by phase,revision_number""",
        tuple(phases)
    )]
    ids=[int(x["id"]) for x in phase_revisions]

    if ids:
        imarks=",".join("?" for _ in ids)
        phase_parents=[decode(r) for r in rows(
            c,
            f"""select * from preset_phase_revision_parents
                where child_phase_revision_id in ({imarks})
                   or parent_phase_revision_id in ({imarks})
                order by child_phase_revision_id,parent_phase_revision_id""",
            tuple(ids+ids)
        )]
        phase_modules=[decode(r) for r in rows(
            c,
            f"""select * from preset_phase_revision_modules
                where phase_revision_id in ({imarks})
                order by phase_revision_id,position""",
            tuple(ids)
        )]
    else:
        phase_parents=[]
        phase_modules=[]

    gids=sorted({int(x["module_revision_id"]) for x in phase_modules})
    if gids:
        gmarks=",".join("?" for _ in gids)
        module_revisions=[decode(r) for r in rows(
            c,
            f"""select * from preset_module_revisions
                where id in ({gmarks})
                order by module_key,revision_number""",
            tuple(gids)
        )]
    else:
        module_revisions=[]

    baselines=[decode(r) for r in rows(
        c,
        f"""select b.*,rr.revision_ref,rr.fingerprint
            from preset_phase_baselines b
            join preset_phase_revisions rr on rr.id=b.phase_revision_id
            where b.phase in ({rmarks})
            order by b.phase""",
        tuple(phases)
    )]

    return {
        "schema":"ezstudio.preset.export.v1",
        "available":True,
        "phases":phases,
        "run_links":run_links,
        "phase_revisions":phase_revisions,
        "phase_parents":phase_parents,
        "phase_modules":phase_modules,
        "module_revisions":module_revisions,
        "baselines":baselines,
    }

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--db",required=True)
    ap.add_argument("--song-id",required=True,type=int)
    ap.add_argument("--output",required=True)
    a=ap.parse_args()

    c=sqlite3.connect(a.db)
    t=tables(c)
    selected=rows(c,"select * from songs where id=?",(a.song_id,))
    if not selected: raise RuntimeError("catalog_song_not_found")
    song=selected[0]
    h=str(song.get("audio_sha256") or "")
    ids=[a.song_id] if not h else [
        int(r["id"]) for r in rows(
            c,"select id from songs where audio_sha256=? order by id",(h,)
        )
    ]
    marks=",".join("?" for _ in ids)

    runs=[] if "scientific_runs" not in t else [
        decode(r) for r in rows(
            c,
            f"select * from scientific_runs where song_id in ({marks}) order by id",
            tuple(ids)
        )
    ]
    active={
        x:[r for r in runs if r.get("item")==x and r.get("state")!="abandoned"]
        for x in REGIONS
    }
    jobs=[] if "analysis_jobs" not in t else [
        decode(r) for r in rows(
            c,
            f"select * from analysis_jobs where song_id in ({marks}) order by id",
            tuple(ids)
        )
    ]

    arts={}
    if runs and "scientific_artifacts" in t:
        rids=[int(r["id"]) for r in runs]
        rm=",".join("?" for _ in rids)
        for r in rows(
            c,
            f"select * from scientific_artifacts where run_id in ({rm}) order by run_id,id",
            tuple(rids)
        ):
            arts.setdefault(int(r["run_id"]),[]).append(decode(r))

    legacy=[] if "benchmark_runs" not in t else [
        decode(r) for r in rows(
            c,
            f"select * from benchmark_runs where song_id in ({marks}) order by id",
            tuple(ids)
        )
    ]

    human_validations=[] if "profile_human_validations" not in t else [
        decode(r) for r in rows(
            c,
            f"""select v.*
                from profile_human_validations v
                join scientific_runs sr on sr.id=v.run_id
                where sr.song_id in ({marks})
                order by v.run_id,v.subject_key""",
            tuple(ids)
        )
    ]

    present=[bool(active[x]) for x in REGIONS]
    gap=False
    valid=True
    for x in present:
        if not x: gap=True
        elif gap: valid=False

    latest={}
    for x in REGIONS:
        r=active[x][-1] if active[x] else None
        latest[x]=None if r is None else {
            "run_id":int(r["id"]),
            "public_id":r.get("public_id"),
            "state":r.get("state"),
            "engine_name":r.get("engine_name"),
            "engine_version":r.get("engine_version"),
            "model_name":r.get("model_name"),
        }

    preset=preset_payload(c,t,[int(r["id"]) for r in runs])

    manifest={
        "schema":"ezstudio.analysis-chain.preset-bundle.v1",
        "generated_at":datetime.now(timezone.utc).isoformat(),
        "song":song,
        "catalog_song_ids":ids,
        "analysis_chain":{
            "phases":latest,
            "integrity":{
                "order":list(REGIONS),
                "present":dict(zip(REGIONS,present)),
                "valid_prefix":valid,
            },
        },
        "preset_evolution":{
            "schema":preset["schema"],
            "available":preset["available"],
            "phases":preset.get("phases",[]),
            "baselines":preset.get("baselines",[]),
        },
        "counts":{
            "scientific_runs":len(runs),
            "analysis_jobs":len(jobs),
            "legacy_chords_runs":len(legacy),
            "profile_human_validations":len(human_validations),
            "preset_phase_revisions":len(preset.get("phase_revisions",[])),
            "preset_module_revisions":len(preset.get("module_revisions",[])),
        },
        "binary_audio_included":False,
    }

    out=Path(a.output)
    out.parent.mkdir(parents=True,exist_ok=True)
    with zipfile.ZipFile(out,"w",zipfile.ZIP_DEFLATED,compresslevel=9) as z:
        z.writestr("manifest.json",json.dumps(manifest,ensure_ascii=False,indent=2)+"\n")
        z.writestr("sqlite/analysis_jobs.json",json.dumps(jobs,ensure_ascii=False,indent=2,default=str)+"\n")
        z.writestr("sqlite/legacy_chords_runs.json",json.dumps(legacy,ensure_ascii=False,indent=2,default=str)+"\n")
        z.writestr("sqlite/profile_human_validations.json",json.dumps(human_validations,ensure_ascii=False,indent=2,default=str)+"\n")
        links=preset.get("run_links",[])
        for run in runs:
            rid=int(run["id"])
            phase=str(run.get("item") or "unknown")
            pid=str(run.get("public_id") or f"{phase}-{rid}")
            payload=dict(run)
            payload["artifacts"]=arts.get(rid,[])
            payload["preset"]=next((x for x in links if int(x["run_id"])==rid),None)
            z.writestr(
                f"sqlite/phases/{safe(phase)}/{safe(pid)}.json",
                json.dumps(payload,ensure_ascii=False,indent=2,default=str)+"\n"
            )
            for art in arts.get(rid,[]):
                p=Path(str(art.get("path") or ""))
                if p.is_file() and p.suffix.lower() in TEXT_SUFFIXES and p.stat().st_size<=25*1024*1024:
                    z.write(p,f"artifacts/{safe(phase)}/{safe(pid)}/{safe(p.name)}")

        for name in (
            "run_links","phase_revisions","phase_parents",
            "phase_modules","module_revisions","baselines"
        ):
            z.writestr(
                f"preset/{name}.json",
                json.dumps(preset.get(name,[]),ensure_ascii=False,indent=2,default=str)+"\n"
            )

        z.writestr(
            "README.txt",
            "EZStudio_lab Preset bundle v1\n"
            "Two orthogonal histories are preserved:\n"
            "1) scientific runs per song;\n"
            "2) genomic evolution of phase/gene revisions.\n"
            "No heavy audio binaries included.\n"
        )

    print(f"EZSTUDIO_CHROMOSOME_PRESET_EXPORT_V2_OK {out}")
    return 0

if __name__=="__main__":
    raise SystemExit(main())
