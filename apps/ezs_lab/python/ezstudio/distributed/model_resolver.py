from __future__ import annotations

import hashlib
import json
import os
import shutil
from pathlib import Path


class ModelResolutionError(RuntimeError):
    pass


def _safe_relative(value: str | Path) -> Path:
    relative = Path(value)
    if relative.is_absolute():
        raise ModelResolutionError("model_path_must_be_relative")
    if any(part in {"..", ""} for part in relative.parts):
        raise ModelResolutionError("model_path_invalid")
    return relative


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(4 * 1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def sha256_path(path: Path) -> str:
    path = path.resolve()
    if path.is_file():
        return _sha256_file(path)
    if not path.is_dir():
        raise ModelResolutionError(f"model_not_found:{path}")
    digest = hashlib.sha256()
    for child in sorted(
        (p for p in path.rglob("*") if p.is_file()),
        key=lambda p: p.relative_to(path).as_posix().lower(),
    ):
        rel = child.relative_to(path).as_posix()
        digest.update(rel.encode("utf-8"))
        digest.update(b"\0")
        digest.update(_sha256_file(child).encode("ascii"))
        digest.update(b"\0")
    return digest.hexdigest()


class ModelResolver:
    def __init__(
        self,
        shared_root: str | Path | None = None,
        cache_root: str | Path | None = None,
    ) -> None:
        shared = (
            str(shared_root)
            if shared_root is not None
            else os.getenv("EZSTUDIO_SHARED_MODEL_ROOT")
            or os.getenv("AI_MODELS_ROOT")
        )
        if not shared:
            raise ModelResolutionError(
                "shared_model_root_required:set EZSTUDIO_SHARED_MODEL_ROOT or AI_MODELS_ROOT"
            )
        self.shared_root = Path(shared)
        cache = (
            str(cache_root)
            if cache_root is not None
            else os.getenv("EZSTUDIOLAB_ASSISTANT_MODEL_CACHE")
        )
        self.cache_root = Path(cache) if cache else None

    def source_path(self, relative_path: str | Path) -> Path:
        relative = _safe_relative(relative_path)
        source = self.shared_root / relative
        if not source.exists():
            raise ModelResolutionError(f"shared_model_missing:{source}")
        return source

    def resolve(self, relative_path: str | Path, *, use_cache: bool = False) -> Path:
        source = self.source_path(relative_path)
        if not use_cache:
            return source
        if self.cache_root is None:
            raise ModelResolutionError(
                "model_cache_required:set EZSTUDIOLAB_ASSISTANT_MODEL_CACHE"
            )
        relative = _safe_relative(relative_path)
        target = self.cache_root / relative
        marker = target.parent / f".{target.name}.EZStudioLab_assistant-cache.json"
        source_digest = sha256_path(source)

        if target.exists() and marker.is_file():
            try:
                metadata = json.loads(marker.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                metadata = {}
            if (
                metadata.get("source_sha256") == source_digest
                and metadata.get("relative_path") == relative.as_posix()
                and sha256_path(target) == source_digest
            ):
                return target

        target.parent.mkdir(parents=True, exist_ok=True)
        temporary = target.parent / f".{target.name}.copying"

        if temporary.exists():
            if temporary.is_dir():
                shutil.rmtree(temporary)
            else:
                temporary.unlink()

        if source.is_dir():
            shutil.copytree(source, temporary)
        else:
            shutil.copy2(source, temporary)

        copied_digest = sha256_path(temporary)
        if copied_digest != source_digest:
            if temporary.is_dir():
                shutil.rmtree(temporary)
            else:
                temporary.unlink(missing_ok=True)
            raise ModelResolutionError(
                f"model_cache_sha256_mismatch:{relative.as_posix()}"
            )

        if target.exists():
            if target.is_dir():
                shutil.rmtree(target)
            else:
                target.unlink()

        temporary.replace(target)
        marker.write_text(
            json.dumps(
                {
                    "schema": "EZStudioLab_assistant.model-cache.v1",
                    "relative_path": relative.as_posix(),
                    "source": str(source),
                    "source_sha256": source_digest,
                },
                ensure_ascii=False,
                indent=2,
            )
            + "\n",
            encoding="utf-8",
        )
        return target
