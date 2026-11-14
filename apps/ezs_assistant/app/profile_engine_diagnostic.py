"""Redacted operational PROFILE engine log, independent of scientific verdicts.

Events and summary snapshots are automatically included by the existing
append-only GitHub raw trace publisher. An additional safe local summary is
retained even if GitHub publication fails. No network activity in this module.
"""
from __future__ import annotations

from contextlib import contextmanager
from contextvars import ContextVar
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import tempfile
import time

from profile_trace import emit, snap

_ROOT = Path(__file__).resolve().parents[1]
_ACTIVE = ContextVar('ezs_profile_engine_diagnostic', default=None)
_CODE = re.compile(r'^[a-z][a-z0-9_:-]{0,79}$')


def _utc():
    return datetime.now(timezone.utc).isoformat()


def _safe_code(value):
    if not isinstance(value, str) or not _CODE.fullmatch(value):
        return None
    return value


def _status(value):
    if not isinstance(value, dict):
        return 'OK', None
    state = _safe_code(value.get('status'))
    reason = _safe_code(value.get('reason'))
    if state in ('not_configured', 'disabled', 'skipped', 'provider_not_configured',
                 'local_ai_provider_not_configured', 'unavailable'):
        return 'SKIPPED', reason or state
    if state in ('error', 'failed', 'invalid'):
        return 'FAILED', reason or state
    if state in ('partial', 'degraded', 'inconclusive'):
        return 'DEGRADED', reason or state
    return 'OK', reason


def call_engine(engine, func, *args, **kwargs):
    """Execute once, log timings/status, never swallow or alter errors/results."""
    if not isinstance(engine, str) or not _CODE.fullmatch(engine):
        raise ValueError('invalid_engine_id')
    state = _ACTIVE.get()
    if state is None:
        return func(*args, **kwargs)
    begun = time.perf_counter_ns()
    event = {'engine': engine, 'status': 'RUNNING', 'at_utc': _utc(),
             'run_id': state['run_id'], 'job_id': state['job_id']}
    emit('profile.engine.start', event)
    end = None
    try:
        result = func(*args, **kwargs)
        status, reason = _status(result)
        end = {'engine': engine, 'status': status, 'reason_code': reason,
               'exception_type': None}
        return result
    except BaseException as exc:
        # Class name only; never exception message or traceback in archive.
        end = {'engine': engine, 'status': 'FAILED', 'reason_code': 'engine_exception',
               'exception_type': type(exc).__name__}
        raise
    finally:
        if end is not None:
            end.update({'run_id': state['run_id'], 'job_id': state['job_id'],
                        'at_utc': _utc(),
                        'duration_ms': round((time.perf_counter_ns()-begun)/1e6, 3)})
            state['steps'].append(end)
            emit('profile.engine.end', end)
            if callable(state.get('on_step')):
                try: state['on_step'](dict(end))
                except Exception: pass


def record_item(engine, item, started_ns=None):
    """Emit one observed assertion outcome; no values, URLs, or retrieved content."""
    state = _ACTIVE.get()
    if state is None or not isinstance(item, dict):
        return
    key = _safe_code(item.get('key'))
    if not key:
        return
    status = _safe_code(item.get('status')) or _safe_code(item.get('verdict')) or 'unknown'
    docs = item.get('documents')
    elapsed = (round((time.perf_counter_ns()-started_ns)/1e6, 3)
               if type(started_ns) is int and started_ns >= 0 else None)
    event = {'engine': engine, 'item': key, 'observed_status': status,
             'reason_code': _safe_code(item.get('reason')),
             'document_count': len(docs) if isinstance(docs, list) else None,
             'run_id': state['run_id'], 'job_id': state['job_id'],
             'at_utc': _utc(), 'duration_ms': elapsed,
             'duration_semantics': 'measured' if elapsed is not None else 'not_instrumented_per_item'}
    state['items'].append(event)
    emit('profile.engine.item', event)


def record_items(engine, items):
    """Summarize per-assertion outcomes without values, URLs or source bodies.

    No synthetic per-item duration: only observed status and counts from the
    returned result of the owning engine are recorded.
    """
    state = _ACTIVE.get()
    if state is None or not isinstance(items, list):
        return
    for item in items:
        record_item(engine, item)


def _local_summary(trace, summary, root):
    # Safe atomic write on destination filesystem, not global H:/C: TEMP.
    parent = (Path(root) if root is not None else _ROOT/'runtime'/'reports')/'profile'/f'run-{trace.run_id:06d}'
    parent.mkdir(parents=True, exist_ok=True)
    destination = parent/f'execution-{trace.trace_id[:12]}.json'
    fd, tmp_path = tempfile.mkstemp(prefix='.ezs-profile-exec-', suffix='.tmp', dir=parent)
    temporary = Path(tmp_path)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8', newline='\n') as output:
            json.dump(summary, output, ensure_ascii=False, sort_keys=True, allow_nan=False)
            output.write('\n')
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, destination)
    finally:
        temporary.unlink(missing_ok=True)
    return destination


@contextmanager
def execution_scope(run_id, job_id, trace, *, receipt_root=None, on_step=None):
    """One scoped, structured summary; trace snapshot precedes publish_trace()."""
    if type(run_id) is not int or run_id <= 0 or type(job_id) is not int or job_id <= 0:
        raise ValueError('invalid_execution_identity')
    state = {'run_id': run_id, 'job_id': job_id, 'steps': [], 'items': [], 'on_step': on_step}
    token = _ACTIVE.set(state)
    begin_ns = time.perf_counter_ns()
    final = 'OK'
    try:
        yield
    except BaseException:
        final = 'FAILED'
        raise
    finally:
        try:
            if trace is None:
                return
            steps = list(state['steps'])
            summary = {'schema': 'ezstudio.profile-engine-diagnostic.v1',
                       'run_id': run_id, 'job_id': job_id, 'trace_id': trace.trace_id[:12],
                       'status': final, 'at_utc': _utc(),
                       'duration_ms': round((time.perf_counter_ns()-begin_ns)/1e6, 3),
                       'engine_count': len(steps), 'steps': steps,
                       'item_count': len(state['items']), 'items': list(state['items']),
                       'scope': 'observed_engine_calls_only',
                       'remote_archive_status': 'not_yet_attempted'}
            # The trace snapshot is included in GitHub by profile_raw_archive.package.
            for name, write in (
                ('snapshot', lambda: snap('profile.engine.summary', summary)),
                ('local_summary', lambda: _local_summary(trace, summary, receipt_root)),
            ):
                try:
                    write()
                except Exception as exc:
                    # Diagnostics must not convert a successful scientific review
                    # into a failed LAB job; observable, redacted marker only.
                    print('PROFILE_ENGINE_DIAG_ERROR=' + name + ':' + type(exc).__name__, flush=True)
        finally:
            _ACTIVE.reset(token)
