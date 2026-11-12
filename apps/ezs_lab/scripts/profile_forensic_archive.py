"""Event-only, MAIN-local PROFILE forensic archive. No worker, scheduler or polling.

The archive contains all *available* structured values after explicit safety redaction.
It never claims to recover upstream fields that were not emitted.
"""
from __future__ import annotations

from contextlib import closing
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
from urllib.parse import urlsplit, urlunsplit, parse_qsl, urlencode

import publish_run_reports as summary
import publish_profile_oneshot as oneshot
import report_git_transport as git_transport

ROOT = Path(os.getenv('EZS_LAB_ROOT', r'H:\EZStudio_plateforme\apps\ezs_lab'))
SEGMENT_BYTES = 128 * 1024
SECRET_KEY = re.compile(r'(?i)(authorization|cookie|set-cookie|access[_-]?token|refresh[_-]?token|api[_-]?key|secret|password|passwd|credential|private[_-]?key|session[_-]?id|csrf|bearer)')
SECRET_TEXT = [
    re.compile(r'(?i)\bBearer\s+[^\s"\']+'),
    re.compile(r'(?i)\b(?:Authorization|Cookie|Set-Cookie|X-EZScore-Analysis-Token)\s*[:=]\s*[^\r\n]+'),
    re.compile(r'(?i)\bsk-[a-z0-9_-]{20,}'),
    re.compile(r'(?i)\b(?:github_pat_|gh[pousr]_)[a-z0-9_\-]{8,}'),
    re.compile(r'(?i)\b(?:api[_-]?key|token|password|secret)\s*[=:]\s*["\']?[^\s&"\']+'),
    re.compile(r'(?i)\b[A-Z]:\\[^\s"\']+'),
    re.compile(r'(?i)/(?:home|Users)/[^/\s"\']+'),
]
PRIVATE_QUERY = re.compile(r'(?i)(token|key|auth|cookie|secret|password|signature|session)')


def utc():
    return dt.datetime.now(dt.timezone.utc).isoformat()


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def clean(obj, audit, location='$'):
    """Preserve JSON types/cardinality, redact credentials recursively, record every redaction."""
    if isinstance(obj, dict):
        out = {}
        for k, value in obj.items():
            key = str(k)
            at = location + '.' + key
            if SECRET_KEY.search(key):
                out[key] = '[REDACTED_SECRET]'
                audit.append({'path': at, 'reason': 'secret_key'})
            else:
                out[key] = clean(value, audit, at)
        return out
    if isinstance(obj, list):
        return [clean(value, audit, f'{location}[{index}]') for index, value in enumerate(obj)]
    if isinstance(obj, str):
        value = obj
        if value.startswith(('http://', 'https://')):
            p = urlsplit(value)
            if p.username or p.password:
                audit.append({'path': location, 'reason': 'url_credentials'})
                value = urlunsplit((p.scheme, p.hostname or '', p.path, p.query, p.fragment))
                p = urlsplit(value)
            queries = parse_qsl(p.query, keep_blank_values=True)
            if any(PRIVATE_QUERY.search(k) for k, _ in queries):
                value = urlunsplit((p.scheme, p.netloc, p.path, urlencode([(k, '[REDACTED]') if PRIVATE_QUERY.search(k) else (k,v) for k,v in queries]), p.fragment))
                audit.append({'path': location, 'reason': 'url_sensitive_query'})
        for pattern in SECRET_TEXT:
            modified = pattern.sub('[REDACTED_SECRET]', value)
            if modified != value:
                audit.append({'path': location, 'reason': 'secret_or_private_path'})
                value = modified
        return value
    if obj is None or isinstance(obj, (bool,int,float)):
        return obj
    raise ValueError('non_json_capture_type')


def _json_bytes(value):
    return (json.dumps(value, ensure_ascii=False, sort_keys=True, indent=2, allow_nan=False) + '\n').encode('utf-8')


def _safe_write(path: Path, data: bytes):
    if path.exists():
        if path.read_bytes() != data:
            raise RuntimeError('immutable_archive_conflict')
        return
    path.parent.mkdir(parents=True, exist_ok=True)
    pending = path.with_name(path.name + '.pending')
    pending.write_bytes(data)
    os.replace(pending, path)


def _install_artifact(repo: Path, prefix: str, logical: str, value, audit: list):
    payload = _json_bytes(clean(value, audit, '$.' + logical))
    pieces = [payload[i:i+SEGMENT_BYTES] for i in range(0, len(payload), SEGMENT_BYTES)] or [b'']
    files = []
    for index, fragment in enumerate(pieces, 1):
        path = f'{prefix}/data/{logical}.part-{index:05d}'
        _safe_write(repo / path, fragment)
        files.append({'path':path,'bytes':len(fragment),'sha256':sha(fragment)})
    # Proven round-trip after redaction; no truncation or loss at segment boundaries.
    if b''.join((repo / p['path']).read_bytes() for p in files) != payload:
        raise RuntimeError('archive_segment_roundtrip_failed')
    return {'logical_name': logical, 'sha256': sha(payload), 'size_bytes':len(payload), 'fragments': files}


