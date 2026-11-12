"""Short-lived local UI job for slow genuine PROFILE investigations.

No database and no LAB persistence. Requests/results stay in runtime/state
and are overwritten atomically. Caller does not wait for research.
"""
from __future__ import annotations
import json
import os
from pathlib import Path
import subprocess
import sys
import uuid

ROOT=Path(__file__).resolve().parents[1]
STATE=ROOT/"runtime"/"state"/"profile-direct"
sys.path.insert(0,str(ROOT/"runtime"))
from profile_direct_research import main as research_main

def write(path, data):
    temp=path.with_suffix(".tmp")
    temp.write_text(json.dumps(data,ensure_ascii=False),encoding="utf-8")
    os.replace(temp,path)

def run(job_id):
    request=STATE/(job_id+".request.json")
    result=STATE/(job_id+".result.json")
    write(result,{"ok":True,"state":"running","job_id":job_id})
    try:
        from contextlib import redirect_stdout
        import io
        with request.open(encoding="utf-8") as source:
            original=sys.stdin
            sys.stdin=source
            try:
                output=io.StringIO()
                with redirect_stdout(output):
                    research_main()
            finally:
                sys.stdin=original
        payload=json.loads(output.getvalue())
        payload["state"]="completed"
        payload["job_id"]=job_id
        write(result,payload)
    except Exception as exc:
        write(result,{"ok":False,"state":"error","job_id":job_id,
                      "error":type(exc).__name__,"reason":str(exc)[:200]})

def launch():
    data=json.load(sys.stdin)
    title=str(data.get("title") or "").strip()
    artist=str(data.get("artist") or "").strip()
    if not title or not artist or len(title)>180 or len(artist)>180:
        raise ValueError("identity_invalid")
    STATE.mkdir(parents=True,exist_ok=True)
    job_id=uuid.uuid4().hex
    write(STATE/(job_id+".request.json"),{"title":title,"artist":artist})
    write(STATE/(job_id+".result.json"),{"ok":True,"state":"queued","job_id":job_id})
    flags=(getattr(subprocess,"CREATE_NEW_PROCESS_GROUP",0) |
           getattr(subprocess,"DETACHED_PROCESS",0) |
           getattr(subprocess,"CREATE_NO_WINDOW",0))
    proc=subprocess.Popen([sys.executable,str(Path(__file__).resolve()),"--run",job_id],
        cwd=str(ROOT),stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,creationflags=flags,close_fds=True)
    print(json.dumps({"ok":True,"state":"queued","job_id":job_id,"pid":proc.pid}))

if __name__=="__main__":
    try:
        if len(sys.argv)==3 and sys.argv[1]=="--run":
            job_id=sys.argv[2]
            if len(job_id)==32 and all(c in "0123456789abcdef" for c in job_id):
                run(job_id)
        else:
            launch()
    except Exception as exc:
        print(json.dumps({"ok":False,"state":"error",
                          "error":type(exc).__name__,"reason":str(exc)[:200]}))
        sys.exit(1)
