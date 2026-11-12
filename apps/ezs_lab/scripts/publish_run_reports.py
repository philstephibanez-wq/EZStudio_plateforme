"""Read-only MAIN publisher of sanitized LAB and Assistant run summaries.

Execution is best-effort and independent of the scientific workers.
Requires gh auth with write permission on private EZStudio_Run_Reports.
No models, audio, stems, paths, credentials, prompts or raw traces are exported.
"""
from __future__ import annotations

import argparse
import base64
import json
import os
from pathlib import Path
import re
import sqlite3
import subprocess
import sys
import hashlib
import time
from urllib.parse import urlsplit, urlunsplit

DEST = "philstephibanez-wq/EZStudio_Run_Reports"
PHASES = {"profile": "profile", "stems": "stems", "chords": "chords_n", "lyrics": "lyrics"}
FORBIDDEN = re.compile(
    r"token|secret|password|credential|authorization|cookie|bearer|"
    r"api.key|private.key|prompt|request|response|trace|"
    r"path|filepath|filename|directory|artifact|stem|audio|"
    r"hash|sha256|environment|config|command|url|uri|"
    r"raw|base64|binary|waveform|embedding|model.path",
    re.I,
)
SAFE_TEXT = re.compile(r"^[\w .,:;()/%+&?!°#'’=-]{0,250}$", re.UNICODE)
MAX_DEPTH = 7
MAX_ITEMS = 40
MAX_REPORT = 100_000
STATE_DIR = Path(os.environ.get("EZSTUDIO_REPORTS_STATE_DIR", r"H:\\EZStudio_Run_Reports_state"))


def sanitize(value, depth=0):
    """Explicit bounds + secret/path denylist; discard unknown free text."""
    if depth > MAX_DEPTH:
        return None
    if value is None or isinstance(value, bool):
        return value
    if isinstance(value, (int, float)):
        return value
    if isinstance(value, str):
        return value if SAFE_TEXT.fullmatch(value) and not re.search(
            r"[A-Za-z]:[\\\\/]|\\\\\\\\|https?://|@", value
        ) else None
    if isinstance(value, list):
        return [x for x in (sanitize(v, depth + 1) for v in value[:MAX_ITEMS]) if x is not None]
    if isinstance(value, dict):
        return {
            str(k): clean for k, v in list(value.items())[:MAX_ITEMS]
            if not FORBIDDEN.search(str(k))
            and (clean := sanitize(v, depth + 1)) is not None
        }
    return None



PUBLIC_HOSTS = frozenset(("musicbrainz.org", "www.musicbrainz.org",
    "en.wikipedia.org", "fr.wikipedia.org", "www.wikidata.org",
    "www.wikipedia.org", "wikidata.org"))

def sanitize_sources(raw):
    """Allowlisted public references only; never export arbitrary URLs or evidence payloads."""
    try:
        entries = json.loads(raw or "[]")
    except (ValueError, TypeError):
        return []
    if not isinstance(entries, list):
        return []
    kept = []
    for entry in entries[:MAX_ITEMS]:
        if not isinstance(entry, dict):
            continue
        value = entry.get("url")
        if not isinstance(value, str) or len(value) > 500:
            continue
        try:
            url = urlsplit(value)
            if url.scheme != "https" or url.hostname not in PUBLIC_HOSTS or url.username or url.password or url.port:
                continue
            if not url.path.startswith("/") or ".." in url.path or "\\\\" in url.path:
                continue
            clean_url = urlunsplit(("https", url.hostname, url.path, "", ""))
        except ValueError:
            continue
        kept.append({
            "name": sanitize(entry.get("name")) or "Public reference",
            "type": "consulted_reference" if entry.get("type") == "consulted_reference" else "public_reference",
            "url": clean_url,
            "identity_relevant": entry.get("identity_relevant") is True,
            "evidence_accepted": entry.get("evidence_accepted") is True,
        })
    return kept


