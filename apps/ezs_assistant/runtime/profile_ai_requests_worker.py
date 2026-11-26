from __future__ import annotations
CANONICAL_EXECUTION_PATH = "v1"
"""LAB-triggered PROFILE documentary review on MAIN, using the existing LAB FIFO."""
import argparse
import hashlib
import json
import os
import sys
import time
import urllib.request
import urllib.error
import traceback
from contextlib import nullcontext
from pathlib import Path
from datetime import datetime, timezone
import subprocess

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT/'app'))
import main as assistant_main
from profile_review import normalize_track_identity
from profile_trace import Trace, activate, enabled, emit, snap
from profile_report import publish_trace
from profile_publication_receipt import publish_with_receipt
from profile_engine_diagnostic import call_engine, execution_scope, record_items
from documentary_engine_r9 import QUERIES as R8_ITEMS
# Domain steps are owned by LAB; no foreign SQLite access or direct Orchestrator call.
CHECKPOINT_PHASES = {
    'identity_resolver': 'identity',
    'recording_duration': 'identity',
    'public_source_research': 'discovery',
    'review_payload_builder': 'evidence',
    'independent_verifier': 'verification',
    'assertion_web_investigator': 'evidence',
    'local_ai_document_review': 'evidence',
    'documentary_verdict_merger': 'verification',
    'documentary_payload_enricher': 'persistence',
    'ai_draft_payload_enricher': 'persistence',
    'lab_revision_submit': 'persistence',
}
CHECKPOINT_PHASES.update({'r8_structured_sources':'discovery', 'r8_archive':'persistence', **{'r8_item_'+k:'evidence' for k in R8_ITEMS}})
_CURRENT_STEPS = []

def _report_start(job_id, step):
    engine = str(step.get('engine') or '').strip()
    if engine not in CHECKPOINT_PHASES:
        return
    # Separate event key: checkpoint storage is immutable per key.
    try:
        api(PATH + '/' + str(job_id) + '/checkpoints', {
            'schema': 'ezstudio.assistant-checkpoint.v1',
            'event_key': 'engine.start.' + engine,
            'phase': CHECKPOINT_PHASES[engine],
            'outcome': 'started', 'detail': {},
        })
    except Exception as exc:
        # Instrumentation must not change the expertise verdict.
        print('PROFILE_START_CHECKPOINT_ERROR=' + type(exc).__name__, flush=True)

def _report_step(job_id, step):
    engine = str(step.get('engine') or '').strip()
    state = str(step.get('status') or '').strip().upper()
    _CURRENT_STEPS.append({
        'engine': engine,
        'status': step.get('status'),
        'duration_ms': step.get('duration_ms'),
        # Only an engine-attested physical device; never infer GPU from system availability.
        'device': step.get('device'),
    })
    if engine not in CHECKPOINT_PHASES:
        raise RuntimeError('assistant_unknown_checkpoint_engine:' + engine[:90])
    if state not in ('OK', 'FAILED', 'ERROR', 'BLOCKED'):
        raise RuntimeError('assistant_unknown_checkpoint_status:' + state[:40])
    envelope = {
        'schema': 'ezstudio.assistant-checkpoint.v1',
        'event_key': 'engine.' + engine,
        'phase': CHECKPOINT_PHASES[engine],
        'outcome': 'ok' if state == 'OK' else ('blocked' if state == 'BLOCKED' else 'failed'),
        'detail': {'device': step['device']} if isinstance(step.get('device'), str) and step.get('device') else {},
    }
    # An HTTP rejection must be observable, not silently lost.
    try:
        api(PATH + '/' + str(job_id) + '/checkpoints', envelope)
    except Exception as exc:
        print('PROFILE_CHECKPOINT_REST_ERROR=' + type(exc).__name__, flush=True)
        raise

