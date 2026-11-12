"""Single event-driven PROFILE publication; no polling, scheduled jobs or console.

Invoked only after LAB persistence, and only if enabled. No output on stdout.
"""
from __future__ import annotations
import argparse
from contextlib import contextmanager, closing
import datetime as dt
import json
import os
from pathlib import Path
import sqlite3
import sys
import traceback

import publish_run_reports as publisher
import report_git_transport as transport

STATE = Path(os.getenv('EZSTUDIO_REPORTS_STATE_DIR', r'H:\EZStudio_Run_Reports_state'))
SWITCH = Path(os.getenv('EZS_RUN_REPORTS_SWITCH', r'H:\EZStudio_Run_Reports_state\autopush.flag'))
DB = Path(__file__).resolve().parents[1] / 'data' / 'benchmark.sqlite'


def enabled():
    try:
        return SWITCH.read_text(encoding='ascii').strip() == '1'
    except FileNotFoundError:
        return True  # checked by default


def record_error(run_id, producer, reason):
    if not enabled():
        return
    path = transport.REPO / 'logs' / 'profile' / 'publication-errors.jsonl'
    path.parent.mkdir(parents=True, exist_ok=True)
    item = {'schema': 'ezstudio.profile-publication-error.v1',
            'run_id': run_id, 'producer': producer, 'reason': reason,
            'utc': dt.datetime.now(dt.timezone.utc).isoformat()}
    with path.open('a', encoding='utf-8') as handle:
        handle.write(json.dumps(item, sort_keys=True) + '\n')


@contextmanager
def exclusive():
    """Serialized one-shot Git operations; never allow concurrent index modification."""
    STATE.mkdir(parents=True, exist_ok=True)
    file = (STATE / 'profile-publication.lock').open('a+b')
    try:
        if os.name == 'nt':
            import msvcrt
            file.seek(0)
            if file.read(1) == b'':
                file.write(b'0')
                file.flush()
            file.seek(0)
            msvcrt.locking(file.fileno(), msvcrt.LK_LOCK, 1)
        else:
            import fcntl
            fcntl.flock(file.fileno(), fcntl.LOCK_EX)
        yield
    finally:
        if os.name == 'nt':
            file.seek(0)
            msvcrt.locking(file.fileno(), msvcrt.LK_UNLCK, 1)
        else:
            import fcntl
            fcntl.flock(file.fileno(), fcntl.LOCK_UN)
        file.close()


def publish_once(run_id, producer, revision_id=None, db_path=DB, state_dir=STATE):
    if not enabled():
        return 'disabled'  # no directory, no log, no Git, no report
    if run_id < 1 or producer not in ('lab', 'assistant'):
        raise ValueError('invalid_report_target')
    with exclusive():
        if not enabled():
            return 'disabled'
        # sqlite3.Connection.__exit__ commits/rolls back but does NOT close.
        # Windows keeps db.sqlite locked until close(), breaking tests and cleanup.
        with closing(sqlite3.connect(f'file:{Path(db_path).resolve().as_posix()}?mode=ro', uri=True)) as db:
            matches = []
            for path, report in publisher.collect(db):
                if report.get('phase') != 'profile' or report.get('producer') != producer:
                    continue
                if report.get('run_id') != run_id:
                    continue
                if producer == 'assistant' and revision_id is not None and report.get('revision_id') != revision_id:
                    continue
                matches.append((path, report))
        if not matches:
            return 'not_persisted'
        # An Assistant completion must refer to one exact revision. No old revisions by accident.
        if producer == 'assistant':
            if revision_id is None or len(matches) != 1:
                raise ValueError('assistant_revision_identity_required')
        elif len(matches) != 1:
            raise ValueError('lab_run_identity_ambiguous')
        path, report = matches[0]
        fingerprint = publisher._digest(report)
        state_path = Path(state_dir) / 'published_git.json'
        state = publisher._load_state(state_path)
        if state.get(path) == fingerprint:
            if producer == 'lab':
                import profile_forensic_archive as forensic
                paths = forensic.archive_lab(run_id, db_path)
                if paths and enabled():
                    os.environ['EZS_RUN_REPORTS_AUTOPUSH'] = '1'
                    transport.push(transport.REPO, paths)
            return 'already_published'
        if publisher.scan_record(report):
            raise ValueError('report_privacy_audit_failed')
        paths = list(transport.persist(transport.REPO, path, report))
        # OFF wins over diagnostics: don't even read the scientific database
        # after a UI disable during preparation. Preserve R3C behavior.
        if not enabled():
            return 'disabled_after_local_prepare'
        if producer == 'lab':
            import profile_forensic_archive as forensic
            paths += forensic.archive_lab(run_id, db_path)
        if not enabled():
            return 'disabled_before_push'
        # The UI switch is authoritative for this finite event, not a stale
        # process environment inherited from an old R1/R2 launcher.
        os.environ['EZS_RUN_REPORTS_AUTOPUSH'] = '1'
        outcome = transport.push(transport.REPO, paths)
        if outcome not in ('published_verified', 'unchanged_verified'):
            raise RuntimeError('github_publication_unverified')
        state[path] = fingerprint
        publisher._save_state(state_path, state)
        return outcome


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--run-id', type=int, required=True)
    parser.add_argument('--producer', choices=['lab', 'assistant'], required=True)
    parser.add_argument('--revision-id', type=int)
    args = parser.parse_args()
    if not enabled():
        return 0
    try:
        outcome = publish_once(args.run_id, args.producer, args.revision_id)
        if outcome == 'not_persisted':
            raise ValueError('profile_result_not_persisted')
        return 0
    except Exception as exc:
        # Never persist exception message/traceback, which can carry credentials.
        record_error(args.run_id, args.producer, type(exc).__name__)
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
