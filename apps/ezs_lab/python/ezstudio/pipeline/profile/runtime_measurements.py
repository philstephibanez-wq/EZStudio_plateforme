"""Best-effort per-process PROFILE runtime counters. Metadata only, never audio.

All metrics are observational. No field here claims GPU kernel attribution.
"""
from __future__ import annotations

import os
import time
import statistics
from pathlib import Path


class RuntimeMeasurements:
    def __init__(self):
        self.pid = os.getpid()
        self.started_ns = time.perf_counter_ns()
        self.start_cpu = time.process_time()
        self.samples = []
        self.sampling_errors = 0
        self._psutil = None
        self._process = None
        try:
            import psutil
            self._psutil = psutil
            self._process = psutil.Process(self.pid)
        except (ImportError, OSError):
            pass

    def sample(self):
        try:
            sample = {"elapsed_ms": round((time.perf_counter_ns() - self.started_ns) / 1e6, 3),
                      "process_cpu_seconds": round(time.process_time() - self.start_cpu, 6),
                      "rss_mib": None, "child_rss_mib": None,
                      "child_count": None, "read_bytes": None, "write_bytes": None}
            if self._process is not None:
                sample["rss_mib"] = round(self._process.memory_info().rss / 1048576, 3)
                children = self._process.children(recursive=True)
                sample["child_count"] = len(children)
                rss = 0
                for child in children:
                    try:
                        rss += child.memory_info().rss
                    except (self._psutil.NoSuchProcess, self._psutil.AccessDenied):
                        pass
                sample["child_rss_mib"] = round(rss / 1048576, 3)
                try:
                    io = self._process.io_counters()
                    sample["read_bytes"] = int(io.read_bytes)
                    sample["write_bytes"] = int(io.write_bytes)
                except (AttributeError, OSError, self._psutil.AccessDenied):
                    pass
            self.samples.append(sample)
        except Exception:
            self.sampling_errors += 1

    def summary(self):
        self.sample()
        def maximum(key):
            vals = [row[key] for row in self.samples if isinstance(row.get(key), (int, float))]
            return max(vals) if vals else None
        cpu_elapsed = time.process_time() - self.start_cpu
        return {
            "schema": "ezstudio.observability.runtime-process.v1",
            "observed_pid": self.pid,
            "measurement_scope": "own_process_and_children_memory_only",
            "wall_seconds": round((time.perf_counter_ns() - self.started_ns) / 1e9, 6),
            "process_cpu_seconds": round(cpu_elapsed, 6),
            "peak_rss_mib_sampled": maximum("rss_mib"),
            "peak_child_rss_mib_sampled": maximum("child_rss_mib"),
            "max_children_sampled": maximum("child_count"),
            "sample_count": len(self.samples),
            "sampling_errors": self.sampling_errors,
            "psutil_available": self._process is not None,
            "gpu_physical_device_verified": False,
            "gpu_process_attribution": "not_instrumented",
            "gpu_usage_meaning": "existing_nvidia_smi_host_wide_not_process_attributed",
            "samples": self.samples,
        }
