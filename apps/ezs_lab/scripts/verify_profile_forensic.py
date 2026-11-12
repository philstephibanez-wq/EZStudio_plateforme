"""Read-only archive validation. Verify manifest and every byte of every fragment.

Example: python verify_profile_forensic.py H:/EZStudio_Run_Reports diagnostics/lab/profile/run-000197/trace-<id>/manifest.json
"""
from __future__ import annotations
import argparse
import hashlib
import json
from pathlib import Path
import re

ALLOWED = re.compile(r'diagnostics/(?:lab|assistant)/profile/run-\d{6}/trace-[a-f0-9]{12}/manifest\.json')


def verify(repo: Path, manifest_path: str):
    if not ALLOWED.fullmatch(manifest_path):
        raise ValueError('manifest_path_invalid')
    doc = json.loads((repo/manifest_path).read_text(encoding='utf-8'))
    if doc.get('schema') == 'ezstudio.assistant-profile-raw-archive.v1':
        if doc.get('producer') != 'EZStudioLab_assistant' or doc.get('run_id') != int(manifest_path.split('/')[3][4:]) or doc.get('trace_id', '')[:12] != manifest_path.split('/')[4][6:]:
            raise ValueError('assistant_manifest_identity_mismatch')
        prefix=manifest_path.rsplit('/',1)[0]+'/'
        allowed = re.compile(r'(?:timeline-\d{4}\.jsonl|(?:source-sets|assistant-preset-evidence|stage-observations)\.json\.part-\d{4}|snapshots/[0-9]{4}_[A-Za-z0-9_.-]+\.json\.part-\d{4})')
        pieces=doc.get('artifacts')
        if not isinstance(pieces,list) or doc.get('artifact_count')!=len(pieces):
            raise ValueError('assistant_artifact_count_invalid')
        checked=[]
        for item in pieces:
            path=item.get('path') if isinstance(item,dict) else None
            if not isinstance(path,str) or not allowed.fullmatch(path):
                raise ValueError('assistant_artifact_path_invalid')
            blob=(repo/(prefix+path)).read_bytes()
            if len(blob)!=item.get('size_bytes') or hashlib.sha256(blob).hexdigest()!=item.get('sha256'):
                raise ValueError('assistant_artifact_hash_mismatch:'+path)
            checked.append({'logical':path,'bytes':len(blob),'verified':True})
        return {'run_id':doc['run_id'],'producer':'assistant','trace_id':doc['trace_id'][:12],
                'schema':doc['schema'],'documents':checked,'capture_boundary':doc.get('capture_boundary')}
    if doc.get('schema') != 'ezs.forensic-manifest.v1':
        raise ValueError('unsupported_forensic_manifest_schema')
    prefix=manifest_path.rsplit('/',1)[0]+'/data/'
    results=[]
    for entry in doc['documents']:
        fragments=entry['fragments']
        buffers=[]
        for fragment in fragments:
            file=fragment['path']
            if not file.startswith(prefix) or not re.fullmatch(r'diagnostics/(?:lab|assistant)/profile/run-\d{6}/trace-[a-f0-9]{12}/data/[a-z0-9_-]+\.part-\d{5}',file):
                raise ValueError('unapproved_fragment_path')
            blob=(repo/file).read_bytes()
            if len(blob)!=fragment['bytes'] or hashlib.sha256(blob).hexdigest()!=fragment['sha256']:
                raise ValueError('fragment_hash_mismatch:'+file)
            buffers.append(blob)
        rebuilt=b''.join(buffers)
        if len(rebuilt)!=entry['size_bytes'] or hashlib.sha256(rebuilt).hexdigest()!=entry['sha256']:
            raise ValueError('document_roundtrip_mismatch:'+entry['logical_name'])
        json.loads(rebuilt)
        results.append({'logical':entry['logical_name'],'bytes':len(rebuilt),'fragments':len(buffers),'verified':True})
    return {'run_id':doc['run_id'],'producer':doc['producer'],'trace_id':doc['trace_id'],
            'completeness':doc['completeness'],'omissions':doc['omissions'],'documents':results}


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('repo',type=Path)
    parser.add_argument('manifest')
    args=parser.parse_args()
    print(json.dumps(verify(args.repo,args.manifest),ensure_ascii=False,indent=2))

if __name__=='__main__':main()
