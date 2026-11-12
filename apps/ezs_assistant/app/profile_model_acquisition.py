"""Conservative MAIN-only GGUF acquisition; no secondary cache or server changes.

Run without --download for read-only preflight. Explicit --download writes
only to the existing MAIN share; demands an upstream SHA256 declaration.
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
from pathlib import Path, PureWindowsPath
import re
import shutil
import urllib.request

REPO="unsloth/Qwen3-1.7B-GGUF"
FILE="Qwen3-1.7B-Q4_K_M.gguf"
ROOT=r"\\EZSTUDIO-MAIN\EZModels"
API="https://huggingface.co/api/models/"+REPO+"/tree/main"
SOURCE="https://huggingface.co/"+REPO+"/resolve/main/"+FILE
# Official Hugging Face file page publishes two different hashes: Xet CAS and file content SHA256.
# These values are pinned for this exact file and byte size; any upstream change fails closed.
PINNED_SIZE=1107409472
PINNED_XET_HASH="de942b0819216caa3bfe487180dd1bb37398fa1c98cb42bb0bbac7ab7d6e8a12"
PINNED_CONTENT_SHA256="b139949c5bd74937ad8ed8c8cf3d9ffb1e99c866c823204dc42c0d91fa181897"
INTEGRITY_SOURCE="https://huggingface.co/unsloth/Qwen3-1.7B-GGUF/blob/4102b64b54bf3f0ddb9408d83f42d5091e0a7b64/Qwen3-1.7B-Q4_K_M.gguf"

def resolve_integrity_head(url=SOURCE):
    """Xet files often omit lfs.sha256 in the model tree; resolve HEAD exposes the actual content ETag."""
    request=urllib.request.Request(url,method="HEAD",headers={"User-Agent":"EZStudioLab-assistant-model-preflight"})
    with urllib.request.urlopen(request,timeout=30) as response:
        headers=response.headers
        sha=str(headers.get("X-Linked-Etag") or headers.get("ETag") or "").strip('"').lower()
        size=int(headers.get("X-Linked-Size") or headers.get("Content-Length") or 0)
    if not re.fullmatch(r"[0-9a-f]{64}",sha) or size<=500_000_000:
        raise RuntimeError("UPSTREAM_XET_SHA256_UNAVAILABLE")
    return size,sha

def metadata(fetch=None,integrity=None):
    live=fetch is None
    if fetch is None:
        def fetch(url):
            with urllib.request.urlopen(url,timeout=25) as response:
                return json.load(response)
    entries=fetch(API)
    matches=[x for x in entries if x.get("path")==FILE]
    if len(matches)!=1:raise RuntimeError("MODEL_FILE_NOT_FOUND")
    entry=matches[0]
    size=int(entry.get("size") or 0)
    sha=str((entry.get("lfs") or {}).get("sha256") or "").lower()
    if not re.fullmatch(r"[0-9a-f]{64}",sha):
        head_size,sha=(integrity or resolve_integrity_head)()
        if head_size!=size:raise RuntimeError("UPSTREAM_SIZE_MISMATCH")
    if not (size>500_000_000 and re.fullmatch(r"[0-9a-f]{64}",sha)):
        raise RuntimeError("UPSTREAM_SIZE_OR_SHA256_UNAVAILABLE")
    # Xet CAS hash is distinct from the reconstructed file's SHA256.
    # Pin both hashes and the size for this exact production artifact.
    if live and size!=PINNED_SIZE:
        raise RuntimeError("PINNED_CONTENT_SIZE_CHANGED_REVIEW_REQUIRED")
    if size==PINNED_SIZE:
        if sha!=PINNED_XET_HASH:
            raise RuntimeError("PINNED_XET_HASH_CHANGED_REVIEW_REQUIRED")
        content_sha=PINNED_CONTENT_SHA256
    else:
        # Synthetic metadata injected by existing offline unit tests.
        content_sha=sha
    return {"repository":REPO,"file":FILE,"size":size,"sha256":content_sha,
            "xet_hash":sha,"source":SOURCE,"integrity_source":INTEGRITY_SOURCE}

def dest(root):
    # On Windows a UNC share root is an anchor: Path.name is empty.
    canonical=PureWindowsPath(ROOT)
    candidate=PureWindowsPath(str(root))
    if str(candidate).rstrip("\\\\/").casefold()!=str(canonical).rstrip("\\\\/").casefold():
        raise ValueError("MAIN_MODEL_SHARE_REQUIRED")
    return Path(root)/"assistant"/"bench"/"qwen3-1.7b-q4_k_m"/FILE

def sha256(path):
    h=hashlib.sha256()
    with open(path,"rb") as f:
        for chunk in iter(lambda:f.read(4*1024*1024),b""):h.update(chunk)
    return h.hexdigest()

def preflight(root=ROOT,fetch=None):
    info=metadata(fetch)
    target=dest(root)
    if not target.parents[2].is_dir():raise RuntimeError("MAIN_SHARE_UNAVAILABLE")
    usage=shutil.disk_usage(target.parents[2])
    status="exists" if target.exists() else "missing"
    if status=="exists":
        status="verified" if target.stat().st_size==info["size"] and sha256(target)==info["sha256"] else "invalid_existing_file"
    if usage.free<info["size"]*2 and status=="missing":raise RuntimeError("INSUFFICIENT_MAIN_FREE_SPACE")
    return info,target,status

def download(info,target,*,opener=None,chunk_size=8*1024*1024,max_attempts=3):
    """Resumable exact HTTP ranges; fail closed on wrong range/size/hash.

    Partial bytes stay on MAIN after a network failure. No off-host download cache.
    """
    import time
    opener=opener or urllib.request.urlopen
    target.parent.mkdir(parents=True,exist_ok=True)
    part=target.with_name(target.name+".part")
    lock=target.with_name(target.name+".download.lock")
    if target.exists():raise RuntimeError("REFUSE_OVERWRITE_EXISTING")
    if not 0<chunk_size<=64*1024*1024 or not 1<=max_attempts<=8:
        raise ValueError("INVALID_DOWNLOAD_BUDGET")
    with open(lock,"xb"):
        pass
    try:
        size=int(info["size"])
        if part.exists() and part.stat().st_size>size:
            raise RuntimeError("PARTIAL_FILE_EXCEEDS_EXPECTED_SIZE")
        if not part.exists():
            with open(part,"xb"):
                pass
        while True:
            position=part.stat().st_size
            if position>=size:break
            end=min(position+chunk_size,size)-1
            failure=None
            for attempt in range(1,max_attempts+1):
                position=part.stat().st_size
                if position>end:break
                req=urllib.request.Request(info["source"],headers={
                    "User-Agent":"EZStudioLab-assistant-model-preflight",
                    "Range":f"bytes={position}-{end}",
                    "Accept-Encoding":"identity"})
                try:
                    with opener(req,timeout=35) as response:
                        status=response.getcode()
                        header=str(response.headers.get("Content-Range") or "")
                        expected=f"bytes {position}-{end}/{size}"
                        if status!=206 or header.strip()!=expected:
                            raise RuntimeError("UPSTREAM_RANGE_UNSUPPORTED_OR_MISMATCH:"+str(status)+":"+header[:100])
                        remaining=end-position+1
                        with open(part,"ab") as output:
                            while remaining:
                                data=response.read(min(256*1024,remaining))
                                if not data:raise OSError("PREMATURE_EOF")
                                output.write(data)
                                remaining-=len(data)
                    failure=None
                    break
                except RuntimeError:
                    raise
                except (OSError,TimeoutError) as exc:
                    failure=exc
                    print(f"RETRY download position={part.stat().st_size} attempt={attempt}/{max_attempts} error={type(exc).__name__}",flush=True)
                    if attempt<max_attempts:time.sleep(min(attempt,3))
            if failure is not None:
                raise RuntimeError("DOWNLOAD_INTERRUPTED_RESUMABLE_AT:"+str(part.stat().st_size)) from failure
            progress=part.stat().st_size
            print(f"DOWNLOAD_MAIN_PROGRESS {progress}/{size} ({progress*100/size:.1f}%)",flush=True)
        actual=sha256(part)
        if part.stat().st_size!=size:
            raise RuntimeError("DOWNLOAD_SIZE_MISMATCH:"+str(part.stat().st_size)+"/"+str(size))
        if actual!=info["sha256"]:
            raise RuntimeError("DOWNLOAD_SHA256_MISMATCH:actual="+actual+" expected="+info["sha256"]+" ; PARTIAL_RETAINED")
        os.replace(part,target)
    finally:
        lock.unlink(missing_ok=True)

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--download",action="store_true",help="explicitly download the candidate to MAIN only")
    args=p.parse_args()
    info,target,status=preflight()
    print(json.dumps({"candidate":info["repository"],"filename":info["file"],
      "size_bytes":info["size"],"expected_sha256":info["sha256"],
      "target_on_main":str(target),"status":status,"production_unchanged":True},
      ensure_ascii=False,indent=2))
    if status=="verified":return
    if status=="invalid_existing_file":raise RuntimeError("EXISTING_MODEL_INVALID_NO_OVERWRITE")
    if args.download:
        download(info,target)
        print("MODEL_ON_MAIN_SHA256_VERIFIED")
    else:print("PREFLIGHT_ONLY_NO_DOWNLOAD")
if __name__=="__main__":main()