WORKER_START_UTC = datetime.now(timezone.utc).isoformat()
WORKER_START_PID = os.getpid()
def _startup_code_provenance():
    root = Path(__file__).resolve().parents[1]
    sources = [root / 'runtime' / 'profile_ai_requests_worker.py',
               root / 'app' / 'documentary_engine_r9.py',
               root / 'app' / 'documentary_profile.py',
               root / 'app' / 'rules.json',
               root / 'app' / 'profile_report.py']
    digests = {}
    for file in sources:
        try:
            digests[str(file.relative_to(root)).replace('\\', '/')] = hashlib.sha256(file.read_bytes()).hexdigest()
        except OSError:
            digests[str(file.relative_to(root)).replace('\\', '/')] = None
    try:
        p = subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=str(root), capture_output=True,
                           text=True, timeout=5, check=False)
        commit = p.stdout.strip() if p.returncode == 0 else None
    except (OSError, subprocess.TimeoutExpired):
        commit = None
    return {'process_started_at_utc': WORKER_START_UTC, 'pid': WORKER_START_PID,
            'worker_source_sha256_at_start': digests, 'git_commit_at_start': commit,
            'capture_semantics': 'startup_disk_bytes_not_loaded_module_attestation'}

STARTUP_PROVENANCE = _startup_code_provenance()


def _expertise_engine_provenance():
    """Version from loaded Python bytecode plus non-authoritative disk digests.

    This is not a scientific accuracy claim, nor a historical version inference.
    It fingerprints the executable entry points used by this worker process.
    """
    import marshal
    from documentary_engine_r9 import run, discover, structured, text_facts, summarize, draft
    functions = {'r8_run':run,'r8_discover':discover,'r8_structured':structured,'r8_text_facts':text_facts,'r8_summarize':summarize,'r8_draft':draft}
    loaded = {name: hashlib.sha256(marshal.dumps(fn.__code__)).hexdigest()
              for name, fn in sorted(functions.items())}
    fingerprint = hashlib.sha256(json.dumps(loaded, sort_keys=True, separators=(',', ':')).encode('ascii')).hexdigest()
    return {
        'schema': 'ezs.profile.expertise-engine-provenance.v1',
        'engine_id': 'documentary-r9',
        'engine_version': 'code-sha256:' + fingerprint,
        'loaded_entrypoint_sha256': loaded,
        'worker_startup': STARTUP_PROVENANCE,
        'capture_semantics': 'loaded_entrypoint_bytecode; dependencies/config not fully attested',
    }

BASE = os.environ.get('EZSTUDIOLAB_ASSISTANT_BASE_URL', 'http://127.0.0.1:8701').rstrip('/')

def _load_lab_token():
    """Reuse LAB's canonical private token without writing or printing it."""
    token = (os.environ.get('EZSTUDIOLAB_ASSISTANT_TOKEN')
             or os.environ.get('EZSTUDIO_ANALYSIS_WORKER_TOKEN') or '').strip()
    if token:
        return token
    root = Path(os.environ.get('EZSTUDIO_LAB_ROOT', str(Path(__file__).resolve().parents[2] / 'ezs_lab')))
    local = root / '.env.local'
    if not local.is_file():
        raise RuntimeError('lab_token_file_unavailable')
    for line in local.read_text(encoding='utf-8-sig').splitlines():
        if line.startswith('EZSTUDIO_ANALYSIS_WORKER_TOKEN='):
            token = line.split('=', 1)[1].strip().strip('"').strip("'")
            if token:
                return token
    raise RuntimeError('lab_token_unavailable')

TOKEN = _load_lab_token()
PATH = '/internal/analysis/desktop/profile/ai-requests'

