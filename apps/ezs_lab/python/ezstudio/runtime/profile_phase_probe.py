"""PROFILE observability markers, independent of scientific results."""
from __future__ import annotations
import json
import os
import time
from datetime import datetime, timezone
from pathlib import Path

def mark(module: str, phase: str) -> None:
    """Best-effort external timing trace; never interrupt an analysis."""
    try:
        root = Path(os.getenv("EZSTUDIO_OBSERVABILITY_ROOT", r"H:\EZStudio_plateforme\apps\ezs_lab\var\logs\observability"))
        session = os.getenv("EZSTUDIO_PROFILE_OBSERVABILITY_SESSION", "profile")
        path = root / "profile" / "phase-events" / session / "phases.jsonl"
        path.parent.mkdir(parents=True, exist_ok=True)
        row = {"schema": "ezstudio.observability.profile-phase.v1", "module": module,
               "phase": phase, "utc": datetime.now(timezone.utc).isoformat(),
               "monotonic_ns": time.perf_counter_ns(), "pid": os.getpid()}
        with path.open("a", encoding="utf-8", newline="\n") as output:
            output.write(json.dumps(row, ensure_ascii=False, separators=(",", ":")) + "\n")
    except Exception:
        pass
