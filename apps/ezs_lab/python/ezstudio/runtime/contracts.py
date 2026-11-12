from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any


PHASES = ("profile", "stems", "chords", "lyrics")


class ContractError(ValueError):
    pass


def _required_text(value: Any, name: str) -> str:
    if not isinstance(value, str) or not value.strip():
        raise ContractError(f"{name}_required")
    return value.strip()


@dataclass(frozen=True)
class ModulePreset:
    id: str
    revision: str
    phase: str
    adapter: str
    engine: str
    display_name: str
    family: str
    model: str | None = None
    model_path: Any = None
    engine_version: str | None = None
    device: str | None = None
    order: int = 0
    inputs: dict[str, Any] = field(default_factory=dict)
    parameters: dict[str, Any] = field(default_factory=dict)
    thresholds: dict[str, Any] = field(default_factory=dict)
    weights: dict[str, Any] = field(default_factory=dict)
    settings: dict[str, Any] = field(default_factory=dict)
    implementation: dict[str, Any] = field(default_factory=dict)
    decision: dict[str, Any] = field(default_factory=dict)
    outputs: dict[str, Any] = field(default_factory=dict)
    diagnostics: dict[str, Any] = field(default_factory=dict)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> "ModulePreset":
        if not isinstance(data, dict):
            raise ContractError("module_preset_must_be_object")
        phase = _required_text(data.get("phase"), "module.phase").lower()
        if phase not in PHASES:
            raise ContractError(f"module.phase_invalid:{phase}")
        order = data.get("order", 0)
        if not isinstance(order, int):
            raise ContractError("module.order_must_be_integer")
        return cls(
            id=_required_text(data.get("id"), "module.id"),
            revision=_required_text(data.get("revision"), "module.revision"),
            phase=phase,
            adapter=_required_text(data.get("adapter"), "module.adapter"),
            engine=_required_text(data.get("engine"), "module.engine"),
            display_name=_required_text(
                data.get("display_name") or data.get("id"),
                "module.display_name",
            ),
            family=_required_text(
                data.get("family")
                or str(data.get("id") or "").split(".", 1)[0],
                "module.family",
            ),
            model=(str(data["model"]).strip() if data.get("model") else None),
            model_path=data.get("model_path"),
            engine_version=(str(data["engine_version"]).strip() if data.get("engine_version") else None),
            device=(str(data["device"]).strip() if data.get("device") else None),
            order=order,
            inputs=dict(data.get("inputs") or {}),
            parameters=dict(data.get("parameters") or {}),
            thresholds=dict(data.get("thresholds") or {}),
            weights=dict(data.get("weights") or {}),
            settings=dict(data.get("settings") or {}),
            implementation=dict(data.get("implementation") or {}),
            decision=dict(data.get("decision") or {}),
            outputs=dict(data.get("outputs") or {}),
            diagnostics=dict(data.get("diagnostics") or {}),
        )


@dataclass(frozen=True)
class PhasePreset:
    id: str
    revision: str
    phase: str
    modules: tuple[ModulePreset, ...]
    settings: dict[str, Any] = field(default_factory=dict)
    outputs: dict[str, Any] = field(default_factory=dict)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> "PhasePreset":
        if not isinstance(data, dict):
            raise ContractError("phase_preset_must_be_object")
        phase = _required_text(data.get("phase"), "phase.phase").lower()
        if phase not in PHASES:
            raise ContractError(f"phase.phase_invalid:{phase}")
        raw_modules = data.get("modules")
        if not isinstance(raw_modules, list) or not raw_modules:
            raise ContractError("phase.modules_required")
        modules = tuple(ModulePreset.from_dict(row) for row in raw_modules)
        ids = [m.id for m in modules]
        if len(ids) != len(set(ids)):
            raise ContractError("phase.module_ids_must_be_unique")
        if any(m.phase != phase for m in modules):
            raise ContractError("phase.module_phase_mismatch")
        return cls(
            id=_required_text(data.get("id"), "phase.id"),
            revision=_required_text(data.get("revision"), "phase.revision"),
            phase=phase,
            modules=tuple(sorted(modules, key=lambda m: (m.order, m.id))),
            settings=dict(data.get("settings") or {}),
            outputs=dict(data.get("outputs") or {}),
        )


@dataclass(frozen=True)
class ChainPhaseRef:
    phase: str
    preset: str

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> "ChainPhaseRef":
        phase = _required_text(data.get("phase"), "chain.phase.phase").lower()
        if phase not in PHASES:
            raise ContractError(f"chain.phase_invalid:{phase}")
        return cls(
            phase=phase,
            preset=_required_text(data.get("preset"), "chain.phase.preset"),
        )


@dataclass(frozen=True)
class ChainPreset:
    id: str
    revision: str
    phases: tuple[ChainPhaseRef, ...]
    runtime_min: str | None = None
    publication_gates: dict[str, Any] = field(default_factory=dict)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> "ChainPreset":
        if not isinstance(data, dict):
            raise ContractError("chain_preset_must_be_object")
        raw = data.get("phases")
        if not isinstance(raw, list) or not raw:
            raise ContractError("chain.phases_required")
        phases = tuple(ChainPhaseRef.from_dict(row) for row in raw)
        names = [p.phase for p in phases]
        if len(names) != len(set(names)):
            raise ContractError("chain.phases_must_be_unique")
        return cls(
            id=_required_text(data.get("id"), "chain.id"),
            revision=_required_text(data.get("revision"), "chain.revision"),
            phases=phases,
            runtime_min=(str(data["runtime_min"]).strip() if data.get("runtime_min") else None),
            publication_gates=dict(data.get("publication_gates") or {}),
        )
