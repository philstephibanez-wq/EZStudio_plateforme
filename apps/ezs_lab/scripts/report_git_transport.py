"""MAIN transport for allowlisted PROFILE reporting; no scientific worker I/O.

The Git repository is a durable reporting sink. No raw traces enter this module.
"""
from __future__ import annotations
import datetime as dt
import json
import os
from pathlib import Path
import re
import subprocess

REPO = Path(os.environ.get('EZS_RUN_REPORTS_ROOT', r'H:\EZStudio_Run_Reports'))
ALLOWED = re.compile(r'^(?:lab/profile/run-\d{6}\.json|assistant/profile/run-\d{6}/revision-\d{6}\.json)$')
TRUE = {'1', 'true', 'on', 'yes'}
SWITCH = Path(os.environ.get('EZS_RUN_REPORTS_SWITCH', r'H:\EZStudio_Run_Reports_state\autopush.flag'))


def _git(repo, *args):
    command = ['git', '-C', str(repo), *args]
    proc = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          timeout=60, check=False,
                          creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0),
                          env={**os.environ, 'GIT_TERMINAL_PROMPT': '0',
                               'GCM_INTERACTIVE': 'never'})
    if proc.returncode:
        # Do not log git stderr: it could contain credentials or remote URLs.
        raise RuntimeError(f'git_{args[0]}_failed_rc_{proc.returncode}')
    return proc.stdout.decode('utf-8', 'replace').strip()


def _write_json_atomic(path, document):
    path.parent.mkdir(parents=True, exist_ok=True)
    data = (json.dumps(document, sort_keys=True, indent=2, ensure_ascii=False,
                       allow_nan=False) + '\n').encode('utf-8')
    temp = path.with_suffix(path.suffix + '.pending')
    temp.write_bytes(data)
    os.replace(temp, path)


def _status(repo, name, run_id, revision_id, result):
    # Whitelisted fields only; safe to commit to private reports repo.
    today = dt.datetime.now(dt.timezone.utc).isoformat()
    document = {'schema':'ezstudio.profile-publication.v1', 'producer':name,
                'phase':'profile', 'run_id':run_id, 'revision_id':revision_id,
                'status':result, 'observed_at_utc':today}
    suffix = f'revision-{revision_id:06d}' if revision_id else 'science'
    path = repo / 'logs' / 'profile' / f'run-{run_id:06d}' / f'{suffix}.json'
    if path.is_file():
        try:
            if json.loads(path.read_text(encoding='utf-8'))['status'] == result:
                return path.relative_to(repo).as_posix()
        except (OSError, ValueError, KeyError, TypeError):
            pass
    _write_json_atomic(path, document)
    return path.relative_to(repo).as_posix()


def _valid_report(path, report):
    if not ALLOWED.fullmatch(path):
        raise ValueError('report_path_not_allowlisted')
    if not isinstance(report, dict) or report.get('phase') != 'profile':
        raise ValueError('report_schema_invalid')
    run_id = report.get('run_id')
    if type(run_id) is not int or run_id <= 0:
        raise ValueError('report_run_id_invalid')
    producer = report.get('producer')
    if producer not in ('lab', 'assistant') or not path.startswith(producer + '/profile/'):
        raise ValueError('report_producer_invalid')
    revision = report.get('revision_id')
    if producer == 'assistant' and (type(revision) is not int or revision <= 0):
        raise ValueError('report_revision_id_invalid')
    if producer == 'lab':
        revision = None
    expected = (f'lab/profile/run-{run_id:06d}.json' if revision is None else
                f'assistant/profile/run-{run_id:06d}/revision-{revision:06d}.json')
    if path != expected:
        raise ValueError('report_identity_mismatch')
    return producer, run_id, revision


def persist(repo, path, report):
    """Local durable write, independent of Git/network toggle."""
    producer, rid, revision = _valid_report(path, report)
    repo = Path(repo)
    if not (repo / '.git').exists():
        raise RuntimeError('reports_repository_missing')
    if not isinstance(report.get('schema'), str) or report['schema'] != 'ezstudio.run-report.v1':
        raise ValueError('report_schema_unexpected')
    destination = repo / path
    if destination.exists():
        existing = destination.read_bytes()
        desired = (json.dumps(report, sort_keys=True, indent=2, ensure_ascii=False, allow_nan=False) + '\n').encode('utf-8')
        if existing != desired:
            modified = _git(repo, 'status', '--porcelain', '--', path)
            if modified:
                raise RuntimeError('existing_report_has_local_edits')
    _write_json_atomic(destination, report)
    receipt = _status(repo, producer, rid, revision, 'persisted_local')
    return path, receipt


def push(repo, paths):
    """Commit only approved report paths and receipts; don't stage code or foreign edits."""
    repo = Path(repo)
    setting = os.getenv('EZS_RUN_REPORTS_AUTOPUSH')
    if setting is None:
        try:
            setting = SWITCH.read_text(encoding='utf-8').strip()
        except OSError:
            setting = '0'
    enabled = setting.lower() in TRUE
    if not enabled:
        return 'disabled'
    if _git(repo, 'branch', '--show-current') != 'main':
        raise RuntimeError('reports_branch_not_main')
    if _git(repo, 'diff', '--cached', '--name-only'):
        raise RuntimeError('reports_index_not_clean')
    for p in paths:
        if not (ALLOWED.fullmatch(p) or re.fullmatch(r'logs/profile/run-\d{6}/(?:science|revision-\d{6})\.json',p)
                or re.fullmatch(r'diagnostics/(?:lab|assistant)/profile/run-\d{6}/trace-[a-f0-9]{12}/(?:manifest\.json|data/[a-z0-9_-]+\.part-\d{5}|timeline-\d{4}\.jsonl|(?:source-sets|assistant-preset-evidence|stage-observations)\.json\.part-\d{4}|snapshots/[0-9]{4}_[A-Za-z0-9_.-]+\.json\.part-\d{4})',p)):
            raise ValueError('staging_path_not_allowlisted')
    # No implicit git add -A; only the exact exported files.
    _git(repo, 'add', '--', *paths)
    staged = _git(repo, 'diff', '--cached', '--name-only').splitlines()
    unexpected = set(staged).difference(paths)
    if unexpected:
        raise RuntimeError('unexpected_staging')
    if not staged:
        # A previous push can have failed after the local commit succeeded.
        local = _git(repo, 'rev-parse', 'HEAD')
        remote = _git(repo, 'ls-remote', 'origin', 'refs/heads/main')
        if remote.startswith(local + '\t'):
            return 'unchanged_verified'
        _git(repo, 'push', 'origin', 'main')
        remote = _git(repo, 'ls-remote', 'origin', 'refs/heads/main')
        if not remote.startswith(local + '\t'):
            raise RuntimeError('remote_head_verification_failed')
        return 'published_verified'
    message = '[reports] profile: persist sanitized LAB and Assistant reports'
    try:
        _git(repo, '-c', 'commit.gpgsign=false', 'commit', '-m', message, '--only', '--', *paths)
        _git(repo, 'push', 'origin', 'main')
        local = _git(repo, 'rev-parse', 'HEAD')
        remote = _git(repo, 'ls-remote', 'origin', 'refs/heads/main')
        if not remote.startswith(local + '\t'):
            raise RuntimeError('remote_head_verification_failed')
        return 'published_verified'
    except Exception:
        # The local commit and files are durable; retry must not discard either.
        raise
