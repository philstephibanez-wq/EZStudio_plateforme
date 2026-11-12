from __future__ import annotations

import ctypes
import json
import os
import platform
import socket
import subprocess
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Any


@dataclass(frozen=True)
class GpuCapability:
    name: str
    vram_total_mb: int
    driver_version: str | None
    compute_capability: str | None


@dataclass(frozen=True)
class WorkerCapabilities:
    protocol: str
    node_id: str
    hostname: str
    os: str
    architecture: str
    cpu_logical_count: int
    ram_total_mb: int | None
    gpus: tuple[GpuCapability, ...]
    cuda_available: bool
    shared_model_root: str | None
    local_model_cache: str | None

    @property
    def max_vram_mb(self) -> int:
        return max((gpu.vram_total_mb for gpu in self.gpus), default=0)

    def to_dict(self) -> dict[str, Any]:
        payload = asdict(self)
        payload["max_vram_mb"] = self.max_vram_mb
        return payload

    def to_json(self) -> str:
        return json.dumps(self.to_dict(), ensure_ascii=False, indent=2)


class _MemoryStatusEx(ctypes.Structure):
    _fields_ = [
        ("dwLength", ctypes.c_ulong),
        ("dwMemoryLoad", ctypes.c_ulong),
        ("ullTotalPhys", ctypes.c_ulonglong),
        ("ullAvailPhys", ctypes.c_ulonglong),
        ("ullTotalPageFile", ctypes.c_ulonglong),
        ("ullAvailPageFile", ctypes.c_ulonglong),
        ("ullTotalVirtual", ctypes.c_ulonglong),
        ("ullAvailVirtual", ctypes.c_ulonglong),
        ("ullAvailExtendedVirtual", ctypes.c_ulonglong),
    ]


def _ram_total_mb() -> int | None:
    if os.name == "nt":
        status = _MemoryStatusEx()
        status.dwLength = ctypes.sizeof(_MemoryStatusEx)
        if ctypes.windll.kernel32.GlobalMemoryStatusEx(ctypes.byref(status)):
            return int(status.ullTotalPhys // (1024 * 1024))
        return None
    meminfo = Path("/proc/meminfo")
    if meminfo.is_file():
        for line in meminfo.read_text(encoding="utf-8").splitlines():
            if line.startswith("MemTotal:"):
                parts = line.split()
                if len(parts) >= 2 and parts[1].isdigit():
                    return int(int(parts[1]) // 1024)
    return None


def _run_nvidia_smi(query: str) -> list[list[str]]:
    try:
        proc = subprocess.run(
            [
                "nvidia-smi",
                f"--query-gpu={query}",
                "--format=csv,noheader,nounits",
            ],
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            timeout=10,
            check=False,
        )
    except (OSError, subprocess.SubprocessError):
        return []
    if proc.returncode != 0:
        return []
    rows: list[list[str]] = []
    for line in proc.stdout.splitlines():
        line = line.strip()
        if line:
            rows.append([part.strip() for part in line.split(",")])
    return rows


def _gpu_capabilities() -> tuple[GpuCapability, ...]:
    rows = _run_nvidia_smi("name,memory.total,driver_version,compute_cap")
    result: list[GpuCapability] = []
    for row in rows:
        if len(row) < 4:
            continue
        try:
            vram = int(float(row[1]))
        except ValueError:
            vram = 0
        result.append(
            GpuCapability(
                name=row[0],
                vram_total_mb=vram,
                driver_version=row[2] or None,
                compute_capability=row[3] or None,
            )
        )
    return tuple(result)


def detect_capabilities() -> WorkerCapabilities:
    gpus = _gpu_capabilities()
    node_id = (
        os.getenv("EZSTUDIOLAB_ASSISTANT_NODE_ID")
        or socket.gethostname().strip()
        or "EZStudioLab_assistant-unknown"
    )
    shared_root = os.getenv("EZSTUDIO_SHARED_MODEL_ROOT") or os.getenv(
        "AI_MODELS_ROOT"
    )
    cache = os.getenv("EZSTUDIOLAB_ASSISTANT_MODEL_CACHE")
    return WorkerCapabilities(
        protocol="ezstudio.distributed.v1",
        node_id=node_id,
        hostname=socket.gethostname(),
        os=platform.platform(),
        architecture=platform.machine(),
        cpu_logical_count=int(os.cpu_count() or 1),
        ram_total_mb=_ram_total_mb(),
        gpus=gpus,
        cuda_available=bool(gpus),
        shared_model_root=shared_root,
        local_model_cache=cache,
    )
