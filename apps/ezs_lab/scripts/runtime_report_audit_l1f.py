#!/usr/bin/env python3
"""L1F: independent, local-only publication candidate gate. Never imports transport."""
from __future__ import annotations
import argparse,hashlib,importlib.util,json,re,sys
from pathlib import Path

SCHEMA='ezstudio.runtime-report.export-candidate.v1'
RUN=re.compile(r'^run-[0-9]{6,12}$')
MAX_BYTES=120000
SAFE_KEY=re.compile(r'^[a-z][a-z0-9_.-]{0,95}$')
BLOCKED=re.compile(r'(?i)(?:[a-z]:[\\/]|\\\\|/(?:home|users)/|github_pat_|gh[pousr]_[a-z0-9]+|sk-[a-z0-9_-]{12,}|bearer\s+\S+|https?://|@|-----BEGIN.*PRIVATE KEY)')

def read_json(path:Path, limit=3_000_000):
    if path.is_symlink() or not path.is_file() or path.stat().st_size>limit:raise ValueError('INPUT_INVALID')
    def no_dup(pairs):
        d={}
        for k,v in pairs:
            if k in d:raise ValueError('DUPLICATE_JSON_FIELD')
            d[k]=v
        return d
    try:return json.loads(path.read_text(encoding='utf-8-sig'),object_pairs_hook=no_dup,
                          parse_constant=lambda x:(_ for _ in ()).throw(ValueError('NON_FINITE_JSON')))
    except (UnicodeError,ValueError) as e:raise ValueError('JSON_INVALID_OR_DUPLICATED') from e

def load_l1e(path:Path):
    spec=importlib.util.spec_from_file_location('runtime_reports_l1e_for_audit',str(path))
    if spec is None or spec.loader is None:raise ValueError('L1E_IMPORT_INVALID')
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    if getattr(module,'SCHEMA',None)!=SCHEMA:raise ValueError('L1E_SCHEMA_MISMATCH')
    return module

def verify(candidate,expected,run):
    if type(candidate) is not dict or candidate.get('schema')!=SCHEMA or candidate.get('run')!=run:
        raise ValueError('CANDIDATE_SCHEMA_OR_RUN_INVALID')
    if candidate.get('publication')!='not_published_requires_separate_authorization':
        raise ValueError('PUBLICATION_CONSENT_MISSING')
    if candidate.get('destination')!='EZStudio_Run_Reports':raise ValueError('DESTINATION_UNEXPECTED')
    if candidate!=expected:raise ValueError('CANDIDATE_DIFFERS_FROM_ALLOWLIST_REBUILD')
    if candidate.get('assistant',{}).get('linked_to_profile_run') is not False:
        raise ValueError('ASSISTANT_RUN_CORRELATION_NOT_PROVEN')
    if candidate.get('coverage',{}).get('assistant_offload_linked_to_lab_run') is not False:
        raise ValueError('COVERAGE_CORRELATION_NOT_PROVEN')
    compact=json.dumps(candidate,ensure_ascii=False,allow_nan=False,sort_keys=True,separators=(',',':')).encode('utf-8')
    if len(compact)>MAX_BYTES:raise ValueError('CANDIDATE_OVERSIZE')
    forbidden_keys={'model_path','source_path','filepath','filename','token','secret',
                    'prompt','response','audio_path','stem_path','raw_trace','pid',
                    'command_line','authorization','cookie','username','email'}
    def check_keys(value):
        if isinstance(value,dict):
            for key,child in value.items():
                if key.lower() in forbidden_keys:raise ValueError('PRIVATE_FIELD_IN_CANDIDATE')
                check_keys(child)
        elif isinstance(value,list):
            for child in value:check_keys(child)
    check_keys(candidate)
    if BLOCKED.search(compact.decode('utf-8')):raise ValueError('PRIVATE_VALUE_IN_CANDIDATE')
    return hashlib.sha256(compact).hexdigest()

def audit(root,candidate_path,l1a,l1b,l1d,run,receipt_path):
    root=root.resolve();receipt_path=receipt_path.resolve()
    if root==receipt_path or root in receipt_path.parents or receipt_path==candidate_path.resolve():
        raise ValueError('OUTPUT_PATH_UNSAFE')
    if receipt_path.exists() or receipt_path.is_symlink():raise ValueError('OUTPUT_ALREADY_EXISTS')
    if not RUN.fullmatch(run):raise ValueError('RUN_INVALID')
    if candidate_path.resolve()==receipt_path:raise ValueError('OUTPUT_COLLISION')
    module=load_l1e(root/'apps/ezs_lab/scripts/runtime_reports_l1e.py')
    a=module._load(l1a,module.L1A); b=module._load(l1b,module.L1B); d=module._load(l1d,module.L1D)
    expected=module.build(a,b,d,run)
    candidate=read_json(candidate_path,MAX_BYTES)
    digest=verify(candidate,expected,run)
    # The receipt is metadata-only. It is not approval for publication.
    receipt={'schema':'ezstudio.runtime-report.privacy-audit.local.v1',
             'run':run,'candidate_semantic_sha256':digest,
             'module_count':candidate['lab']['module_count'],
             'phase_module_count':candidate['phases']['phase_module_count'],
             'privacy_gate':'passed_allowlist_reconstruction',
             'cross_process_gpu_correlation':'not_attested',
             'publication':'blocked_pending_explicit_authorization',
             'remote_write_performed':False,
             'transport_compatibility':'not_compatible_with_legacy_report_git_transport_v1'}
    receipt_path.parent.mkdir(parents=True,exist_ok=True)
    raw=(json.dumps(receipt,sort_keys=True,ensure_ascii=False,indent=2,allow_nan=False)+'\n').encode('utf-8')
    with receipt_path.open('xb') as f:f.write(raw)
    return receipt

def main(argv=None):
    p=argparse.ArgumentParser(description='L1F privacy gate, local only, no publisher or git calls')
    for field in ('root','candidate','lab-l1a','phases-l1b','assistant-l1d','output'):
        p.add_argument('--'+field,required=True,type=Path)
    p.add_argument('--run',required=True)
    args=p.parse_args(argv)
    try:
        result=audit(args.root,args.candidate,args.lab_l1a,args.phases_l1b,args.assistant_l1d,args.run,args.output)
        print('L1F_AUDIT_OK modules='+str(result['module_count'])+' phases='+str(result['phase_module_count'])+' publication=false')
        print('L1F_CANDIDATE_SEMANTIC_SHA256='+result['candidate_semantic_sha256'])
        return 0
    except (ValueError,OSError,TypeError,KeyError) as exc:
        print('L1F_AUDIT_REFUSED:'+type(exc).__name__+':'+str(exc),file=sys.stderr)
        return 1
if __name__=='__main__':raise SystemExit(main())
