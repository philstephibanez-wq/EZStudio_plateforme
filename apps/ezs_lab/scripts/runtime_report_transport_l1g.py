#!/usr/bin/env python3
"""L1G: offline transport preparation only. NO Git, network, commit, push or publisher import."""
from __future__ import annotations
import argparse,hashlib,importlib.util,json,re,sys
from pathlib import Path

PATH_RE=re.compile(r'^run-[0-9]{6,12}$')
PAYLOAD_SCHEMA='ezstudio.runtime-report.export-candidate.v1'
AUDIT_SCHEMA='ezstudio.runtime-report.privacy-audit.local.v1'
PREPARED_SCHEMA='ezstudio.runtime-report.transport-plan.local.v1'
REPORT_ROOT='observability/profile/'

def unique_pairs(pairs):
    out={}
    for key,value in pairs:
        if key in out:raise ValueError('JSON_DUPLICATE_KEY')
        out[key]=value
    return out

def load_json(path, max_size=120_000):
    path=Path(path)
    if not path.is_file() or path.is_symlink() or path.stat().st_size>max_size:raise ValueError('INPUT_INVALID')
    return json.loads(path.read_text(encoding='utf-8-sig'),object_pairs_hook=unique_pairs,
                      parse_constant=lambda value:(_ for _ in ()).throw(ValueError('NON_FINITE_JSON')))

def semantic_hash(value):
    raw=json.dumps(value,ensure_ascii=False,sort_keys=True,separators=(',',':'),allow_nan=False).encode('utf-8')
    return hashlib.sha256(raw).hexdigest()

def byte_hash(raw):return hashlib.sha256(raw).hexdigest()

def audit_gate(root,run,candidate,receipt,l1a,l1b,l1d):
    script=root/'apps/ezs_lab/scripts/runtime_report_audit_l1f.py'
    spec=importlib.util.spec_from_file_location('l1f_audit_for_l1g',script)
    if spec is None or spec.loader is None:raise ValueError('L1F_MODULE_MISSING')
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    rebuild=module.load_l1e(root/'apps/ezs_lab/scripts/runtime_reports_l1e.py')
    expected=rebuild.build(rebuild._load(l1a,rebuild.L1A),rebuild._load(l1b,rebuild.L1B),rebuild._load(l1d,rebuild.L1D),run)
    digest=module.verify(candidate,expected,run)
    if type(receipt) is not dict or set(receipt)!={'candidate_semantic_sha256','cross_process_gpu_correlation','module_count','phase_module_count','privacy_gate','publication','remote_write_performed','run','schema','transport_compatibility'}:
        raise ValueError('AUDIT_FIELDS_INVALID')
    if receipt['schema']!=AUDIT_SCHEMA or receipt['run']!=run or receipt['candidate_semantic_sha256']!=digest:
        raise ValueError('AUDIT_MISMATCH')
    if receipt['privacy_gate']!='passed_allowlist_reconstruction' or receipt['publication']!='blocked_pending_explicit_authorization' or receipt['remote_write_performed'] is not False:
        raise ValueError('AUDIT_GATE_NOT_PASSED')
    if receipt['cross_process_gpu_correlation']!='not_attested' or receipt['transport_compatibility']!='not_compatible_with_legacy_report_git_transport_v1':
        raise ValueError('AUDIT_PROVENANCE_INVALID')
    if type(receipt['module_count']) is not int or receipt['module_count']!=candidate['lab']['module_count'] or type(receipt['phase_module_count']) is not int or receipt['phase_module_count']!=candidate['phases']['phase_module_count']:
        raise ValueError('AUDIT_COUNTS_INVALID')
    return digest

def prepare(root,run,candidate_path,audit_path,l1a,l1b,l1d,output):
    root=Path(root).resolve()
    output=Path(output).resolve()
    inputs=[Path(p).resolve() for p in (candidate_path,audit_path,l1a,l1b,l1d)]
    if not PATH_RE.fullmatch(run):raise ValueError('RUN_INVALID')
    if output==root or root in output.parents or output in inputs or output.exists() or output.is_symlink():
        raise ValueError('OUTPUT_UNSAFE_OR_EXISTS')
    candidate=load_json(candidate_path)
    audit=load_json(audit_path)
    digest=audit_gate(root,run,candidate,audit,l1a,l1b,l1d)
    if candidate.get('schema')!=PAYLOAD_SCHEMA:raise ValueError('CANDIDATE_SCHEMA_INVALID')
    target=REPORT_ROOT+run+'.json'
    # Preparation only. No path in the user's reporting repo is written by L1G.
    wire=(json.dumps(candidate,ensure_ascii=False,sort_keys=True,indent=2,allow_nan=False)+'\n').encode('utf-8')
    report={'schema':PREPARED_SCHEMA,'run':run,'target_repository':'EZStudio_Run_Reports',
            'proposed_relative_path':target,'candidate_semantic_sha256':digest,
            'candidate_canonical_bytes_sha256':byte_hash(wire),'candidate_canonical_bytes':len(wire),
            'module_count':audit['module_count'],'phase_module_count':audit['phase_module_count'],
            'source_audit_verified':True,'new_transport_schema_required':True,
            'legacy_transport_modified':False,'publication':'blocked_pending_explicit_authorization',
            'dry_run':True,'remote_write_performed':False,'git_commit_performed':False,'git_push_performed':False}
    output.parent.mkdir(parents=True,exist_ok=True)
    with output.open('xb') as f:f.write((json.dumps(report,indent=2,ensure_ascii=False,sort_keys=True,allow_nan=False)+'\n').encode('utf-8'))
    return report

def main(argv=None):
    p=argparse.ArgumentParser(description='L1G: strictly offline publish transport simulation')
    for field in ('root','candidate','audit','lab-l1a','phases-l1b','assistant-l1d','output'):
        p.add_argument('--'+field,required=True,type=Path)
    p.add_argument('--run',required=True)
    args=p.parse_args(argv)
    try:
        result=prepare(args.root,args.run,args.candidate,args.audit,args.lab_l1a,args.phases_l1b,args.assistant_l1d,args.output)
        print('L1G_DRY_RUN_OK modules='+str(result['module_count'])+' phases='+str(result['phase_module_count'])+' published=false')
        print('L1G_SHA256='+result['candidate_semantic_sha256'])
        return 0
    except (ValueError,OSError,TypeError,KeyError,AttributeError) as exc:
        print('L1G_REFUSED:'+type(exc).__name__+':'+str(exc),file=sys.stderr)
        return 1
if __name__=='__main__':raise SystemExit(main())
