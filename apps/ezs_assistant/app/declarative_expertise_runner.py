from __future__ import annotations
import hashlib, json, os, sqlite3
from datetime import datetime, timezone
from pathlib import Path
from ai_provider import build_ai_provider_from_env
from profile_identity import resolve_missing_artist
from profile_recording_disambiguation import duration_from_catalog
from profile_musicbrainz_structured import discover_recordings, fetch_certified_recording_details
from profile_web_investigator import research_each_assertion
from profile_web_local_review import review_web_items
from profile_final_verifier import verify_final_values, attach_documentary_findings

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_PRESET = ROOT / "ezs_lab" / "presets" / "assistant" / "profile-expertise-baseline-v2.json"
DEFAULT_DB = ROOT / "ezs_lab" / "data" / "benchmark.sqlite"

def _fp(obj):
    return hashlib.sha256(json.dumps(obj, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode()).hexdigest()

def sync_preset_to_sqlite(preset: dict, db_path=DEFAULT_DB, run_id=None) -> dict:
    now = datetime.now(timezone.utc).isoformat()
    phase = str(preset.get("phase") or "profile")
    con = sqlite3.connect(str(db_path))
    try:
        con.executescript("""
        CREATE TABLE IF NOT EXISTS assistant_engine_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, engine_key TEXT NOT NULL, revision_number INTEGER NOT NULL, revision_ref TEXT NOT NULL UNIQUE, fingerprint TEXT NOT NULL, params_json TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(engine_key, fingerprint));
        CREATE TABLE IF NOT EXISTS assistant_preset_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, phase TEXT NOT NULL, revision_number INTEGER NOT NULL, revision_ref TEXT NOT NULL UNIQUE, fingerprint TEXT NOT NULL, preset_id TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'promoted', created_at TEXT NOT NULL, UNIQUE(phase, fingerprint));
        CREATE TABLE IF NOT EXISTS assistant_preset_revision_engines (preset_revision_id INTEGER NOT NULL, engine_revision_id INTEGER NOT NULL, position INTEGER NOT NULL, PRIMARY KEY(preset_revision_id, position));
        CREATE TABLE IF NOT EXISTS assistant_preset_baselines (phase TEXT PRIMARY KEY, preset_revision_id INTEGER NOT NULL, updated_at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS assistant_run_preset (run_id INTEGER PRIMARY KEY, preset_revision_id INTEGER NOT NULL, captured_at TEXT NOT NULL);
        """)
        eids, erefs = [], {}
        for step in preset["pipeline"]:
            params = preset["engines"].get(step) or {}
            fp = _fp({"engine": step, "params": params})
            row = con.execute("SELECT id, revision_ref FROM assistant_engine_revisions WHERE engine_key=? AND fingerprint=?", (step, fp)).fetchone()
            if row:
                eid, eref = row[0], row[1]
            else:
                num = con.execute("SELECT COALESCE(MAX(revision_number),0)+1 FROM assistant_engine_revisions WHERE engine_key=?", (step,)).fetchone()[0]
                eref = f"ia.{step}.r{num}"
                eid = con.execute("INSERT INTO assistant_engine_revisions(engine_key,revision_number,revision_ref,fingerprint,params_json,created_at) VALUES(?,?,?,?,?,?)", (step, num, eref, fp, json.dumps(params, sort_keys=True), now)).lastrowid
            eids.append(eid); erefs[step] = eref
        pfp = _fp({"phase": phase, "pipeline": preset["pipeline"], "engines": preset["engines"]})
        prow = con.execute("SELECT id, revision_ref FROM assistant_preset_revisions WHERE phase=? AND fingerprint=?", (phase, pfp)).fetchone()
        if prow:
            pid, pref = prow[0], prow[1]
        else:
            pnum = con.execute("SELECT COALESCE(MAX(revision_number),0)+1 FROM assistant_preset_revisions WHERE phase=?", (phase,)).fetchone()[0]
            pref = f"EXPERTISE_{phase.upper()}.r{pnum}"
            pid = con.execute("INSERT INTO assistant_preset_revisions(phase,revision_number,revision_ref,fingerprint,preset_id,status,created_at) VALUES(?,?,?,?,?,'promoted',?)", (phase, pnum, pref, pfp, preset["id"], now)).lastrowid
            for pos, eid in enumerate(eids, 1):
                con.execute("INSERT INTO assistant_preset_revision_engines(preset_revision_id,engine_revision_id,position) VALUES(?,?,?)", (pid, eid, pos))
        con.execute("INSERT INTO assistant_preset_baselines(phase,preset_revision_id,updated_at) VALUES(?,?,?) ON CONFLICT(phase) DO UPDATE SET preset_revision_id=excluded.preset_revision_id,updated_at=excluded.updated_at", (phase, pid, now))
        if isinstance(run_id, int) and run_id > 0:
            con.execute("INSERT INTO assistant_run_preset(run_id,preset_revision_id,captured_at) VALUES(?,?,?) ON CONFLICT(run_id) DO UPDATE SET preset_revision_id=excluded.preset_revision_id,captured_at=excluded.captured_at", (run_id, pid, now))
        con.commit()
        return {"preset_revision_id": pid, "preset_ref": pref, "preset_fingerprint": pfp, "engine_refs": erefs}
    finally:
        con.close()

def execute_declarative_expertise(row: dict, ident: dict, *, preset_path=None, db_path=DEFAULT_DB, source_research=None) -> dict:
    preset = json.loads(Path(preset_path or DEFAULT_PRESET).read_text(encoding="utf-8"))
    reg = sync_preset_to_sqlite(preset, db_path=db_path, run_id=row.get("run_id"))
    engines = preset["engines"]
    state = {"row": dict(row), "ident": {**ident, "catalog_duration_ms": ident.get("catalog_duration_ms") or duration_from_catalog(row)}, "research": source_research or {"status": "skipped", "sources": []}}
    for step in preset["pipeline"]:
        p = engines.get(step) or {}
        if p.get("enabled") is False: continue
        if step == "identity_resolver" and not state["ident"].get("artist") and state["ident"].get("title"):
            c = resolve_missing_artist(state["ident"]["title"], build_ai_provider_from_env())
            if c.get("status") == "resolved": state["ident"]["artist"] = state["row"]["artist"] = c["artist"]
        elif step == "catalog_disambiguator":
            mb = discover_recordings(state["ident"].get("title"), state["ident"].get("artist"), duration_ms=state["ident"].get("catalog_duration_ms"), limit=int(p.get("search_limit", 20)))
            state["ident"]["recording_identity_verified"] = bool(mb.get("verified"))
            state["ident"]["certified_recording"] = mb.get("certified_recording")
            if mb.get("verified") and isinstance(mb.get("certified_recording"), dict):
                state["ident"]["certified_details"] = fetch_certified_recording_details(mb["certified_recording"].get("mbid"))
        elif step == "web_collector":
            state["investigation"] = research_each_assertion(state["row"], state["ident"], source_research=state["research"])
        elif step == "ocr_reader":
            state["ocr_params"] = p
        elif step == "evidence_extractor":
            inv_id = {**state["ident"], **((state.get("investigation") or {}).get("identity") or {})}
            state["local_review"] = review_web_items(state.get("investigation") or {"items": []}, inv_id)
        elif step == "set_verdict_engine":
            base = verify_final_values(state["row"], state["ident"], state["research"])
            state["verification"] = attach_documentary_findings(base, state.get("investigation") or {"items": [], "identity": state["ident"]}, state.get("local_review") or {"items": []})
            state["verification"]["preset_provenance"] = reg
    return state
