#!/usr/bin/env python3
"""L1E: deterministic, allowlisted, local-only staging for EZStudio_Run_Reports.

Does not import or call the GitHub publisher; does not access SQLite or workers.
All upstream inputs are treated as untrusted. No generic recursive sanitizer.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import math
from pathlib import Path
import re
import sys

SCHEMA = 'ezstudio.runtime-report.export-candidate.v1'
L1A = 'ezstudio.runtime-report.local.v1'
L1B = 'ezstudio.runtime-report.l1b.local.v1'
L1D = 'ezstudio.runtime-report.assistant-offload.local.v1'
RUN = re.compile(r'^run-[0-9]{6,12}$')
MODULE = re.compile(r'^[a-z][a-z0-9_.-]{0,95}$')
PHASE = re.compile(r'^[a-z][a-z0-9_.-]{0,63}$')
STATUS = {'ok', 'error', 'failed', 'skipped', 'cancelled'}
DEVICES = {'cuda:0', 'cuda:1', 'cpu:numpy', 'cpu', 'unreported', 'unverified', 'cuda:0-isolated'}
ATTESTATION = {'unverified', 'verified_startup_log_bound_to_active_pid'}
MAX_INPUT = 3_000_000
MAX_MODULES = 100
MAX_PHASES = 100


def _load(path: Path, expected: str) -> dict:
    if path.is_symlink() or not path.is_file() or path.stat().st_size > MAX_INPUT:
        raise ValueError('INPUT_NOT_SAFE')
    try:
        data = json.loads(path.read_text(encoding='utf-8-sig'))
    except (UnicodeError, ValueError) as exc:
        raise ValueError('INPUT_NOT_JSON') from exc
    if not isinstance(data, dict) or data.get('schema') != expected:
        raise ValueError('INPUT_SCHEMA_MISMATCH')
    return data


def _duration(x, *, maximum=86400):
    if x is None or isinstance(x, bool) or not isinstance(x, (int, float)):
        return None
    if not math.isfinite(x) or x < 0 or x > maximum:
        return None
    return round(float(x), 6)


def _enum(x, valid, fallback):
    return x if isinstance(x, str) and x in valid else fallback


def _int(x, *, maximum):
    return x if isinstance(x, int) and not isinstance(x, bool) and 0 <= x <= maximum else None


def _bool(x):
    return x if type(x) is bool else False


def _lab(data: dict, run: str) -> dict:
    if data.get('run') != run:
        raise ValueError('LAB_RUN_MISMATCH')
    rows = data.get('modules')
    if not isinstance(rows, list) or len(rows) > MAX_MODULES:
        raise ValueError('LAB_MODULES_INVALID')
    found = set()
    modules = []
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError('LAB_MODULE_INVALID')
        name = row.get('module_id')
        if not isinstance(name, str) or not MODULE.fullmatch(name) or name in found:
            raise ValueError('LAB_MODULE_ID_INVALID')
        found.add(name)
        modules.append({
            'id': name,
            'status': _enum(row.get('status'), STATUS, 'unverified'),
            'declared_device': _enum(row.get('declared_device'), DEVICES, 'unverified'),
            'elapsed_wall_s': _duration(row.get('wall_seconds')),
            'process_cpu_s': _duration(row.get('process_cpu_seconds')),
            'peak_rss_mib_sampled': _duration(row.get('peak_rss_mib_sampled'), maximum=1048576),
            'peak_child_rss_mib_sampled': _duration(row.get('peak_child_rss_mib_sampled'), maximum=1048576),
            'cpu_samples': _int(row.get('runtime_sample_count'), maximum=1000000),
            'gpu_host_mean_percent': _duration(row.get('gpu_host_utilization_mean_percent'), maximum=100),
            'gpu_host_max_percent': _duration(row.get('gpu_host_utilization_max_percent'), maximum=100),
            'gpu_host_samples': _int(row.get('gpu_sample_count'), maximum=1000000),
            'physical_device_attestation': 'unverified',
            'gpu_pid_attribution': 'unverified',
        })
    modules.sort(key=lambda r:r['id'])
    return {'module_count': len(modules), 'modules': modules}


def _phases(data: dict) -> dict:
    raw = data.get('modules')
    if not isinstance(raw, dict) or len(raw)>MAX_PHASES:
        raise ValueError('PHASES_INVALID')
    result=[]
    for name,row in sorted(raw.items()):
        if not PHASE.fullmatch(name) or not isinstance(row,dict):
            raise ValueError('PHASE_INVALID')
        coverage = row.get('coverage')
        coverage = coverage if isinstance(coverage,dict) else {}
        spans = row.get('spans_seconds')
        if not isinstance(spans,dict) or len(spans)>MAX_PHASES:
            raise ValueError('PHASE_SPANS_INVALID')
        measured = {}
        for label,seconds in sorted(spans.items()):
            if isinstance(label,str) and PHASE.fullmatch(label):
                value=_duration(seconds)
                if value is not None:measured[label]=value
        result.append({
            'module':name,
            'event_count':_int(row.get('event_count'),maximum=1000000),
            'spans_seconds':measured,
            'internal_subprocess_observed':_bool(coverage.get('subprocess_internal_observed')) if name=='passt' else (name=='passt_child'),
            'runtime_gpu_attribution':'not_attested',
        })
    return {'phase_module_count':len(result),'phase_modules':result}


def _assistant(data:dict) -> dict:
    state=_enum(data.get('offload_attestation'),ATTESTATION,'unverified')
    positive=(state=='verified_startup_log_bound_to_active_pid'
              and data.get('owner_verified') is True
              and data.get('process_verified') is True
              and data.get('native_log_bound_to_pid') is True
              and data.get('physical_vendor')=='NVIDIA'
              and isinstance(data.get('requested_device'),str)
              and re.fullmatch(r'Vulkan[0-9]{1,2}',data['requested_device'])
              and data.get('actual_vulkan_device')==data.get('requested_device')
              and isinstance(data.get('actual_offload_layers'),dict)
              and _duration(data.get('model_buffer_mib'),maximum=1048576) is not None
              and data.get('model_buffer_mib') > 0)
    layer=data.get('actual_offload_layers') if positive else {}
    actual=_int(layer.get('actual'),maximum=100000) if isinstance(layer,dict) else None
    total=_int(layer.get('total'),maximum=100000) if isinstance(layer,dict) else None
    positive=bool(positive and actual and total and 0<actual<=total)
    return {
        'offload_attestation':'verified_startup_log_bound_to_active_pid' if positive else 'unverified',
        'physical_vendor':'NVIDIA' if positive else 'unverified',
        'actual_vulkan_device':data['actual_vulkan_device'] if positive else 'unverified',
        'offloaded_layers':actual if positive else None,
        'total_layers':total if positive else None,
        'gpu_model_buffer_mib':_duration(data.get('model_buffer_mib'),maximum=1048576) if positive else None,
        'gpu_kernel_time':'not_measured',
        'linked_to_profile_run':False,
    }


def build(a:dict,b:dict,d:dict,run:str)->dict:
    if not RUN.fullmatch(run):raise ValueError('RUN_INVALID')
    out={'schema':SCHEMA,'destination':'EZStudio_Run_Reports','phase':'profile','run':run,
         'publication':'not_published_requires_separate_authorization',
         'lab':_lab(a,run),'phases':_phases(b), 'assistant':_assistant(d),
         'coverage':{
             'lab_scientific_outputs':'not_included',
             'assistant_offload_linked_to_lab_run':False,
             'profile_cpu_counters':'present_only_if_sampled',
             'physical_gpu_for_lab':'not_attested_by_L1A',
             'phase_gpu_kernel_time':'not_measured',
             'privacy':'explicit_schema_allowlist_only',
         }}
    # Conservative guard: all output must be JSON and derived from fixed fields.
    raw=json.dumps(out,ensure_ascii=False,allow_nan=False,sort_keys=True,separators=(',',':')).encode('utf-8')
    if len(raw)>120000:raise ValueError('OUTPUT_TOO_LARGE')
    if any(x in raw.lower() for x in (b'\\\\users\\\\',b'github_pat_',b'bearer ',b'--log-file',b'--model')):
        raise ValueError('OUTPUT_PRIVACY_GUARD')
    return out


def main(argv=None):
    p=argparse.ArgumentParser(description='L1E export candidat local seulement, zéro push')
    p.add_argument('--root',type=Path,required=True)
    p.add_argument('--run',required=True)
    p.add_argument('--lab-l1a',type=Path,required=True)
    p.add_argument('--phases-l1b',type=Path,required=True)
    p.add_argument('--assistant-l1d',type=Path,required=True)
    p.add_argument('--output',type=Path,required=True)
    args=p.parse_args(argv)
    root=args.root.resolve()
    out=args.output.resolve()
    if root==out or root in out.parents:
        p.error('OUTPUT_MUST_BE_OUTSIDE_REPO')
    if out.exists():
        p.error('OUTPUT_ALREADY_EXISTS_REFUSED')
    try:
        result=build(_load(args.lab_l1a,L1A),_load(args.phases_l1b,L1B),_load(args.assistant_l1d,L1D),args.run)
        out.parent.mkdir(parents=True,exist_ok=True)
        target=out.with_suffix(out.suffix+'.pending')
        if target.exists():raise ValueError('STAGING_PENDING_EXISTS')
        raw=(json.dumps(result,ensure_ascii=False,indent=2,sort_keys=True,allow_nan=False)+'\n').encode('utf-8')
        with target.open('xb') as f:f.write(raw)
        target.replace(out)
        print('L1E_EXPORT_CANDIDATE_OK modules='+str(result['lab']['module_count'])+' phases='+str(result['phases']['phase_module_count'])+' published=false')
        print('L1E_SHA256='+hashlib.sha256(raw).hexdigest())
        return 0
    except (OSError,ValueError,TypeError) as exc:
        print('L1E_FAILED:'+type(exc).__name__+':'+str(exc),file=sys.stderr)
        return 1

if __name__=='__main__':raise SystemExit(main())
