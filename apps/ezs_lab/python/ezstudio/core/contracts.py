from __future__ import annotations
from dataclasses import asdict, dataclass, field
from datetime import datetime, timezone
from typing import Any, Literal
from .hashing import sha256_json

CORE_SCHEMA_VERSION=1
LAB_ITEMS=("import","stems","chords","no_chord","lyrics")
RunStatus=Literal["queued","running","done","error","cancelled"]

def utc_now()->str: return datetime.now(timezone.utc).isoformat()
def _validate_item(item:str)->str:
    value=str(item).strip().lower()
    if value not in LAB_ITEMS: raise ValueError(f"Unknown EZStudio_lab item: {item!r}")
    return value

@dataclass(frozen=True, slots=True)
class ExperimentRecord:
    experiment_id:str; item:str; name:str; protocol_version:str; hypothesis:str=""; references:tuple[str,...]=()
    def __post_init__(self): object.__setattr__(self,"item",_validate_item(self.item))
    def to_dict(self)->dict[str,Any]: return asdict(self)

@dataclass(frozen=True, slots=True)
class ArtifactRecord:
    artifact_id:str; kind:str; sha256:str; bytes:int; path:str; media_type:str|None=None; created_at:str=field(default_factory=utc_now); metadata:dict[str,Any]=field(default_factory=dict)
    def to_dict(self)->dict[str,Any]: return asdict(self)

@dataclass(frozen=True, slots=True)
class ObservationRecord:
    namespace:str; name:str; value:Any; unit:str|None=None; time_s:float|None=None; beat:int|None=None; metadata:dict[str,Any]=field(default_factory=dict)
    def to_dict(self)->dict[str,Any]: return asdict(self)

@dataclass(frozen=True, slots=True)
class EventRecord:
    event_type:str; time_s:float|None=None; beat:int|None=None; severity:str="info"; payload:dict[str,Any]=field(default_factory=dict)
    def to_dict(self)->dict[str,Any]: return asdict(self)

@dataclass(slots=True)
class RunRecord:
    run_id:str; item:str; engine_name:str; engine_version:str; config:dict[str,Any]; status:RunStatus="queued"; experiment_id:str|None=None; parent_run_ids:list[str]=field(default_factory=list); input_artifact_ids:list[str]=field(default_factory=list); output_artifact_ids:list[str]=field(default_factory=list); started_at:str|None=None; finished_at:str|None=None; environment:dict[str,Any]=field(default_factory=dict); metrics:dict[str,Any]=field(default_factory=dict); diagnostic_artifact_ids:list[str]=field(default_factory=list); error:str|None=None
    def __post_init__(self): self.item=_validate_item(self.item)
    @property
    def config_hash(self)->str: return sha256_json(self.config)
    def mark_running(self): self.status="running"; self.started_at=utc_now(); self.finished_at=None; self.error=None
    def mark_done(self): self.status="done"; self.finished_at=utc_now(); self.error=None
    def mark_error(self,error:str): self.status="error"; self.finished_at=utc_now(); self.error=str(error)
    def to_dict(self)->dict[str,Any]:
        payload=asdict(self); payload["schema_version"]=CORE_SCHEMA_VERSION; payload["config_hash"]=self.config_hash; return payload
