"""Detailed, non-truncating structured diagnostics from MAIN's SQLite archive.

Preserves all source JSON items recursively. Secret and personal-path redactions
are recorded; archive is explicitly incomplete for XPS raw inference traces.
No audio, stems, models or binaries are archived.
"""
from __future__ import annotations
import hashlib
import json
import re
from urllib.parse import urlsplit, urlunsplit

PHASES={"profile":"profile","stems":"stems","chords":"chords_n","lyrics":"lyrics"}
SECRET_KEYS=re.compile(r"(?:^|_)(?:token|secret|password|passwd|credential|authorization|cookie|api_key|private_key|refresh_token|access_key)(?:_|$)",re.I)
SECRET_VALUES=re.compile(r"(?i)\b(?:bearer\s+\S{8,}|gh[pousr]_[a-z0-9_]{12,}|github_pat_[a-z0-9_]{12,}|sk-[a-z0-9_-]{12,})")
PERSONAL_PATHS=re.compile(r"(?i)(?:[a-z]:[\\/](?:users|utilisateurs)[\\/][^\\/\s]+|/(?:home|users)/[^/\s]+)")
PRIVATE_FIELD=re.compile(r"(?:^|_)(?:email|telephone|phone|address|session|jwt|user_home)(?:_|$)",re.I)
URLS=re.compile(r"https?://[^\s\x22\x27<>]+",re.I)
UNSAFE_FIELD=re.compile(r"(?i)(?:^|_)(?:audio_bytes|waveform|pcm|stem_bytes|binary|base64_audio|model_weights)(?:_|$)")

def filtered(value, redactions, key_path="$"):
    if isinstance(value,dict):
        out={}
        for k,v in value.items():
            k=str(k); loc=key_path+"."+k
            if SECRET_KEYS.search(k) or UNSAFE_FIELD.search(k) or PRIVATE_FIELD.search(k):
                out[k]="[REDACTED_SECRET_OR_BINARY]"
                redactions.append({"field":loc,"reason":"secret_or_binary_key"})
            else:
                out[k]=filtered(v,redactions,loc)
        return out
    if isinstance(value,list):
        return [filtered(v,redactions,key_path+"[]") for v in value]
    if isinstance(value,str):
        old=value
        value=SECRET_VALUES.sub("[REDACTED_SECRET]",value)
        value=PERSONAL_PATHS.sub("[REDACTED_PERSONAL_PATH]",value)
        def strip_credentials(match):
            try:
                parsed=urlsplit(match.group(0))
                hostname=parsed.hostname or ""
                if parsed.port:
                    hostname += ":" + str(parsed.port)
                return urlunsplit((parsed.scheme,hostname,parsed.path,"",""))
            except ValueError:
                return "[REDACTED_URL]"
        value=URLS.sub(strip_credentials,value)
        if old!=value: redactions.append({"field":key_path,"reason":"sensitive_value"})
        return value
    if value is None or isinstance(value,(bool,int,float)): return value
    raise TypeError("unsupported diagnostic value at "+key_path)

def parse(raw, field):
    try: return json.loads(raw if raw is not None else "{}")
    except (TypeError,ValueError) as e: raise ValueError("invalid_source_json:"+field) from e

def make_record(producer,phase,run_id,values,revision_id=None):
    redactions=[]
    clean=filtered(values,redactions)
    payload=json.dumps(clean,sort_keys=True,ensure_ascii=False,separators=(",",":"),allow_nan=False).encode("utf-8")
    # Snapshot hashes before privacy filtering are provenance evidence only.
    # Never publish source values or infer integrity from a hash alone.
    module_integrity=[]
    if producer=="lab" and phase=="profile":
        source_modules=values.get("diagnostics_json",{}).get("module_registry",{}).get("modules",[])
        clean_modules=clean.get("diagnostics_json",{}).get("module_registry",{}).get("modules",[])
        if isinstance(source_modules,list) and isinstance(clean_modules,list) and len(source_modules)==len(clean_modules):
            for original,archived in zip(source_modules,clean_modules):
                if not isinstance(original,dict) or not isinstance(archived,dict) or original.get("id")!=archived.get("id"):
                    raise ValueError("module_archive_identity_mismatch")
                def source_digest(obj):
                    canonical=json.dumps(obj,sort_keys=True,ensure_ascii=False,separators=(",",":"),allow_nan=False).encode("utf-8")
                    return hashlib.sha256(canonical).hexdigest()
                before=source_digest(original)
                after=source_digest(archived)
                module_integrity.append({"module":original.get("id"),"source_sha256_before_redaction":before,
                                         "archive_sha256_after_redaction":after,"archive_changed":before!=after})
    return {"schema":"ezstudio.diagnostics.main.v1","producer":producer,"phase":phase,
            "run_id":run_id,"revision_id":revision_id,"fields":clean,"redactions":redactions,
            "module_integrity":module_integrity,
            "fields_sha256":hashlib.sha256(payload).hexdigest(),
            "coverage":{"source":"MAIN SQLite","included_json_fields":sorted(k for k in values if k.endswith("_json")),
                        "not_available_from_main":["raw_xps_qwen_request","raw_xps_qwen_response","xps_trace_events"]}}

def reports(db, *, offset=0, limit=None, skip_row=None, revision_id=None):
    """Stable page over LAB runs then Assistant revisions, before JSON decoding."""
    seen=0
    emitted=0
    def eligible():
        nonlocal seen, emitted
        index=seen
        seen+=1
        return index >= offset and (limit is None or emitted < limit)
    db.row_factory=__import__("sqlite3").Row
    for row in ([] if revision_id is not None else db.execute("SELECT * FROM scientific_runs ORDER BY id DESC")):
        row=dict(row);phase=PHASES.get(row.get("item"))
        if not phase or row.get("state") not in ("done","error","failed","cancelled"):continue
        if limit is not None and emitted >= limit: return
        if not eligible(): continue
        if skip_row is not None and skip_row("lab", phase, row["id"], None, row): continue
        emitted+=1
        # Exclude database blobs/paths to local audio and stems; list every exclusion in the record.
        excluded=[k for k,v in row.items() if isinstance(v,bytes) or UNSAFE_FIELD.search(k)]
        values={k:parse(v,k) if k.endswith("_json") else v for k,v in row.items()
                if k not in excluded and k not in ("source_path","checkpoint")}
        record=make_record("lab",phase,row["id"],values)
        record["coverage"]["excluded_fields"]=sorted(set(excluded+["source_path","checkpoint"]))
        yield f"diagnostics/lab/{phase}/run-{row['id']:06d}/main-db.json",record
    table=db.execute("SELECT name FROM sqlite_master WHERE type='table' AND name='profile_external_revisions'").fetchone()
    if table:
        query = ("SELECT * FROM profile_external_revisions WHERE id=?" if revision_id is not None
                 else "SELECT * FROM profile_external_revisions ORDER BY id DESC")
        for row in db.execute(query, (revision_id,) if revision_id is not None else ()):
            if limit is not None and emitted >= limit: return
            if not eligible(): continue
            row=dict(row)
            if skip_row is not None and skip_row("assistant", "profile", row["run_id"], row["id"], row): continue
            emitted+=1
            values={k:parse(v,k) if k.endswith("_json") else v for k,v in row.items()}
            record=make_record("assistant","profile",row["run_id"],values,row["id"])
            yield f"diagnostics/assistant/profile/run-{row['run_id']:06d}/revision-{row['id']:06d}/main-db.json",record
