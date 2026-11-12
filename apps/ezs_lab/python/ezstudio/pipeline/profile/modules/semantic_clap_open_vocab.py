from __future__ import annotations

import sys
from pathlib import Path

# The PROFILE runner is executed as a script; make the common runtime package
# importable without depending on the caller's PYTHONPATH.
_PYTHON_ROOT = Path(__file__).resolve().parents[4]
if str(_PYTHON_ROOT) not in sys.path:
    sys.path.insert(0, str(_PYTHON_ROOT))

from module import ModuleContext
from ezstudio.runtime.adapters.clap_zero_shot import run_clap_zero_shot
from module import ModuleConfig


def run(context: ModuleContext, config: ModuleConfig) -> dict:
    module_config = config.resolved_module_config
    if module_config is None:
        raise RuntimeError("profile_clap_resolved_module_config_missing")
    result, warnings = run_clap_zero_shot(
        context.source,
        context.model_root,
        module_config,
    )
    if not bool(result.get("available")):
        raise RuntimeError(
            ";".join(warnings) if warnings else "clap_unavailable"
        )

    families = (
        (module_config.data.get("taxonomy") or {}).get("families") or {}
    )
    normalized = {
        str(family): list(result.get(str(family)) or [])
        for family in families
    }

    return {
        "raw": {
            "chunks": result.get("chunks"),
            "chunk_seconds": result.get("chunk_seconds"),
            "sample_rate": result.get("sample_rate"),
            "score_semantics": result.get("score_semantics"),
            "temporal": result.get("temporal") or {},
            "decisions": result.get("decisions") or {},
            "module_config": result.get("module_config") or module_config.provenance(),
        },
        "normalized": normalized,
        "warnings": warnings,
        "model": {
            "id": str(
                (module_config.data.get("engine") or {}).get("model") or ""
            ),
            "path": str(
                context.model_root
                / str(
                    (module_config.data.get("engine") or {})
                    .get("model", "")
                ).rsplit("/", 1)[-1]
            ),
        },
        "device": str(
            (module_config.data.get("engine") or {}).get("device")
            or "cuda:0"
        ),
        "engine": {
            "version": result.get("transformers_version"),
            "adapter": module_config.engine_adapter,
            "module_config_revision": module_config.revision,
            "module_config_sha256": module_config.sha256,
        },
    }