def parse_json(raw):
    try:
        obj = json.loads(raw or "{}")
        return obj if isinstance(obj, dict) else {}
    except (ValueError, TypeError):
        return {}


def collect(db):
    db.row_factory = sqlite3.Row
    runs = db.execute(
        "SELECT id, public_id, item, state, engine_name, engine_version, "
        "metrics_json, diagnostics_json, created_at, finished_at "
        "FROM scientific_runs ORDER BY id DESC"
    )
    for run in runs:
        phase = PHASES.get(run["item"])
        if not phase or run["state"] not in {"done", "error", "failed", "cancelled"}:
            continue
        report = {
            "schema": "ezstudio.run-report.v1", "producer": "lab",
            "run_id": run["id"], "public_id": run["public_id"],
            "phase": phase, "state": run["state"],
            "engine": sanitize(run["engine_name"]),
            "engine_version": sanitize(run["engine_version"]),
            "created_at": run["created_at"], "finished_at": run["finished_at"],
            "metrics": sanitize(parse_json(run["metrics_json"])),
            "diagnostics": sanitize(parse_json(run["diagnostics_json"])),
        }
        yield f"lab/{phase}/run-{run['id']:06d}.json", report

    exists = db.execute(
        "SELECT name FROM sqlite_master WHERE type='table' "
        "AND name='profile_external_revisions'"
    ).fetchone()
    if not exists:
        return
    revisions = db.execute(
        "SELECT id, run_id, reviewer, status, sources_json, consensus_json, "
        "comparison_json, confidence, created_at, updated_at "
        "FROM profile_external_revisions ORDER BY id DESC"
    )
    for rev in revisions:
        report = {
            "schema": "ezstudio.run-report.v1", "producer": "assistant",
            "phase": "profile", "run_id": rev["run_id"],
            "revision_id": rev["id"], "reviewer": sanitize(rev["reviewer"]),
            "status": rev["status"], "created_at": rev["created_at"],
            "updated_at": rev["updated_at"], "confidence": rev["confidence"],
            "consensus": sanitize(parse_json(rev["consensus_json"])),
            "sources": sanitize_sources(rev["sources_json"]),
            "comparison": sanitize(parse_json(rev["comparison_json"])),
            "note": "Consultative; not independent scientific validation.",
        }
        yield (
            f"assistant/profile/run-{rev['run_id']:06d}/revision-{rev['id']:06d}.json",
            report,
        )


from report_diagnostics import reports as detailed_reports
from report_privacy_audit import scan_record


def gh(*args, input_json=None):
    command = ["gh", "api", *args]
    payload = None if input_json is None else json.dumps(input_json).encode("utf-8")
    res = subprocess.run(command, input=payload, stdout=subprocess.PIPE,
                         stderr=subprocess.PIPE, timeout=40, check=False)
    if res.returncode:
        raise RuntimeError("github_api_failed: " + res.stderr.decode("utf-8", "replace")[:240])
    return json.loads(res.stdout or b"{}")


def _git_blob_sha(raw):
    return hashlib.sha1(b"blob " + str(len(raw)).encode("ascii") + b"\x00" + raw).hexdigest()