def api(path, data=None):
    if not TOKEN:
        raise RuntimeError('token_missing')
    headers = {'X-EZScore-Analysis-Token': TOKEN, 'Accept': 'application/json'}
    if data is not None:
        headers['Content-Type'] = 'application/json'
    req = urllib.request.Request(
        BASE + path,
        data=None if data is None else json.dumps(data).encode('utf-8'),
        headers=headers,
        method='GET' if data is None else 'POST',
    )
    begin = time.perf_counter_ns()
    emit('lab.http.request', {'method': req.get_method(), 'path': path, 'authorization': 'redacted'})
    try:
        with urllib.request.urlopen(req, timeout=25) as res:
            raw = res.read()
            emit('lab.http.response', {'status': res.status, 'headers': dict(res.headers), 'raw_body': raw.decode('utf-8', 'replace')}, start_ns=begin)
            return json.loads(raw)
    except urllib.error.HTTPError as exc:
        detail = exc.read().decode('utf-8', 'replace')
        emit('lab.http.error', {'status': exc.code, 'body': detail, 'url': BASE + path}, start_ns=begin)
        raise
    except Exception as exc:
        emit('lab.http.error', {'type': type(exc).__name__, 'message': str(exc), 'url': BASE + path}, start_ns=begin)
        raise

class ExpertiseCancelled(Exception):
    """LAB has recorded cancellation for this exact Assistant attempt."""


def assert_running(run_id, job_id):
    reply = api(PATH + '/' + str(run_id) + '/state?job_id=' + str(job_id))
    state = reply.get('request') or {}
    if state.get('job_id') != job_id or state.get('run_id') != run_id:
        raise RuntimeError('expertise_job_identity_mismatch')
    if state.get('status') in ('cancelling', 'cancelled'):
        raise ExpertiseCancelled('expertise_cancel_requested')
    if state.get('status') != 'running':
        raise RuntimeError('expertise_job_no_longer_running')


def process(run_id, client, check_cancel=None):
    import uuid
    from documentary_engine_r9 import run, draft, identity_only
    _CURRENT_STEPS.clear()
    execution_id = uuid.uuid4().hex
    check_cancel = check_cancel or (lambda: None)
    check_cancel()
    row = api('/internal/analysis/desktop/profile/reviews/run/' + str(run_id))['item']
    ident = identity_only(row.get('normalized_identity') or normalize_track_identity(row))
    del row
    if not ident.get('title'):
        raise RuntimeError('titre_musical_absent')
    result = run(ident, ROOT, run_id, execution_id, check_cancel, execute=call_engine)
    for key, item in result['profile']['provisional']['items'].items():
        record_items('r8_item_'+key,[{'key':key,'status':item['status']}])
    snap('profile.r9.documentary', result)
    check_cancel()
    payload = draft(run_id, execution_id, result, _CURRENT_STEPS)
    snap('lab.submit.request', payload)
    submitted = call_engine('lab_revision_submit', client.submit_profile_review, payload)
    snap('lab.submit.response', submitted)
    revision = (submitted.get('revision') or {}).get('id')
    if not submitted.get('ok') or type(revision) is not int or revision < 1:
        raise RuntimeError('revision_non_confirmee')
    return {'run_id':run_id, 'revision':revision, 'ai_status':'completed', 'review_status':'draft',
            'scored_count':0, 'non_trouve_count':0, 'assertion_count':0,
            'documentary_status':result['profile']['status'],
            'documentary_counts':result['profile']['counts']}

