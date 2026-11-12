"""Optional PROFILE runtime probes and scientific output comparison.

No scientific computation and no scheduling policy is implemented here.
"""
from __future__ import annotations

import json
import os
import time
from pathlib import Path
from typing import Any

_TELEMETRY_KEYS = {"timing"}


def scientific_view(value: Any) -> Any:
    """Remove only the runtime timing field of each module record."""
    if isinstance(value, dict):
        return {k: scientific_view(v) for k, v in value.items() if k not in _TELEMETRY_KEYS}
    if isinstance(value, list):
        return [scientific_view(v) for v in value]
    return value


def compare_scientific(reference: Any, candidate: Any) -> dict[str, Any]:
    """Exact, ordered comparison. No undocumented floating-point tolerance."""
    a = scientific_view(reference)
    b = scientific_view(candidate)
    differences: list[str] = []

    def visit(x: Any, y: Any, path: str) -> None:
        if len(differences) >= 50:
            return
        if type(x) is not type(y):
            differences.append(path + ":type")
        elif isinstance(x, dict):
            if x.keys() != y.keys():
                differences.append(path + ":keys")
            for k in x.keys() & y.keys():
                visit(x[k], y[k], path + "/" + str(k))
        elif isinstance(x, list):
            if len(x) != len(y):
                differences.append(path + ":length")
            for i, (vx, vy) in enumerate(zip(x, y)):
                visit(vx, vy, path + "/" + str(i))
        elif x != y:
            differences.append(path + ":value")

    visit(a, b, "$")
    return {"equivalent": not differences, "difference_paths": sorted(differences)}


def probe_enabled() -> bool:
    return os.environ.get("EZSTUDIO_PROFILE_SCHEDULER_PROBE", "") == "1"


def run_with_probe(config: Any, context: Any, fn: Any, executor: Any) -> dict[str, Any]:
    """Measure entire executor including cleanup; return the original record unchanged."""
    if not probe_enabled():
        return executor(config, context, fn)
    started_wall = time.perf_counter()
    started_cpu = time.process_time()
    result = executor(config, context, fn)
    measurement = {
        "module_id": config.module_id,
        "wall_seconds_including_cleanup": round(time.perf_counter() - started_wall, 6),
        "process_cpu_seconds": round(time.process_time() - started_cpu, 6),
        "module_elapsed_seconds": (result.get("timing") or {}).get("elapsed_seconds"),
        "status": result.get("status"),
        "source_sha256": context.audio_sha256,
    }
    # Emit metadata only, never sound, model inputs, internal model scores, or secrets.
    print("EZSTUDIO_PROFILE_SCHEDULER_PROBE " + json.dumps(measurement, ensure_ascii=False), flush=True)
    return result