def archive_document_set(producer: str, run_id: int, trace_id: str, revision_id, docs, *, missing=(), job_id=None):
    """Immutable segments + manifest, committed atomically in one Git transaction."""
    if not oneshot.enabled():
        return []
    if producer not in ('lab','assistant') or type(run_id) is not int or run_id <= 0:
        raise ValueError('invalid_forensic_identity')
    if not re.fullmatch(r'[a-f0-9]{12}', trace_id):
        raise ValueError('invalid_trace_id')
    prefix = f'diagnostics/{producer}/profile/run-{run_id:06d}/trace-{trace_id}'
    repo = git_transport.REPO
    if not (repo / '.git').exists():
        raise RuntimeError('reports_repository_missing')
    prior=repo / prefix / 'manifest.json'
    if prior.is_file():
        # Preserve the original version/provenance on a later retry.
        from verify_profile_forensic import verify
        verified=verify(repo, prefix+'/manifest.json')
        old=json.loads(prior.read_text(encoding='utf-8'))
        if (old.get('producer'),old.get('run_id'),old.get('revision_id')) != (producer,run_id,revision_id):
            raise RuntimeError('existing_manifest_identity_conflict')
        expected={item['logical_name']:item['sha256'] for item in old['documents']}
        current={name:sha(_json_bytes(clean(value,[],'$.'+name))) for name,value in docs}
        if current!=expected or list(dict.fromkeys(missing))!=old.get('omissions',[]):
            raise RuntimeError('immutable_trace_content_changed_new_trace_required')
        paths=[frag['path'] for doc in old['documents'] for frag in doc['fragments']]
        return paths+[prefix+'/manifest.json']
    audit = []
    manifest_docs=[]
    for label, value in docs:
        if not re.fullmatch('[a-z0-9_-]{1,64}', label):
            raise ValueError('unsafe_logical_document_name')
        manifest_docs.append(_install_artifact(repo, prefix, label, value, audit))
    # A piece absent upstream must be explicitly documented, never inferred.
    omissions=list(dict.fromkeys(missing))
    source_root = ROOT if producer == 'lab' else Path(os.getenv('EZS_ASSISTANT_ROOT',r'H:\EZStudioLab_assistant'))
    try:
        product_version=(source_root/'VERSION').read_text(encoding='utf-8-sig').strip()
    except OSError:
        product_version=None
    try:
        git_sha=git_transport._git(source_root,'rev-parse','HEAD')
    except (OSError,RuntimeError):
        git_sha=None
    manifest = {
        'schema':'ezs.forensic-manifest.v1',
        'producer':producer, 'phase':'profile', 'run_id':run_id,
        'job_id':job_id, 'revision_id':revision_id, 'trace_id':trace_id,
        'product_version':product_version, 'source_git_sha':git_sha,
        'captured_at_utc':None, 'documents':manifest_docs,
        'omissions':omissions, 'redactions':audit,
        'completeness':'partial' if omissions else 'complete',
        'scope':'available_allowed_observations_only',
    }
    manifest_path=prefix+'/manifest.json'
    manifest_raw=_json_bytes(manifest)
    _safe_write(repo / manifest_path, manifest_raw)
    return [piece['path'] for doc in manifest_docs for piece in doc['fragments']] + [manifest_path]


def _profile_job(db, run_id: int):
    # Older minimal SQLite schemas used by the R3C contract contain no job table.
    # Missing optional diagnostics are not a failed scientific publication.
    exists = db.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='analysis_jobs'").fetchone()
    if not exists:
        return None, None
    sql="SELECT id,request_json FROM analysis_jobs WHERE scientific_run_id=? AND kind='profile' ORDER BY id DESC LIMIT 1"
    row=db.execute(sql,(run_id,)).fetchone()
    if not row: return None,None
    data=json.loads(row[1] or '{}')
    return row[0], data.get('output_path')


