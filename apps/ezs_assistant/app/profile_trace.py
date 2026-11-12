"""PROFILE debug trace: per-run, high resolution, secret-redacted, opt-in.

Enabled by default for debug; disable with EZS_PROFILE_TRACE=0; configure EZS_PROFILE_TRACE_ROOT and
EZS_PROFILE_TRACE_RETENTION_DAYS. Never emit auth credentials or tokens.
"""
from __future__ import annotations
import contextlib
import contextvars
import datetime as dt
import json
import os
from pathlib import Path
import re
import threading
import time
import traceback
import uuid

_ACTIVE = contextvars.ContextVar('ezs_profile_trace', default=None)
_SECRET_KEYS = re.compile(r'(token|secret|password|passwd|authorization|api[_-]?key|cookie|credential|session|private[_-]?key)',re.I)
_SECRET_IN_TEXT = [
 re.compile(r'(?i)(Bearer\s+)\S+'),
 re.compile(r'(?i)(X-EZScore-Analysis-Token\s*[=:]\s*)\S+'),
 re.compile(r'(?i)([?&](?:token|api_key|access_key|password)=)[^&\s]+'),
]

def clean(value, depth=0):
    if depth > 40: return '<depth_limit>'
    if isinstance(value, dict):
        return {str(k): ('<REDACTED>' if _SECRET_KEYS.search(str(k)) else clean(v,depth+1)) for k,v in value.items()}
    if isinstance(value,(list,tuple)): return [clean(v,depth+1) for v in value]
    if isinstance(value,str):
        for pattern in _SECRET_IN_TEXT: value=pattern.sub(lambda m:m.group(1)+'<REDACTED>',value)
        return value
    if value is None or isinstance(value,(bool,int,float)): return value
    return repr(value)

def enabled():
    if os.getenv('EZS_PROFILE_TRACE','1').strip().lower() not in ('1','true','yes','on'):
        return False
    switch=Path(os.getenv('EZS_RUN_REPORTS_SWITCH',r'H:\EZStudio_Run_Reports_state\autopush.flag'))
    try: return switch.read_text(encoding='ascii').strip()=='1'
    except FileNotFoundError: return True

class Trace:
    def __init__(self,run_id):
        self.run_id=int(run_id)
        self.trace_id=uuid.uuid4().hex
        self.start_ns=time.perf_counter_ns()
        self.root=Path(os.getenv('EZS_PROFILE_TRACE_ROOT') or r'H:\EZStudio_Run_Reports_state\assistant-traces')
        self.folder=self.root/('run-'+str(run_id)+'-'+self.trace_id[:12])
        self.lock=threading.Lock()
        self.folder.mkdir(parents=True,exist_ok=False)
        self.file=self.folder/'timeline.jsonl'
        self.seq=0
        self.event('trace.begin',{'trace_id':self.trace_id,'run_id':self.run_id,'pid':os.getpid()})
    def event(self,name,data=None,*,start_ns=None):
        now=time.perf_counter_ns()
        record={'schema':'ezs.profile-trace.v1','seq':None,'at_utc':dt.datetime.now(dt.timezone.utc).isoformat(timespec='microseconds'),
                'run_id':self.run_id,'trace_id':self.trace_id,'event':name,
                'elapsed_us':(now-self.start_ns)//1000,'duration_us':None if start_ns is None else (now-start_ns)//1000,
                'data':clean(data)}
        with self.lock:
            self.seq+=1;record['seq']=self.seq
            with self.file.open('a',encoding='utf-8') as f:
                f.write(json.dumps(record,ensure_ascii=False,default=str,allow_nan=False)+'\n')
    def snapshot(self,name,payload):
        # JSONL remains authoritative; snapshot aids manual debugging.
        self.event(name,payload)
        path=self.folder/(f'{self.seq:04d}_'+re.sub('[^a-zA-Z0-9_.-]','_',name)+'.json')
        path.write_text(json.dumps(clean(payload),indent=2,ensure_ascii=False,default=str),encoding='utf-8')
    @contextlib.contextmanager
    def phase(self,name,request=None):
        started=time.perf_counter_ns()
        self.event(name+'.begin',request)
        try:
            yield
        except BaseException as exc:
            self.event(name+'.error',{'type':type(exc).__name__,'message':str(exc),'traceback':traceback.format_exc()},start_ns=started)
            raise
        else:
            self.event(name+'.end',start_ns=started)
    def finish(self,outcome):self.event('trace.finish',outcome,start_ns=self.start_ns)

def current():return _ACTIVE.get()
@contextlib.contextmanager
def activate(trace):
    token=_ACTIVE.set(trace)
    try:yield trace
    finally:_ACTIVE.reset(token)

def emit(event,data=None,*,start_ns=None):
    t=current()
    if t:t.event(event,data,start_ns=start_ns)

def snap(event,data):
    t=current()
    if t:t.snapshot(event,data)

# Purge is only applied to dedicated per-job directories, never elsewhere.
def purge():
    root=Path(os.getenv('EZS_PROFILE_TRACE_ROOT') or r'H:\EZStudio_Run_Reports_state\assistant-traces')
    if not root.is_dir():return
    try:days=min(90,max(1,int(os.getenv('EZS_PROFILE_TRACE_RETENTION_DAYS','7'))))
    except ValueError:days=7
    threshold=time.time()-days*86400
    import shutil
    for p in root.iterdir():
        if p.is_dir() and re.fullmatch(r'run-\d+-[0-9a-f]{12}',p.name) and p.stat().st_mtime<threshold:
            shutil.rmtree(p)
