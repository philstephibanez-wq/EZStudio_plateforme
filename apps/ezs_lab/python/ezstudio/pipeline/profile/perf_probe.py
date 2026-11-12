from __future__ import annotations

import json
import os
import statistics
import subprocess
import threading
import time
from datetime import datetime, timezone
from pathlib import Path

FIELDS = (
    "utilization.gpu",
    "memory.used",
    "memory.total",
    "temperature.gpu",
    "power.draw",
    "clocks.sm",
    "pstate",
)

def _utc() -> str:
    return datetime.now(timezone.utc).isoformat()

def _num(value: str):
    value = value.strip()
    if not value or value.upper() in {"N/A", "[N/A]"}:
        return None
    try:
        return float(value)
    except ValueError:
        return None

class ModulePerformanceProbe:
    def __init__(self, *, module_id: str, module_name: str, family: str, device: str | None, audio_sha256: str) -> None:
        self.module_id = module_id
        self.module_name = module_name
        self.family = family
        self.device = device
        self.audio_sha256 = audio_sha256
        self.started_at = _utc()
        self.samples: list[dict] = []
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None

        base = Path(os.getenv(
            "EZSTUDIO_OBSERVABILITY_ROOT",
            r"H:\EZStudio_plateforme\apps\ezs_lab\var\logs\observability",
        ))
        session = os.getenv("EZSTUDIO_PROFILE_OBSERVABILITY_SESSION", "profile")
        self.dir = base / "profile" / audio_sha256 / session

    def start(self) -> None:
        self.dir.mkdir(parents=True, exist_ok=True)
        self._thread = threading.Thread(
            target=self._sample_loop,
            name=f"perf-probe-{self.module_id}",
            daemon=True,
        )
        self._thread.start()

    def _sample_loop(self) -> None:
        while not self._stop.is_set():
            sample = self._sample_gpu()
            if sample is not None:
                self.samples.append(sample)
            self._stop.wait(1.0)

    def _sample_gpu(self) -> dict | None:
        exe = os.getenv("EZSTUDIO_NVIDIA_SMI", "nvidia-smi")
        query = ",".join(FIELDS)
        try:
            cp = subprocess.run(
                [exe, f"--query-gpu={query}", "--format=csv,noheader,nounits"],
                capture_output=True,
                text=True,
                timeout=2.5,
                check=False,
            )
            if cp.returncode != 0:
                return None
            line = next((x.strip() for x in cp.stdout.splitlines() if x.strip()), "")
            if not line:
                return None
            parts = [x.strip() for x in line.split(",")]
            if len(parts) < len(FIELDS):
                return None
            return {
                "timestamp": time.time(),
                "gpu_util_percent": _num(parts[0]),
                "memory_used_mib": _num(parts[1]),
                "memory_total_mib": _num(parts[2]),
                "temperature_c": _num(parts[3]),
                "power_w": _num(parts[4]),
                "sm_clock_mhz": _num(parts[5]),
                "pstate": parts[6],
            }
        except Exception:
            return None

    @staticmethod
    def _stats(samples: list[dict], key: str) -> dict:
        values = [float(row[key]) for row in samples if row.get(key) is not None]
        if not values:
            return {"mean": None, "max": None, "min": None}
        return {
            "mean": round(statistics.fmean(values), 2),
            "max": round(max(values), 2),
            "min": round(min(values), 2),
        }

    def finish(self, *, status: str, elapsed_seconds: float) -> None:
        self._stop.set()
        if self._thread is not None:
            self._thread.join(timeout=3.0)

        payload = {
            "schema": "ezstudio.observability.profile-module.v1",
            "scope": "observability-only",
            "included_in_preset": False,
            "audio_sha256": self.audio_sha256,
            "session": os.getenv("EZSTUDIO_PROFILE_OBSERVABILITY_SESSION", "profile"),
            "module": {
                "id": self.module_id,
                "name": self.module_name,
                "family": self.family,
                "declared_device": self.device,
            },
            "started_at": self.started_at,
            "finished_at": _utc(),
            "elapsed_seconds": float(elapsed_seconds),
            "status": status,
            "sampling_interval_seconds": 1.0,
            "summary": {
                "gpu_util_percent": self._stats(self.samples, "gpu_util_percent"),
                "memory_used_mib": self._stats(self.samples, "memory_used_mib"),
                "temperature_c": self._stats(self.samples, "temperature_c"),
                "power_w": self._stats(self.samples, "power_w"),
                "sm_clock_mhz": self._stats(self.samples, "sm_clock_mhz"),
                "sample_count": len(self.samples),
            },
            "samples": self.samples,
        }

        safe = "".join(ch if ch.isalnum() or ch in "._-" else "-" for ch in self.module_id)
        path = self.dir / f"{safe}.json"
        tmp = path.with_suffix(".tmp")
        tmp.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
        tmp.replace(path)


# Deprecated compatibility alias.
GenePerformanceProbe = ModulePerformanceProbe
