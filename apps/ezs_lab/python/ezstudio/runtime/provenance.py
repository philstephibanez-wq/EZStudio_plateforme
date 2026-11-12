from __future__ import annotations

import hashlib
import json
from dataclasses import asdict
from typing import Any

from .execution_plan import ExecutionPlan


def canonical_json(value: Any) -> str:
    return json.dumps(
        value,
        ensure_ascii=False,
        separators=(",", ":"),
        sort_keys=True,
    )


def fingerprint(value: Any) -> str:
    return hashlib.sha256(canonical_json(value).encode("utf-8")).hexdigest()


def plan_provenance(
    plan: ExecutionPlan,
    *,
    preset_sha256: str,
    runtime_version: str,
) -> dict[str, Any]:
    modules = []
    for row in plan.modules:
        module_dict = asdict(row.module)
        modules.append(
            {
                "position": row.position,
                "id": row.module.id,
                "revision": row.module.revision,
                "adapter": row.module.adapter,
                "engine": row.module.engine,
                "model": row.module.model,
                "config_fingerprint": fingerprint(module_dict),
            }
        )
    value = {
        "schema": "ezstudio.execution-provenance.v1",
        "phase": plan.phase,
        "phase_preset_id": plan.phase_id,
        "phase_preset_revision": plan.phase_revision,
        "phase_preset_sha256": preset_sha256,
        "runtime_version": runtime_version,
        "modules": modules,
    }
    value["execution_fingerprint"] = fingerprint(value)
    return value
