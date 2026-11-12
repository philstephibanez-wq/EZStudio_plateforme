from __future__ import annotations

import json
import os
import socket
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ASSISTANT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_STATUS_PATH = ASSISTANT_ROOT / "logs" / "assistant.status.json"


def status_path() -> Path:
    raw = str(os.getenv("EZSTUDIOLAB_ASSISTANT_STATUS_FILE") or "").strip()
    return Path(raw) if raw else DEFAULT_STATUS_PATH


def write_status(
    state: str,
    activity: str,
    *,
    job_id: int | None = None,
    phase: str | None = None,
    progress: float | None = None,
    extra: dict[str, Any] | None = None,
) -> dict[str, Any]:
    now = time.time()
    payload: dict[str, Any] = {
        "schema": "ezstudio.assistant-local-status.v1",
        "state": str(state or "UNKNOWN").upper(),
        "activity": str(activity or "").strip(),
        "hostname": socket.gethostname(),
        "pid": os.getpid(),
        "at_epoch": now,
        "at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "job_id": int(job_id) if job_id is not None else None,
        "phase": str(phase).upper() if phase else None,
        "progress": float(progress) if progress is not None else None,
    }
    if extra:
        payload["extra"] = dict(extra)

    path = status_path()
    path.parent.mkdir(parents=True, exist_ok=True)

    # Each writer gets its own temporary file. Distributed and profile workers
    # can publish status concurrently, so a shared ".tmp" pathname is unsafe.
    with tempfile.NamedTemporaryFile(
        mode="w",
        encoding="utf-8",
        newline="\n",
        dir=path.parent,
        prefix=path.name + ".",
        suffix=".tmp",
        delete=False,
    ) as handle:
        handle.write(
            json.dumps(payload, ensure_ascii=False, separators=(",", ":")) + "\n"
        )
        handle.flush()
        os.fsync(handle.fileno())
        tmp = Path(handle.name)

    try:
        # Windows may transiently deny replacement while the status window is
        # reading the destination. Retry briefly; keep the final operation atomic.
        for attempt in range(8):
            try:
                os.replace(tmp, path)
                break
            except PermissionError:
                if attempt == 7:
                    raise
                time.sleep(0.025 * (attempt + 1))
    finally:
        if tmp.exists():
            tmp.unlink(missing_ok=True)

    return payload