def once(client, target_job_id=None, target_run_id=None):
    print('E2E_ASSISTANT_ONCE_R2', 'global_job_id=' + str(os.environ.get('ORCHESTRATOR_JOB_ID', 'missing')), 'local_job_id=' + str(target_job_id), 'run_id=' + str(target_run_id), flush=True)
    # Worker cannot choose its own work; exact GO comes from Orchestrator.
    if not target_job_id or not target_run_id:
        raise RuntimeError('orchestrator_go_required')
    orch_id = int(os.environ.get('ORCHESTRATOR_JOB_ID', '0') or '0')
    if orch_id <= 0:
        raise RuntimeError('orchestrator_job_id_required')
    state = (api(PATH + '/' + str(target_run_id) + '/state?job_id=' + str(target_job_id)).get('request') or {})
    if (state.get('job_id') != target_job_id or state.get('run_id') != target_run_id or state.get('status') != 'queued'):
        raise RuntimeError('orchestrator_go_target_mismatch')
    # Keep the R2A GO fence, but obtain the exact LAB record tied to this global job.
    global_job_id = orch_id
    target_path = (PATH + '?job_id=' + str(target_job_id) + '&run_id=' + str(target_run_id)
                   + '&orchestrator_job_id=' + str(orch_id))
    requests = api(target_path)['requests']
    if not isinstance(requests, list) or len(requests) != 1:
        raise RuntimeError('one_shot_global_target_not_queued')
    if requests:
        print('LAB_PROFILE_IA_PENDING=' + str(len(requests)), flush=True)
    for item in requests:
        rid = int(item['run_id'])
        job_id = int(item['job_id'])
        claim = api(PATH + '/' + str(rid) + '/claim', {'job_id': job_id, 'orchestrator_job_id': orch_id})
        if claim.get('claimed') is not True:
            if target_job_id is not None:
                raise RuntimeError('one_shot_claim_refused')
            continue
        status = 'completed'
        message = 'Révision IA créée'
        trace = None
        if enabled():
            try:
                trace = Trace(rid)
            except Exception as trace_exc:
                print('PROFILE_TRACE_INIT_ERROR ' + type(trace_exc).__name__ + ':' + str(trace_exc), flush=True)
        outcome = None
        context = activate(trace) if trace else nullcontext()
        with context:
            try:
                emit('job.claimed', {'run_id': rid, 'claim': True})
                snap('worker.startup_provenance', STARTUP_PROVENANCE)
                snap('worker.expertise_engine_provenance', _expertise_engine_provenance())
                with execution_scope(rid, job_id, trace, on_step=lambda step: _report_step(job_id, step), on_start=lambda step: _report_start(job_id, step)):
                    outcome = process(rid, client, check_cancel=lambda: assert_running(rid, job_id))
                assert_running(rid, job_id)
                message = 'Révision IA #' + str(outcome['revision']) + ' enregistrée'
                message += ' — documentaire : ' + str(outcome['documentary_counts']['items_with_evidence']) + '/11 items renseignés, 0/11 certifié'
                print(json.dumps(outcome), flush=True)
            except ExpertiseCancelled:
                status = 'cancelled'
                message = 'expertise_cancel_requested'
                emit('job.cancel_observed', {'run_id': rid, 'job_id': job_id})
            except Exception as exc:
                status = 'error'
                message = type(exc).__name__ + ': ' + str(exc)[:750]
                emit('job.error', {'exception': type(exc).__name__, 'message': str(exc), 'traceback': traceback.format_exc()})
                print(json.dumps({'run_id': rid, 'status': status, 'reason': message}), flush=True)
            finally:
                # Complete all Assistant-owned I/O and reports BEFORE acknowledging
                # a cancellation. LAB may then safely admit the next FIFO job.
                if trace:
                    trace.finish({'status': status, 'message': message})
                    try:
                        published = publish_with_receipt(trace, outcome, publish_trace, job_id=job_id)
                        print('PROFILE_REPORT=' + published, flush=True)
                    except Exception as export_exc:
                        print('PROFILE_REPORT_ERROR=' + type(export_exc).__name__ + ':' + str(export_exc)[:120], flush=True)
                    print('PROFILE_TRACE=' + str(trace.folder), flush=True)
                try:
                    if status != 'cancelled':
                        try:
                            assert_running(rid, job_id)
                        except ExpertiseCancelled:
                            status = 'cancelled'
                            message = 'expertise_cancel_requested'
                    if status == 'cancelled':
                        emit('lab.cancel_ack.request', {'run_id': rid, 'job_id': job_id})
                        response = api(PATH + '/' + str(rid) + '/cancelled', {'job_id': job_id})
                        if response.get('ok') is not True or (response.get('request') or {}).get('status') != 'cancelled':
                            raise RuntimeError('cancellation_not_confirmed')
                    else:
                        emit('lab.finish.request', {'status': status, 'message': message})
                        response = api(PATH + '/' + str(rid) + '/finish', {'job_id': job_id, 'status': status, 'message': message})
                        if response.get('ok') is not True or (response.get('request') or {}).get('status') != status:
                            raise RuntimeError('finish_not_confirmed')
                except Exception as exc:
                    emit('lab.finish.error', {'type': type(exc).__name__, 'message': str(exc), 'traceback': traceback.format_exc()})
                    raise
        if status == 'error':
            raise RuntimeError('expertise_failed_after_finish')
        if target_job_id is not None:
            return 1
    if target_job_id is not None:
        raise RuntimeError('one_shot_target_not_processed')
    return 0

