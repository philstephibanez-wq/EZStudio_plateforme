from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any


class ModuleConfigError(ValueError):
    pass


@dataclass(frozen=True)
class ModuleConfigDocument:
    path: Path
    data: dict[str, Any]
    sha256: str

    @property
    def module_id(self) -> str:
        return str(self.data["module"]["id"])


    @property
    def revision(self) -> str:
        return str(self.data["module"]["revision"])

    @property
    def phase(self) -> str:
        return str(self.data["module"]["phase"])


    @property
    def engine_adapter(self) -> str:
        return str(self.data["engine"]["adapter"])

    def provenance(self) -> dict[str, Any]:
        return {
            "schema": self.data["schema"],
            "module_id": self.module_id,
            "module_revision": self.revision,
            "phase": self.phase,
            "engine_adapter": self.engine_adapter,
            "config_path": self.path.as_posix(),
            "config_sha256": self.sha256,
        }


def _require_mapping(value: Any, name: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ModuleConfigError(f"{name}_must_be_object")
    return value


def _require_string(mapping: dict[str, Any], key: str, prefix: str) -> str:
    value = mapping.get(key)
    if not isinstance(value, str) or not value.strip():
        raise ModuleConfigError(f"{prefix}.{key}_must_be_non_empty_string")
    return value.strip()


def validate_module_config(data: dict[str, Any]) -> None:
    schema = data.get("schema")
    if schema != "ezstudio.module-config.v1":
        raise ModuleConfigError("unsupported_module_config_schema")

    module = _require_mapping(data.get("module"), "module")
    _require_string(module, "id", "module")
    _require_string(module, "revision", "module")
    phase = _require_string(module, "phase", "module")
    if phase not in {"profile", "stems", "chords", "lyrics"}:
        raise ModuleConfigError("module.phase_invalid")

    engine = _require_mapping(data.get("engine"), "engine")
    _require_string(engine, "adapter", "engine")

    if "parameters" in data and not isinstance(data["parameters"], dict):
        raise ModuleConfigError("parameters_must_be_object")
    if "decision" in data and not isinstance(data["decision"], dict):
        raise ModuleConfigError("decision_must_be_object")
    if "output" in data and not isinstance(data["output"], dict):
        raise ModuleConfigError("output_must_be_object")

    taxonomy = data.get("taxonomy")
    if taxonomy is not None:
        taxonomy = _require_mapping(taxonomy, "taxonomy")
        families = taxonomy.get("families")
        if families is not None:
            families = _require_mapping(families, "taxonomy.families")
            for family, labels in families.items():
                if not isinstance(family, str) or not family.strip():
                    raise ModuleConfigError("taxonomy_family_invalid")
                if not isinstance(labels, list) or not labels:
                    raise ModuleConfigError(f"taxonomy.{family}_must_be_non_empty_list")
                if any(not isinstance(x, str) or not x.strip() for x in labels):
                    raise ModuleConfigError(f"taxonomy.{family}_contains_invalid_label")


def load_module_config(path: str | Path) -> ModuleConfigDocument:
    p = Path(path)
    if not p.is_file():
        raise ModuleConfigError(f"module_config_missing:{p}")
    raw = p.read_bytes()
    try:
        data = json.loads(raw.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise ModuleConfigError(f"module_config_invalid_json:{exc}") from exc
    if not isinstance(data, dict):
        raise ModuleConfigError("module_config_root_must_be_object")
    validate_module_config(data)
    return ModuleConfigDocument(
        path=p,
        data=data,
        sha256=hashlib.sha256(raw).hexdigest(),
    )


