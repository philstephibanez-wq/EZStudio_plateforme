"""Durable, redacted receipt of the existing PROFILE report publication attempt.

The GitHub archive remains authoritative. This receipt is a local diagnostic,
not a replacement for the cross-repository archive or a musical verdict.
"""
from __future__ import annotations

from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import tempfile

ROOT = Path(__file__).resolve().parents[1]
TRACE_ID = re.compile(r"^[0-9a-f]{32}$")


def _destination(trace, root):
    run = getattr(trace, "run_id", None)
    trace_id = getattr(trace, "trace_id", None)
    if type(run) is not int or not 0 < run <= 999999999 or not isinstance(trace_id, str) or not TRACE_ID.fullmatch(trace_id):
        raise ValueError("profile_receipt_identity_invalid")
    base = Path(root) if root is not None else ROOT / "runtime" / "reports"
    return base / "profile" / f"run-{run:06d}" / f"trace-{trace_id[:12]}.json"


def _receipt(trace, outcome, status, error_type=None, job_id=None):
    revision = outcome.get("revision") if isinstance(outcome, dict) else None
    if type(revision) is not int or revision <= 0:
        revision = None
    return {
        "schema": "ezstudio.profile-report-publication-receipt.v1",
        "run_id": trace.run_id,
        "trace_id": trace.trace_id[:12],
        "revision_id": revision,
        "job_id": job_id,
        "status": status,
        "error_type": error_type,
        "recorded_at_utc": datetime.now(timezone.utc).isoformat(),
        "archive_destination": "EZStudio_Run_Reports",
        "archive_authoritative": True,
    }


def _atomic_json(destination, payload):
    destination.parent.mkdir(parents=True, exist_ok=True)
    # os.replace is atomic only on one filesystem. The receipt's ignored runtime
    # directory is its atomic staging area, including when TEMP is on C: or H:.
    fd, tmp_name = tempfile.mkstemp(prefix=".ezs-profile-", suffix=".tmp", dir=destination.parent)
    tmp = Path(tmp_name)
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
            json.dump(payload, handle, ensure_ascii=False, sort_keys=True, allow_nan=False)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(tmp, destination)
    finally:
        tmp.unlink(missing_ok=True)


def publish_with_receipt(trace, outcome, publisher, *, job_id=None, receipt_root=None):
    """Invoke the existing publisher once, persist only redacted status, retain errors.

    Does not change LAB acknowledgement or the remote publishing workflow.
    The caller still logs PROFILE_REPORT_ERROR on publication failure.
    """
    if job_id is not None and (type(job_id) is not int or job_id <= 0):
        raise ValueError("profile_receipt_job_id_invalid")
    destination = _destination(trace, receipt_root)
    try:
        published = publisher(trace, outcome)
    except Exception as exc:
        _atomic_json(destination, _receipt(trace, outcome, "failed", type(exc).__name__, job_id))
        raise
    status = "published" if published == "published" else (
        "disabled" if published == "disabled" or str(published).endswith(":disabled") else "partial"
    )
    _atomic_json(destination, _receipt(trace, outcome, status, job_id=job_id))
    return published
