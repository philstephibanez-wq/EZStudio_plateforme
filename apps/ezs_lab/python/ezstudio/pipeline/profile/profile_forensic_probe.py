"""Step-by-step non-scientific observer for the canonical PROFILE module pipeline.

No additional inference, no preset mutation. OFF means no file or log writes.
"""
from __future__ import annotations
import datetime as dt
import json
import os
from pathlib import Path
import sys
import time

LAB_ROOT = Path(__file__).resolve().parents[4]
if str(LAB_ROOT / 'scripts') not in sys.path:
    sys.path.insert(0,str(LAB_ROOT / 'scripts'))


def enabled():
    flag=Path(os.getenv('EZS_RUN_REPORTS_SWITCH',r'H:\EZStudio_Run_Reports_state\autopush.flag'))
    try: return flag.read_text(encoding='ascii').strip()=='1'
    except FileNotFoundError: return True


def event(name, data=None, *, since_ns=None):
    if not enabled(): return False
    target=os.getenv('EZS_PROFILE_FORENSIC_DIR','')
    if not target: return False
    from profile_forensic_archive import clean
    audit=[]
    try:
        payload=clean(data or {},audit)
        entry={'schema':'ezs.profile-engine-step.v1','event':name,
               'at_utc':dt.datetime.now(dt.timezone.utc).isoformat(),
               'duration_us':(time.perf_counter_ns()-since_ns)//1000 if since_ns else None,
               'data':payload,'redactions':audit}
        folder=Path(target)
        folder.mkdir(parents=True,exist_ok=True)
        with (folder/'profile-forensic.jsonl').open('a',encoding='utf-8') as stream:
            stream.write(json.dumps(entry,ensure_ascii=False,allow_nan=False)+'\n')
        return True
    except Exception as exc:
        # Telemetry failures never affect scientific decisions or overwrite the output.
        print('PROFILE_FORENSIC_CAPTURE_FAILED type='+type(exc).__name__,file=sys.stderr,flush=True)
        return False
