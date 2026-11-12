from __future__ import annotations
import json, os
from pathlib import Path
from typing import Any
from .contracts import ArtifactRecord, EventRecord, ObservationRecord, RunRecord
from .paths import LabPaths
from .storage import RunWorkspace

class JsonlRunRegistry:
    def __init__(self, paths:LabPaths): self.paths=paths; self.paths.ensure()
    def persist_run(self,run:RunRecord)->Path: return RunWorkspace(self.paths,run.item,run.run_id).write_manifest(run)
    def append_observation(self,run:RunRecord,observation:ObservationRecord)->Path: return self._append(run,"observations.jsonl",observation.to_dict())
    def append_event(self,run:RunRecord,event:EventRecord)->Path: return self._append(run,"events.jsonl",event.to_dict())
    def append_artifact(self,run:RunRecord,artifact:ArtifactRecord)->Path: return self._append(run,"artifacts.jsonl",artifact.to_dict())
    def _append(self,run:RunRecord,name:str,payload:dict[str,Any])->Path:
        path=RunWorkspace(self.paths,run.item,run.run_id).path("raw")/name
        with path.open("a",encoding="utf-8",newline="\n") as fh:
            fh.write(json.dumps(payload,ensure_ascii=False,separators=(",",":"))+"\n"); fh.flush(); os.fsync(fh.fileno())
        return path
