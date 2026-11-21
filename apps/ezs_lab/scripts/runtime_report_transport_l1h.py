#!/usr/bin/env python3
"""L1H isolated versioned publishing transport. Default is strictly offline dry-run.

This never uses the legacy publisher or modifies the MAIN database/worktree.
Remote write is only reachable through independent explicit, digest-bound flags.
"""
from __future__ import annotations
import argparse, base64, hashlib, importlib.util, json, os, re, subprocess, sys
from pathlib import Path

DEST = 'philstephibanez-wq/EZStudio_Run_Reports'
REPO_LABEL = 'EZStudio_Run_Reports'
PLAN_SCHEMA = 'ezstudio.runtime-report.transport-plan.local.v1'
RUN = re.compile(r'^run-[0-9]{6,12}$')
SHA = re.compile(r'^[a-f0-9]{64}$')
MAX_BYTES = 100_000

def load_l1g(root):
    p = root/'apps/ezs_lab/scripts/runtime_report_transport_l1g.py'
    spec = importlib.util.spec_from_file_location('l1g_transport_for_l1h', p)
    if spec is None or spec.loader is None:
        raise ValueError('L1G_MISSING')
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod

def wire(candidate):
    return (json.dumps(candidate, ensure_ascii=False, sort_keys=True, indent=2, allow_nan=False)+'\n').encode('utf-8')

def validate(root, run, candidate_path, audit_path, plan_path, l1a, l1b, l1d):
    if not RUN.fullmatch(run):raise ValueError('RUN_INVALID')
    mod=load_l1g(root)
    candidate=mod.load_json(candidate_path)
    audit=mod.load_json(audit_path)
    plan=mod.load_json(plan_path)
    semantic=mod.audit_gate(root,run,candidate,audit,l1a,l1b,l1d)
    if candidate.get('schema')!=mod.PAYLOAD_SCHEMA or candidate.get('run')!=run:
        raise ValueError('CANDIDATE_SCHEMA_OR_RUN_INVALID')
    expected_keys={'candidate_canonical_bytes','candidate_canonical_bytes_sha256',
       'candidate_semantic_sha256','dry_run','git_commit_performed','git_push_performed',
       'legacy_transport_modified','module_count','new_transport_schema_required',
       'phase_module_count','proposed_relative_path','publication','remote_write_performed',
       'run','schema','source_audit_verified','target_repository'}
    if type(plan) is not dict or set(plan)!=expected_keys:
        raise ValueError('PLAN_FIELDS_INVALID')
    if plan['schema']!=PLAN_SCHEMA or plan['run']!=run or plan['target_repository']!=REPO_LABEL:
        raise ValueError('PLAN_IDENTITY_INVALID')
    if plan['proposed_relative_path']!=f'observability/profile/{run}.json':
        raise ValueError('DESTINATION_NOT_ALLOWLISTED')
    if not (plan['dry_run'] is True and plan['git_commit_performed'] is False
        and plan['git_push_performed'] is False and plan['legacy_transport_modified'] is False
        and plan['source_audit_verified'] is True and plan['new_transport_schema_required'] is True
        and plan['remote_write_performed'] is False and plan['publication']=='blocked_pending_explicit_authorization'):
        raise ValueError('PLAN_SAFETY_INVALID')
    raw=wire(candidate)
    if len(raw)>MAX_BYTES:raise ValueError('REPORT_SIZE_EXCEEDED')
    if (plan['candidate_semantic_sha256']!=semantic or plan['candidate_canonical_bytes']!=len(raw)
        or plan['candidate_canonical_bytes_sha256']!=hashlib.sha256(raw).hexdigest()
        or plan['module_count']!=audit['module_count'] or plan['phase_module_count']!=audit['phase_module_count']):
        raise ValueError('PLAN_CONTENT_MISMATCH')
    if not SHA.fullmatch(semantic):raise ValueError('SEMANTIC_DIGEST_INVALID')
    return plan['proposed_relative_path'],raw,semantic

