from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path

from .contracts import ModulePreset


class ModelResolutionError(RuntimeError):
    pass


@dataclass(frozen=True)
class ResolvedModel:
    model_id: str
    path: Path


class ModelResolver:
    """Resolve declared model identifiers without silent fallback."""

    def __init__(self, roots: dict[str, str | Path] | None = None) -> None:
        self.roots = {
            str(key): Path(value).resolve()
            for key, value in (roots or {}).items()
        }

    def resolve(self, module: ModulePreset) -> ResolvedModel | None:
        if not module.model:
            return None

        model_path = module.settings.get("model_path")
        if isinstance(model_path, str) and model_path.strip():
            p = Path(os.path.expandvars(model_path.strip()))
            if not p.is_absolute():
                root_key = str(module.settings.get("model_root") or "")
                root = self.roots.get(root_key)
                if root is None:
                    raise ModelResolutionError(
                        f"model_root_not_registered:{module.id}:{root_key}"
                    )
                p = root / p
            p = p.resolve()
            if not p.exists():
                raise ModelResolutionError(
                    f"model_path_missing:{module.id}:{p}"
                )
            return ResolvedModel(model_id=module.model, path=p)

        return ResolvedModel(model_id=module.model, path=Path(module.model))
