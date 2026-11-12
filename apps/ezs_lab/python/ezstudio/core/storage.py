from __future__ import annotations
import json, mimetypes, os, shutil, tempfile
from pathlib import Path
from typing import Any
from .contracts import ArtifactRecord, RunRecord, LAB_ITEMS
from .hashing import sha256_file
from .paths import LabPaths

def _safe_component(value:str)->str:
    cleaned="".join(c if c.isalnum() or c in "-_ ." else "_" for c in str(value)).replace(" ","_").strip("._")
    if not cleaned: raise ValueError("Empty/unsafe path component")
    return cleaned

class ArtifactStore:
    def __init__(self, paths:LabPaths): self.paths=paths; self.paths.ensure()
    def import_file(self, source:str|Path, *, kind:str, metadata:dict[str,Any]|None=None, extension:str|None=None)->ArtifactRecord:
        src=Path(source)
        if not src.is_file(): raise FileNotFoundError(src)
        digest=sha256_file(src); shard=digest[:2]; suffix=extension if extension is not None else src.suffix
        if suffix and not suffix.startswith("."): suffix="."+suffix
        target_dir=self.paths.artifacts/shard/digest; target_dir.mkdir(parents=True,exist_ok=True); target=target_dir/f"payload{suffix or ''}"
        if not target.exists():
            fd,tmp_name=tempfile.mkstemp(prefix=".incoming-",dir=target_dir); os.close(fd); tmp=Path(tmp_name)
            try:
                shutil.copyfile(src,tmp)
                if sha256_file(tmp)!=digest: raise RuntimeError("Artifact checksum changed during copy")
                os.replace(tmp,target)
            finally:
                tmp.unlink(missing_ok=True)
        if sha256_file(target)!=digest: raise RuntimeError(f"Artifact store corruption detected: {target}")
        media_type,_=mimetypes.guess_type(str(target))
        record=ArtifactRecord(artifact_id=f"sha256:{digest}",kind=str(kind),sha256=digest,bytes=target.stat().st_size,path=str(target),media_type=media_type,metadata=dict(metadata or {}))
        meta_path=target_dir/"artifact.json"
        if not meta_path.exists(): meta_path.write_text(json.dumps(record.to_dict(),ensure_ascii=False,indent=2),encoding="utf-8")
        return record
    def resolve(self, artifact_id:str)->Path:
        prefix="sha256:"
        if not artifact_id.startswith(prefix): raise ValueError(f"Unsupported artifact id: {artifact_id}")
        digest=artifact_id[len(prefix):]
        if len(digest)!=64 or any(c not in "0123456789abcdef" for c in digest.lower()): raise ValueError(f"Invalid sha256 artifact id: {artifact_id}")
        root=self.paths.artifacts/digest[:2]/digest; candidates=[p for p in root.glob("payload*") if p.is_file()]
        if len(candidates)!=1: raise FileNotFoundError(f"Artifact payload not found/ambiguous: {artifact_id}")
        path=candidates[0]
        if sha256_file(path)!=digest: raise RuntimeError(f"Artifact checksum mismatch: {artifact_id}")
        return path

class RunWorkspace:
    SUBDIRS=("inputs","outputs","features","diagnostics","logs","raw")
    def __init__(self, paths:LabPaths, item:str, run_id:str):
        if item not in LAB_ITEMS: raise ValueError(f"Unknown item: {item}")
        self.paths=paths; self.item=item; self.run_id=_safe_component(run_id); self.root=paths.runs/item/self.run_id
    def ensure(self):
        self.root.mkdir(parents=True,exist_ok=True)
        for n in self.SUBDIRS: (self.root/n).mkdir(parents=True,exist_ok=True)
    def path(self,section:str)->Path:
        if section not in self.SUBDIRS: raise ValueError(f"Unknown workspace section: {section}")
        self.ensure(); return self.root/section
    @property
    def manifest_path(self): self.ensure(); return self.root/"manifest.json"
    def write_manifest(self,run:RunRecord)->Path:
        if run.item!=self.item or run.run_id!=self.run_id: raise ValueError("Run/workspace mismatch")
        self.ensure()
        tmp=self.root/".manifest.json.tmp"; tmp.write_text(json.dumps(run.to_dict(),ensure_ascii=False,indent=2),encoding="utf-8"); os.replace(tmp,self.manifest_path); return self.manifest_path
    def read_manifest(self)->dict[str,Any]: return json.loads(self.manifest_path.read_text(encoding="utf-8"))
