"""MAIN-only PROFILE report watcher; filesystem first, optional Git push.

Keeps the existing summary sanitizer in publish_run_reports.collect().
Never touches scientific runs; all Git operations are best-effort and observable.
"""
from __future__ import annotations
import argparse
from contextlib import closing
from pathlib import Path
import sqlite3
import sys
import time
import publish_run_reports as publisher
import report_git_transport as transport


def publish_changes(db_path: Path, state_dir: Path, batch_size: int = 5) -> tuple[int, int]:
    state_file = state_dir / 'published_git.json'
    state = publisher._load_state(state_file)
    previous_state_file = state_dir / 'published.json'
    previous_state = publisher._load_state(previous_state_file)
    pushed = errors = 0
    with closing(sqlite3.connect(f'file:{db_path.resolve().as_posix()}?mode=ro',
                                 uri=True, timeout=5)) as db:
        # Prioritize fresh Assistant results; preserve existing scientific reports.
        for producer in ('assistant', 'lab'):
            attempted = 0
            for path, report in publisher.collect(db):
                if report['producer'] != producer:
                    continue
                is_profile = report.get('phase') == 'profile'
                digest = publisher._digest(report)
                target_state = state if is_profile else previous_state
                if target_state.get(path) == digest:
                    continue
                if attempted >= batch_size:
                    break
                attempted += 1
                try:
                    if is_profile:
                        findings = publisher.scan_record(report)
                        if findings:
                            raise ValueError('report_privacy_audit_failed')
                        paths = transport.persist(transport.REPO, path, report)
                        outcome = transport.push(transport.REPO, paths)
                        if outcome in ('published_verified', 'unchanged_verified'):
                            state[path] = digest
                            publisher._save_state(state_file, state)
                            if outcome == 'published_verified':
                                pushed += 1
                    else:
                        # Preserve historical transport for STEMS/CHORDS/LYRICS.
                        outcome = publisher.publish(path, report)
                        previous_state[path] = digest
                        publisher._save_state(previous_state_file, previous_state)
                        pushed += 1
                    print(f'{producer}: {outcome}: {path}', flush=True)
                except Exception as exc:
                    errors += 1
                    print(f'{producer}: failed: {path}: {type(exc).__name__}: {str(exc)[:100]}',
                          file=sys.stderr, flush=True)
    return pushed, errors


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument('--db', type=Path, default=Path(__file__).resolve().parents[1] / 'data' / 'benchmark.sqlite')
    parser.add_argument('--state-dir', type=Path, default=publisher.STATE_DIR)
    parser.add_argument('--interval', type=float, default=2.0)
    parser.add_argument('--batch-size', type=int, default=5)
    parser.add_argument('--once', action='store_true')
    args = parser.parse_args()
    if not (1 <= args.interval <= 3600) or not (1 <= args.batch_size <= 30):
        parser.error('interval must be 1..3600 seconds and batch-size 1..30')
    if not args.db.is_file():
        parser.error('database not found')
    print('REPORT_WATCHER_STARTED', flush=True)
    while True:
        try:
            pushed, errors = publish_changes(args.db, args.state_dir, args.batch_size)
            if pushed or errors:
                print(f'REPORT_WATCHER pushed={pushed} failed={errors}', flush=True)
        except Exception as exc:
            print(f'REPORT_WATCHER_FAILED {type(exc).__name__}: {str(exc)[:100]}',
                  file=sys.stderr, flush=True)
            if args.once:
                return 1
        if args.once:
            return 1 if errors else 0
        time.sleep(args.interval)

if __name__ == '__main__':
    raise SystemExit(main())