def publish(path, report):
    raw = (json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2) + "\n").encode("utf-8")
    if len(raw) > MAX_REPORT and not path.startswith("diagnostics/"):
        report = dict(report)
        report["truncated"] = True
        if "diagnostics" in report:
            report["diagnostics"] = {"note": "Large diagnostics retained only on MAIN."}
        if "consensus" in report:
            report["consensus"] = {"note": "Large consensus retained only on MAIN."}
        if "comparison" in report:
            report["comparison"] = {"note": "Large comparison retained only on MAIN."}
        raw = (json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2) + "\n").encode("utf-8")
    if len(raw) > (4_000_000 if path.startswith("diagnostics/") else MAX_REPORT):
        raise ValueError("report_exceeds_explicit_limit_no_silent_truncation:" + path)
    endpoint = f"repos/{DEST}/contents/{path}"
    sha = None
    try:
        old = gh(endpoint)
        old_raw = base64.b64decode(old.get("content", ""))
        if old.get("sha") == _git_blob_sha(raw) or (old.get("content") and old_raw == raw):
            return "unchanged"
        sha = old["sha"]
    except RuntimeError as exc:
        if "404" not in str(exc):
            raise
    payload = {
        "message": "report: " + path, "branch": "main",
        "content": base64.b64encode(raw).decode("ascii"),
    }
    if sha:
        payload["sha"] = sha
    gh("--method", "PUT", endpoint, "--input", "-", input_json=payload)
    if path.startswith("diagnostics/"):
        remote = gh(endpoint)
        if remote.get("sha") != _git_blob_sha(raw):
            raise RuntimeError("github_roundtrip_mismatch:" + path)
    return "published"