def main():
    print('E2E_ASSISTANT_MAIN_R2', 'global_job_id=' + str(os.environ.get('ORCHESTRATOR_JOB_ID', 'missing')), flush=True)
    parser = argparse.ArgumentParser()
    parser.add_argument('--once', action='store_true')
    parser.add_argument('--job-id', type=int)
    parser.add_argument('--run-id', type=int)
    parser.add_argument('--poll-seconds', type=int, default=8)
    parser.add_argument('--service-stop-file', type=Path, default=None)
    # Signal owned by the existing WinSW/PHP service; only read between jobs.
    args = parser.parse_args()
    # No standalone polling: only Orchestrator may initiate execution.
    if not args.once:
        parser.error('orchestrator_go_required')
    if args.once:
        if not args.job_id or args.job_id < 1 or not args.run_id or args.run_id < 1:
            parser.error('--once requires positive --job-id and --run-id')
    elif args.job_id is not None or args.run_id is not None:
        parser.error('--job-id/--run-id require --once')
    if args.service_stop_file is not None:
        expected_dir = (ROOT / 'runtime' / 'state').resolve()
        requested = args.service_stop_file.resolve()
        if requested.parent != expected_dir or not requested.name.startswith('profile-service-stop-') or requested.suffix != '.signal':
            parser.error('invalid_service_stop_file')
        if args.once:
            parser.error('--service-stop-file incompatible with --once')
    # History and diagnostic traces are never purged as part of a queue execution.
    global TOKEN
    TOKEN = TOKEN or _load_lab_token()
    client = assistant_main._client_from_args(argparse.Namespace(base_url=BASE, token=TOKEN))
    while True:
        if args.service_stop_file is not None and args.service_stop_file.is_file():
            print('SERVICE_STOP_BETWEEN_JOBS', flush=True)
            return 0
        try:
            once(client, target_job_id=args.job_id, target_run_id=args.run_id) if args.once else once(client)
        except Exception as exc:
            if args.once and 'one_shot_target_not_processed' in str(exc):
                while True:
                    st = (api(PATH + '/' + str(args.run_id) + '/state?job_id=' + str(args.job_id)).get('request') or {}).get('status')
                    if st == 'completed':
                        return 0
                    if st in ('error', 'cancelled'):
                        return 1
                    time.sleep(2)
            print('QUEUE_ERROR ' + type(exc).__name__ + ': ' + str(exc), flush=True)
            if args.once:
                return 2
        if args.once:
            return 0
        if args.service_stop_file is not None and args.service_stop_file.is_file():
            print('SERVICE_STOP_AFTER_JOB', flush=True)
            return 0
        # Prompt stop between REST polling intervals, never interrupt a claimed job.
        for _ in range(max(5, args.poll_seconds)):
            if args.service_stop_file is not None and args.service_stop_file.is_file():
                print('SERVICE_STOP_WHILE_IDLE', flush=True)
                return 0
            time.sleep(1)

if __name__ == '__main__':
    raise SystemExit(main())
