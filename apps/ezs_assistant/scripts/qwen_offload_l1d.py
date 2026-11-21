#!/usr/bin/env python3
"""Read-only Qwen offload evidence, per owned process. Never exports raw log content."""
from __future__ import annotations
import argparse
import datetime as dt
import json
import os
from pathlib import Path
import re
import subprocess
import sys

OFFLOAD = re.compile(r'(?i)offloaded\s+(\d+)\s*/\s*(\d+)\s+layers\s+to\s+GPU')
BUFFER = re.compile(r'(?i)(Vulkan\d+)\s+model buffer size\s*=\s*([0-9]+(?:\.[0-9]+)?)\s*MiB')
DEVICE = re.compile(r'(?i)using device\s+(Vulkan\d+)\s*\(?\s*(NVIDIA[^\r\n)]*|Intel[^\r\n)]*)?')
FLAGS = re.compile(r'(?i)(?:^|\s)-dev\s+(Vulkan\d+)(?:\s|$)')
LAYERS = re.compile(r'(?i)(?:^|\s)(?:-ngl|--gpu-layers|--n-gpu-layers)\s+(\d+)(?:\s|$)')


def parse_lines(lines):
    info={'offload_layers':None,'gpu_model_buffer_mib':None,'vulkan_device_in_log':None,'device_vendor_in_log':None}
    for line in lines:
        m=OFFLOAD.search(line)
        if m:
            a,b=int(m.group(1)),int(m.group(2))
            if 0<=a<=b<=100000: info['offload_layers']={'actual':a,'total':b}
        m=BUFFER.search(line)
        if m:
            info['gpu_model_buffer_mib']=float(m.group(2))
            info['vulkan_device_in_log']=m.group(1)
        m=DEVICE.search(line)
        if m:
            info['vulkan_device_in_log']=m.group(1)
            if m.group(2):info['device_vendor_in_log']='NVIDIA' if 'nvidia' in m.group(2).lower() else 'Intel'
    return info


def cim(pid):
    script=("$p=Get-CimInstance Win32_Process -Filter 'ProcessId="+str(pid)+"' -ErrorAction Stop;"
            "if($null -eq $p){exit 3};"
            "[pscustomobject]@{pid=[int]$p.ProcessId;created=[string]$p.CreationDate;"
            "name=[string]$p.Name;command=[string]$p.CommandLine}|ConvertTo-Json -Compress")
    cp=subprocess.run(['powershell.exe','-NoProfile','-NonInteractive','-Command',script],
                      stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=10,check=False)
    if cp.returncode: return None
    try: return json.loads(cp.stdout.decode('utf-8-sig'))
    except (ValueError,UnicodeDecodeError):return None


def report(root:Path, *, owner=None, proc=None, log_lines=None):
    """Owner and process arguments are private: all returned fields are allowlisted."""
    out={'schema':'ezstudio.runtime-report.assistant-offload.local.v1',
         'scope':'local_metadata_only_no_automatic_publication',
         'created_utc':dt.datetime.now(dt.timezone.utc).isoformat(),
         'owner_verified':False,'process_verified':False,'native_log_bound_to_pid':False,
         'requested_device':None,'requested_gpu_layers':None,
         'actual_offload_layers':None,'model_buffer_mib':None,
         'actual_vulkan_device':'unverified','physical_vendor':'unverified',
         'offload_attestation':'unverified','reason':'owner_or_active_process_unavailable'}
    if owner is None:
        path=root/'var'/'runtime'/'ezs_assistant'/'llama'/'owner.json'
        try:owner=json.loads(path.read_text(encoding='utf-8-sig'))
        except (OSError,ValueError):return out
    if not isinstance(owner,dict):return out
    try:pid=int(owner.get('pid') or 0)
    except (TypeError,ValueError):return out
    if pid<=0:return out
    out['owner_verified']=True
    if proc is None:proc=cim(pid) if os.name=='nt' else None
    if not isinstance(proc,dict):return out
    cmd=str(proc.get('command') or '')
    creation=str(proc.get('created') or '')
    if (str(proc.get('name') or '').lower()!='llama-server.exe'
        or int(proc.get('pid') or 0)!=pid
        or creation!=str(owner.get('creation') or '')
        or not re.search(r'(?i)(?:^|\s)--port\s+8720(?:\s|$)',cmd)):
        out['reason']='process_identity_mismatch'
        return out
    out['process_verified']=True
    match=FLAGS.search(cmd)
    if match:out['requested_device']=match.group(1)
    match=LAYERS.search(cmd)
    if match:out['requested_gpu_layers']=int(match.group(1))
    native=owner.get('native_log')
    if not isinstance(native,str) or not native:
        out['reason']='native_log_absent_from_owner'
        return out
    path=Path(native)
    allowed=(root/'var'/'log'/'ezs_assistant'/'llama').resolve()
    if path.resolve().parent!=allowed or not path.name.startswith('llama-native-') or path.suffix!='.log':
        out['reason']='native_log_path_not_allowlisted';return out
    # The same exact file must be present in the running process command line.
    if ('--log-file' not in cmd.lower() or str(path).lower() not in cmd.lower()):
        out['reason']='active_process_not_bound_to_native_log';return out
    out['native_log_bound_to_pid']=True
    if log_lines is None:
        try:
            if path.stat().st_size>8*1024*1024:
                out['reason']='native_log_too_large';return out
            log_lines=path.read_text(encoding='utf-8',errors='replace').splitlines()
        except OSError:
            out['reason']='native_log_unreadable';return out
    data=parse_lines(log_lines)
    out['actual_offload_layers']=data['offload_layers']
    out['model_buffer_mib']=data['gpu_model_buffer_mib']
    if data['vulkan_device_in_log']:out['actual_vulkan_device']=data['vulkan_device_in_log']
    if data['device_vendor_in_log']:out['physical_vendor']=data['device_vendor_in_log']
    layer=data['offload_layers']
    if (layer and layer['actual']>0 and data['vulkan_device_in_log']==out['requested_device']
        and data['device_vendor_in_log']=='NVIDIA' and data['gpu_model_buffer_mib'] is not None):
        out['offload_attestation']='verified_startup_log_bound_to_active_pid'
        out['reason']='positive_device_and_offload_markers'
    else:
        out['reason']='missing_or_incomplete_positive_offload_markers'
    return out


def main():
    p=argparse.ArgumentParser(description='Read-only local Qwen attestation')
    p.add_argument('--root',required=True,type=Path)
    p.add_argument('--output',required=True,type=Path)
    a=p.parse_args()
    root=a.root.resolve();target=a.output.resolve()
    if root==target or root in target.parents:p.error('output_must_be_outside_repository')
    data=report(root)
    target.parent.mkdir(parents=True,exist_ok=True)
    target.write_text(json.dumps(data,ensure_ascii=False,indent=2,sort_keys=True)+'\n',encoding='utf-8')
    print('L1D_REPORT_OK attestation='+data['offload_attestation']+' reason='+data['reason'])
    return 0

if __name__=='__main__':raise SystemExit(main())
