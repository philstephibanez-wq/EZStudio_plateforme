from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Generic, TypeVar

from .contracts import ChainPreset, ContractError, PhasePreset


class PresetLoadError(ValueError):
    pass


T = TypeVar("T")


@dataclass(frozen=True)
class LoadedPreset(Generic[T]):
    path: Path
    sha256: str
    raw: dict[str, Any]
    value: T


class PresetLoader:
    def __init__(self, root: str | Path) -> None:
        self.root = Path(root).resolve()

    def _read(self, path: str | Path) -> tuple[Path, bytes, dict[str, Any]]:
        p = Path(path)
        if not p.is_absolute():
            p = self.root / p
        p = p.resolve()
        try:
            p.relative_to(self.root)
        except ValueError as exc:
            raise PresetLoadError(f"preset_outside_root:{p}") from exc
        if not p.is_file():
            raise PresetLoadError(f"preset_missing:{p}")
        raw = p.read_bytes()
        try:
            data = json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            raise PresetLoadError(f"preset_invalid_json:{p}:{exc}") from exc
        if not isinstance(data, dict):
            raise PresetLoadError(f"preset_root_must_be_object:{p}")
        return p, raw, data

    def load_phase(self, path: str | Path) -> LoadedPreset[PhasePreset]:
        p, raw, data = self._read(path)
        if data.get("schema") != "ezstudio.phase-preset.v1":
            raise PresetLoadError(f"unsupported_phase_preset_schema:{data.get('schema')}")
        try:
            value = PhasePreset.from_dict(data)
        except ContractError as exc:
            raise PresetLoadError(str(exc)) from exc
        return LoadedPreset(
            path=p,
            sha256=hashlib.sha256(raw).hexdigest(),
            raw=data,
            value=value,
        )

    def load_chain(self, path: str | Path) -> LoadedPreset[ChainPreset]:
        p, raw, data = self._read(path)
        if data.get("schema") != "ezstudio.chain-preset.v1":
            raise PresetLoadError(f"unsupported_chain_preset_schema:{data.get('schema')}")
        try:
            value = ChainPreset.from_dict(data)
        except ContractError as exc:
            raise PresetLoadError(str(exc)) from exc
        return LoadedPreset(
            path=p,
            sha256=hashlib.sha256(raw).hexdigest(),
            raw=data,
            value=value,
        )
