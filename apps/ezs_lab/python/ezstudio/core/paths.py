from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


def _project_root() -> Path:
    return Path(__file__).resolve().parents[3]


@dataclass(frozen=True, slots=True)
class LabPaths:
    root: Path

    @classmethod
    def from_env(cls):
        project = _project_root()
        raw = os.environ.get("EZSTUDIO_STORAGE_ROOT")
        return cls(Path(raw) if raw else project / "var" / "storage")

    @property
    def tmp_root(self) -> Path:
        raw = os.environ.get("EZSTUDIO_TMP_ROOT")
        return Path(raw) if raw else _project_root() / "var" / "tmp"

    @property
    def artifacts(self):
        return self.root / "artifacts"

    @property
    def runs(self):
        return self.root / "runs"

    @property
    def cache(self):
        return self.tmp_root / "cache"

    @property
    def exports(self):
        return self.root / "exports"

    @property
    def logs(self):
        return self.tmp_root / "logs"

    def ensure(self):
        for path in (
            self.root,
            self.artifacts,
            self.runs,
            self.exports,
            self.tmp_root,
            self.cache,
            self.logs,
        ):
            path.mkdir(parents=True, exist_ok=True)
