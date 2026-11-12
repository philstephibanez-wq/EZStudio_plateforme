from __future__ import annotations

from dataclasses import dataclass
from typing import Any

from .capabilities import WorkerCapabilities
from .model_resolver import ModelResolutionError, ModelResolver


@dataclass(frozen=True)
class JobRequirements:
    requires_cuda: bool = False
    min_vram_mb: int = 0
    min_ram_mb: int = 0
    model_relative_path: str | None = None


def compatibility_report(
    capabilities: WorkerCapabilities,
    requirements: JobRequirements,
    *,
    model_resolver: ModelResolver | None = None,
) -> dict[str, Any]:
    reasons: list[str] = []
    if requirements.requires_cuda and not capabilities.cuda_available:
        reasons.append("cuda_required")
    if requirements.min_vram_mb > capabilities.max_vram_mb:
        reasons.append(
            f"insufficient_vram:required={requirements.min_vram_mb}:available={capabilities.max_vram_mb}"
        )
    if (
        requirements.min_ram_mb > 0
        and capabilities.ram_total_mb is not None
        and requirements.min_ram_mb > capabilities.ram_total_mb
    ):
        reasons.append(
            f"insufficient_ram:required={requirements.min_ram_mb}:available={capabilities.ram_total_mb}"
        )

    model_path: str | None = None
    if requirements.model_relative_path:
        if model_resolver is None:
            reasons.append("model_resolver_required")
        else:
            try:
                model_path = str(model_resolver.source_path(requirements.model_relative_path))
            except ModelResolutionError as exc:
                reasons.append(str(exc))

    return {
        "schema": "ezstudio.worker-compatibility.v1",
        "node_id": capabilities.node_id,
        "compatible": not reasons,
        "reasons": reasons,
        "model_path": model_path,
    }