def _digest(report):
    return hashlib.sha256(
        json.dumps(report, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()


def _load_state(path):
    try:
        state = json.loads(path.read_text(encoding="utf-8"))
        return state if isinstance(state, dict) else {}
    except (OSError, ValueError):
        return {}


def _save_state(path, state):
    path.parent.mkdir(parents=True, exist_ok=True)
    staged = path.with_suffix(".pending")
    staged.write_text(json.dumps(state, sort_keys=True), encoding="utf-8")
    os.replace(staged, path)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--db", default=str(Path(__file__).resolve().parents[1] / "data" / "benchmark.sqlite"))
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--producer", choices=("all", "lab", "assistant"), default="all")
    parser.add_argument("--limit", type=int, default=0, help="0 = all runs and revisions; positive = maximum GitHub operations")
    parser.add_argument("--state-dir", type=Path, default=STATE_DIR)
    parser.add_argument("--detailed", action="store_true", help="Opt in to detailed MAIN DB diagnostics after security validation")
    parser.add_argument("--audit-detailed", action="store_true", help="Inspect detailed archive serialization and residual privacy risks without any GitHub upload")
    parser.add_argument("--revision-id", type=int, default=None, help="Archive one Assistant revision, detailed-only; verify module coverage for sequential Qwen reviews")
    parser.add_argument("--detailed-only", action="store_true", help="Publish ONLY detailed archives (pilot mode, never summary reports)")
    parser.add_argument("--archive-offset", type=int, default=0, help="Skip first N detailed records before decoding JSON (use with --detailed-only)")
    args = parser.parse_args()
    if args.detailed_only and (not args.detailed or args.audit_detailed):
        parser.error("--detailed-only requires --detailed and excludes --audit-detailed")
    if args.archive_offset < 0 or (args.archive_offset and not args.detailed_only):
        parser.error("--archive-offset requires --detailed-only and offset >= 0")
    if args.limit < 0:
        parser.error("--limit must be >= 0")
    if args.revision_id is not None and (args.revision_id <= 0 or not args.detailed_only or args.producer != "assistant"):
        parser.error("--revision-id requires positive ID, --detailed-only and --producer assistant")
    if not Path(args.db).is_file():
        parser.error("database not found")
    state_file = args.state_dir / "published.json"
    state = {} if args.dry_run or args.audit_detailed else _load_state(state_file)
    pending_signatures = {}
    def skip_published(producer, phase, run_id, revision_id, row):
        path = (f"diagnostics/lab/{phase}/run-{run_id:06d}/main-db.json"
                if producer == "lab" else
                f"diagnostics/assistant/profile/run-{run_id:06d}/revision-{revision_id:06d}/main-db.json")
        digest = hashlib.sha256()
        for key in sorted(row):
            value = row[key]
            digest.update(value if isinstance(value, bytes) else str(value).encode("utf-8", "surrogatepass"))
            digest.update(b"\\0")
        source_sha = digest.hexdigest()
        pending_signatures[path] = source_sha
        return state.get("source:"+path) == source_sha and path in state
    checked = 0
    attempted = 0
    published = 0
    unchanged = 0
    failed = 0
    with sqlite3.connect(f"file:{Path(args.db).resolve().as_posix()}?mode=ro", uri=True) as db:
        row_skip = skip_published if args.detailed and not args.dry_run and not args.audit_detailed else None
        detailed = detailed_reports(db, offset=args.archive_offset, limit=(args.limit or None) if args.detailed_only else None, skip_row=row_skip, revision_id=args.revision_id)
        streams = (detailed,) if (args.audit_detailed or args.detailed_only) else ((collect(db), detailed) if args.detailed else (collect(db),))
        for path, report in (item for stream in streams for item in stream):
            if args.producer != "all" and report["producer"] != args.producer:
                continue
            checked += 1
            if args.revision_id is not None:
                consultation = report.get("fields", {}).get("comparison_json", {}).get("module_consultations")
                if not isinstance(consultation, dict):
                    raise ValueError("selected_revision_missing_module_consultations")
                modules = consultation.get("results")
                total = consultation.get("item_total")
                if not isinstance(modules, list) or total != 12 or len(modules) != total or consultation.get("complete") is not True:
                    raise ValueError("selected_revision_incomplete_module_coverage")
                if any(not isinstance(m, dict) or m.get("status") not in ("completed","abstain") for m in modules):
                    raise ValueError("selected_revision_module_status_invalid")
            if path.startswith("diagnostics/"):
                try:
                    raw = json.dumps(report, ensure_ascii=False, allow_nan=False).encode("utf-8")
                    if len(raw) > 4_000_000:
                        raise ValueError("diagnostic_too_large")
                    findings = scan_record(report)
                    if findings:
                        raise ValueError("privacy_findings:" + json.dumps(findings[:10],ensure_ascii=False))
                except Exception as exc:
                    failed += 1
                    print(f"blocked: {path}: {type(exc).__name__}: {str(exc)[:200]}", file=sys.stderr, flush=True)
                    continue
            digest = _digest(report)
            if not args.dry_run and not args.audit_detailed and state.get(path) == digest:
                # Legacy state had the archive digest only. Establish source
                # signature even on an unchanged report, so future runs skip
                # the record before JSON decoding and before counting the limit.
                if path in pending_signatures and state.get("source:"+path) != pending_signatures[path]:
                    state["source:"+path] = pending_signatures[path]
                    _save_state(state_file, state)
                unchanged += 1
                continue
            if args.limit and attempted >= args.limit:
                break
            attempted += 1
            try:
                if args.audit_detailed:
                    raw = json.dumps(report, ensure_ascii=False, allow_nan=False).encode("utf-8")
                    if len(raw) > 4_000_000:
                        raise ValueError("diagnostic_too_large")
                    findings = scan_record(report)
                    if findings:
                        raise ValueError("privacy_findings:" + json.dumps(findings[:10],ensure_ascii=False))
                    status = "audit-ok"
                elif args.dry_run:
                    status = "dry-run"
                else:
                    status = publish(path, report)
                    state[path] = digest
                    if path in pending_signatures:
                        state["source:"+path] = pending_signatures[path]
                    _save_state(state_file, state)
                    published += status == "published"
                    unchanged += status == "unchanged"
                print(f"{status}: {path}", flush=True)
            except Exception as exc:
                failed += 1
                print(f"failed: {path}: {type(exc).__name__}: {str(exc)[:200]}",
                      file=sys.stderr, flush=True)
    if args.revision_id is not None and checked != 1:
        raise RuntimeError("selected_revision_not_found_or_incomplete")
    print(f"REPORTS checked={checked} attempted={attempted} published={published} unchanged={unchanged} failed={failed}", flush=True)
    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