def gh(method, endpoint, payload=None):
    command=['gh','api','--method',method,endpoint]
    if payload is not None:command += ['--input','-']
    env={**os.environ,'GH_PROMPT_DISABLED':'1','GIT_TERMINAL_PROMPT':'0','GCM_INTERACTIVE':'never'}
    try:
        p=subprocess.run(command,input=(json.dumps(payload,separators=(',',':')).encode('utf-8') if payload is not None else None),
              stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=45,env=env,check=False,
              creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    except (OSError,subprocess.TimeoutExpired):
        raise RuntimeError('GITHUB_API_UNAVAILABLE') from None
    if p.returncode:
        # Never echo stderr: it can include a URL/token.
        if method=='GET' and b'HTTP 404' in p.stderr:
            return None
        raise RuntimeError('GITHUB_API_'+method+'_FAILED_RC_'+str(p.returncode))
    try:
        result=json.loads(p.stdout)
    except (ValueError,UnicodeError):
        raise RuntimeError('GITHUB_API_INVALID_JSON') from None
    if not isinstance(result,dict):raise RuntimeError('GITHUB_API_INVALID_OBJECT')
    return result

def get_remote(path):
    endpoint=f'repos/{DEST}/contents/{path}?ref=main'
    record=gh('GET',endpoint)
    if record is None:return None
    if record.get('type')!='file' or record.get('encoding')!='base64':
        raise RuntimeError('REMOTE_NOT_REGULAR_BASE64_FILE')
    try:
        data=base64.b64decode(record['content'],validate=False)
    except (ValueError,TypeError,KeyError):
        raise RuntimeError('REMOTE_CONTENT_INVALID') from None
    if len(data)>MAX_BYTES:raise RuntimeError('REMOTE_FILE_TOO_LARGE')
    gitsha=hashlib.sha1(b'blob '+str(len(data)).encode('ascii')+b'\0'+data).hexdigest()
    if record.get('sha')!=gitsha:
        raise RuntimeError('REMOTE_BLOB_SHA_MISMATCH')
    return data

def remote_preflight(path,raw):
    current=get_remote(path)
    if current is None:return 'absent_create_allowed'
    if current==raw:return 'already_equal_no_write'
    raise RuntimeError('REMOTE_DIVERGENCE_REFUSED')

def publish(path,raw,semantic,confirmation,authorized,confirm_destination):
    if not (authorized and confirmation==semantic and confirm_destination==REPO_LABEL):
        raise ValueError('EXPLICIT_PUBLICATION_AUTHORIZATION_REQUIRED')
    state=remote_preflight(path,raw)
    if state=='already_equal_no_write':return 'unchanged_verified'
    payload={'message':'[observability] publish audited PROFILE runtime '+path.split('/')[-1],
             'branch':'main','content':base64.b64encode(raw).decode('ascii')}
    # Create-only: NEVER supply sha. Concurrent creations are rejected by GitHub.
    endpoint=f'repos/{DEST}/contents/{path}'
    gh('PUT',endpoint,payload)
    actual=get_remote(path)
    if actual!=raw:raise RuntimeError('REMOTE_ROUNDTRIP_MISMATCH')
    return 'published_verified'

def save_receipt(output,root,inputs,record):
    output=Path(output).resolve()
    if output==root or root in output.parents or output in inputs or output.exists() or output.is_symlink():
        raise ValueError('OUTPUT_UNSAFE_OR_EXISTS')
    output.parent.mkdir(parents=True,exist_ok=True)
    with output.open('xb') as f:
        f.write(wire(record))

def execute(args):
    root=args.root.resolve()
    inputs=[p.resolve() for p in (args.candidate,args.audit,args.plan,args.lab_l1a,args.phases_l1b,args.assistant_l1d)]
    # Fail before any GitHub invocation or publication side effects if output is unsafe.
    output=args.output.resolve()
    if output==root or root in output.parents or output in inputs or output.exists() or output.is_symlink():
        raise ValueError('OUTPUT_UNSAFE_OR_EXISTS')
    path,raw,semantic=validate(root,args.run,args.candidate,args.audit,args.plan,
                                args.lab_l1a,args.phases_l1b,args.assistant_l1d)
    if args.publish:
        if not (args.authorize_digest==semantic and args.confirm_remote_write and args.confirm_destination==REPO_LABEL):
            raise ValueError('EXPLICIT_PUBLICATION_AUTHORIZATION_REQUIRED')
        result=publish(path,raw,semantic,args.authorize_digest,args.confirm_remote_write,args.confirm_destination)
    elif args.remote_preflight:
        if args.authorize_digest or args.confirm_remote_write or args.confirm_destination:
            raise ValueError('AUTHORIZATION_FLAGS_WITHOUT_PUBLISH_REFUSED')
        result=remote_preflight(path,raw)
    else:
        if args.authorize_digest or args.confirm_remote_write or args.confirm_destination:
            raise ValueError('AUTHORIZATION_FLAGS_WITHOUT_PUBLISH_REFUSED')
        result='offline_dry_run_verified'
    record={'schema':'ezstudio.runtime-report.transport-v2-receipt.local.v1',
            'run':args.run,'destination_repository':REPO_LABEL,'relative_path':path,
            'candidate_semantic_sha256':semantic,'candidate_bytes_sha256':hashlib.sha256(raw).hexdigest(),
            'candidate_bytes':len(raw),'mode':'publish' if args.publish else ('remote_preflight' if args.remote_preflight else 'offline_dry_run'),
            'result':result,'remote_write_performed':result=='published_verified',
            'legacy_transport_modified':False,'source_audit_verified':True}
    save_receipt(args.output,root,inputs,record)
    return record

def parser():
    p=argparse.ArgumentParser(description='L1H isolated transport v2; OFFLINE dry-run by default')
    for key in ('root','candidate','audit','plan','lab-l1a','phases-l1b','assistant-l1d','output'):
        p.add_argument('--'+key,type=Path,required=True)
    p.add_argument('--run',required=True)
    modes=p.add_mutually_exclusive_group()
    modes.add_argument('--remote-preflight',action='store_true',help='Read-only GitHub status check')
    modes.add_argument('--publish',action='store_true',help='Requires three exact authorization flags')
    p.add_argument('--authorize-digest',default=None)
    p.add_argument('--confirm-remote-write',action='store_true')
    p.add_argument('--confirm-destination',default=None)
    return p

def main(argv=None):
    args=parser().parse_args(argv)
    try:
        r=execute(args)
        print('L1H_'+r['mode'].upper()+'_OK result='+r['result']+' remote_write='+str(r['remote_write_performed']).lower())
        print('L1H_SHA256='+r['candidate_semantic_sha256'])
        return 0
    except (ValueError,OSError,TypeError,KeyError,AttributeError,RuntimeError) as e:
        # Error strings are static enumerations, no raw source values or subprocess stderr.
        print('L1H_REFUSED:'+type(e).__name__+':'+str(e),file=sys.stderr)
        return 1
if __name__=='__main__':raise SystemExit(main())