def archive_lab(run_id: int, db_path):
    if not oneshot.enabled(): return []
    with closing(sqlite3.connect('file:'+Path(db_path).resolve().as_posix()+'?mode=ro',uri=True)) as db:
        job_id, output_path = _profile_job(db, run_id)
    omissions=[]
    docs=[]
    if not output_path:
        omissions.append('scientific_output_path_missing')
        scientific=None
    else:
        output=Path(output_path).resolve()
        permitted=(ROOT/'var'/'storage').resolve()
        configured=os.getenv('EZSTUDIO_STORAGE_ROOT')
        if configured: permitted=Path(configured).resolve()
        if not output.is_relative_to(permitted):
            raise ValueError('scientific_artifact_outside_storage_root')
        scientific=output
    if scientific and scientific.is_file():
        raw=json.loads(scientific.read_text(encoding='utf-8'))
        docs.append(('scientific-result',raw))
        for ix, module in enumerate((raw.get('module_registry') or {}).get('modules') or []):
            # Each module retains the exact raw/normalized record as a separate artifact.
            docs.append((f'module-{ix:04d}',module))
    else:
        omissions.append('scientific_result_missing')
    timeline=scientific.parent/'profile-forensic.jsonl' if scientific else None
    if job_id is not None:
        log_root=Path(os.getenv('EZSTUDIO_LOG_ROOT',str(ROOT/'var'/'logs'))).resolve()
        worker_log=log_root/'jobs'/f'job-{job_id:08d}-profile.log'
        if worker_log.is_file():
            docs.append(('worker-stdout-stderr', {'lines':worker_log.read_text(encoding='utf-8',errors='replace').splitlines()}))
        else:
            omissions.append('worker_job_log_missing')
    else:
        omissions.append('worker_job_id_missing')
    if timeline and timeline.is_file():
        lines=[json.loads(line) for line in timeline.read_text(encoding='utf-8').splitlines() if line.strip()]
        docs.append(('timeline',lines))
    else:
        omissions.append('module_timeline_not_instrumented_or_unavailable')
    # Stable correlation key: scientific output hash when present, else run/job.
    identity=f'{run_id}:{job_id}:{sha(scientific.read_bytes()) if scientific and scientific.is_file() else "missing"}'
    trace_id=hashlib.sha256(identity.encode('utf-8')).hexdigest()[:12]
    return archive_document_set('lab',run_id,trace_id,None,docs,missing=omissions,job_id=job_id)


def publish_assistant_trace(trace, outcome):
    """MAIN transport for the existing assistant-main raw archive package.

    The Assistant's canonical package() and validate_manifest() remain the
    authority on redaction, provenance and fragment hashes. No second packager.
    The worker keeps its original pre-ack cancellation ordering unchanged.
    """
    if not oneshot.enabled(): return 'disabled'
    import sys
    assistant_app=Path(os.getenv('EZS_ASSISTANT_ROOT',r'H:\EZStudioLab_assistant'))/'app'
    if str(assistant_app) not in sys.path: sys.path.insert(0,str(assistant_app))
    from profile_raw_archive import package
    from profile_archive_contract import validate_manifest
    run_id=int(trace.run_id)
    revision=outcome.get('revision') if isinstance(outcome,dict) and type(outcome.get('revision')) is int else None
    with oneshot.exclusive():
        if not oneshot.enabled(): return 'disabled'
        prefix,parts,manifest=package(trace,outcome)
        validate_manifest(manifest,dict(parts))
        # Exact canonical byte paths; immutable conflicts are explicit errors.
        paths=[]
        for name, raw in parts:
            rel=prefix+'/'+name
            if not _approved_assistant_path(rel):
                raise ValueError('assistant_raw_archive_path_invalid')
            _safe_write(git_transport.REPO/rel,raw)
            paths.append(rel)
        mpath=prefix+'/manifest.json'
        if not _approved_assistant_path(mpath):
            raise ValueError('assistant_manifest_path_invalid')
        _safe_write(git_transport.REPO/mpath,_json_bytes(manifest))
        paths.append(mpath)
        if revision is not None:
            with closing(sqlite3.connect('file:'+oneshot.DB.resolve().as_posix()+'?mode=ro',uri=True)) as db:
                matches=[(path,report) for path,report in summary.collect(db)
                         if report.get('producer')=='assistant' and report.get('phase')=='profile'
                         and report.get('run_id')==run_id and report.get('revision_id')==revision]
            if len(matches)!=1: raise RuntimeError('assistant_exact_revision_not_persisted')
            path, report=matches[0]
            paths=list(git_transport.persist(git_transport.REPO,path,report))+paths
        if not oneshot.enabled(): return 'disabled_before_push'
        os.environ['EZS_RUN_REPORTS_AUTOPUSH']='1'
        result=git_transport.push(git_transport.REPO,paths)
        if result not in ('published_verified','unchanged_verified'):
            raise RuntimeError('forensic_remote_unverified')
        return result


def _approved_assistant_path(path):
    return re.fullmatch(
      r'diagnostics/assistant/profile/run-\d{6}/trace-[a-f0-9]{12}/'
      r'(?:manifest\.json|timeline-\d{4}\.jsonl|'
      r'(?:source-sets|assistant-preset-evidence|stage-observations)\.json\.part-\d{4}|'
      r'snapshots/[0-9]{4}_[A-Za-z0-9_.-]+\.json\.part-\d{4})',path
    ) is not None
