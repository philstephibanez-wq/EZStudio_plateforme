"""LAB-triggered PROFILE documentary review on MAIN, using the existing LAB FIFO."""
from __future__ import annotations
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
from profile_auto_review import (
    NetworkBudget, research_public_sources, build_review_payload,
    _run_ai_profile_review, enrich_review_payload_with_ai,
    enrich_review_payload_with_documentary,
)
from ai_provider import build_ai_provider_from_env
from profile_trace import Trace, activate, enabled, emit, snap
from profile_report import publish_trace
from profile_publication_receipt import publish_with_receipt
from profile_engine_diagnostic import call_engine, execution_scope, record_items
from profile_lab_observations import attach_lab_observations
from profile_identity import resolve_missing_artist
from profile_sequential_review import examine_modules
from profile_module_qwen import infer_module
from profile_final_verifier import verify_final_values, attach_documentary_findings
from profile_recording_disambiguation import duration_from_catalog
from profile_web_investigator import research_each_assertion
from profile_web_local_review import review_web_items

WORKER_START_UTC = datetime.now(timezone.utc).isoformat()
WORKER_START_PID = os.getpid()
def _startup_code_provenance():
    root = Path(__file__).resolve().parents[1]
    sources = [root / 'runtime' / 'profile_ai_requests_worker.py',
               root / 'app' / 'profile_auto_review.py',
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

TOKEN = os.environ.get('EZSTUDIOLAB_ASSISTANT_TOKEN', '').strip()
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

def require_completed_ai_review(ai):
    """Accept evidence-based abstentions as draft; reject invalid or failed inferences."""
    if not isinstance(ai, dict) or ai.get('status') != 'completed':
        status = ai.get('status') if isinstance(ai, dict) else 'invalid'
        reason = ai.get('reason') if isinstance(ai, dict) else None
        raise RuntimeError('inference_IA_' + str(status) + ':' + str(reason)[:200])
    dimensions = ai.get('dimensions')
    if not isinstance(dimensions, dict) or not dimensions:
        raise RuntimeError('IA_dimensions_absentes')
    if any(not isinstance(value, dict) or value.get('decision') not in
           ('match', 'mismatch', 'uncertain', 'not_scored') for value in dimensions.values()):
        raise RuntimeError('IA_dimensions_invalides')
    # Zero match/mismatch is a valid abstention, not a transport/model failure.
    # The persisted review remains a draft, never a scientific validation.
    return dimensions

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
    check_cancel = check_cancel or (lambda: None)
    check_cancel()
    row = api('/internal/analysis/desktop/profile/reviews/run/' + str(run_id))['item']
    inventory = api('/internal/analysis/desktop/profile/reviews/run/' + str(run_id) + '/final-assertions')
    if inventory.get('schema') != 'ezstudio.profile-final-assertions.v1' or not isinstance(inventory.get('assertions'), list):
        raise RuntimeError('profile_final_assertions_schema_invalid')
    if inventory.get('run_id') != run_id or inventory.get('count') != len(inventory['assertions']):
        raise RuntimeError('profile_final_assertions_count_mismatch')
    row = {**row, '_public_final_assertions': inventory['assertions']}
    emit('profile.final_assertions.loaded', {'count': inventory['count']})
    snap('lab.profile.row', row)
    ident = row.get('normalized_identity') or normalize_track_identity(row)
    if not ident.get('title'):
        raise RuntimeError('titre_musical_absent')
    if not ident.get('artist'):
        candidate = call_engine("identity_resolver", resolve_missing_artist, ident['title'], build_ai_provider_from_env())
        emit('identity.resolution', {'status': candidate['status'], 'candidate_count': candidate['candidate_count']})
        if candidate['status'] == 'resolved':
            ident = {**ident, 'artist': candidate['artist'], 'method': 'assistant_mb_title_artist_match',
                     'requires_verification': True, 'confidence': None}
            row = {**row, 'artist': candidate['artist'], 'normalized_identity': ident}
        else:
            # Do not invent a performer: public research returns an abstention.
            row = {**row, 'normalized_identity': ident}
    ident = {**ident, 'catalog_duration_ms': call_engine("recording_duration", duration_from_catalog, row)}
    started = time.perf_counter_ns()
    emit('research.begin', {'identity': ident})
    try:
        check_cancel()
        research = call_engine("public_source_research", research_public_sources, ident, NetworkBudget())
    finally:
        emit('research.duration', start_ns=started)
    snap('research.result', research)
    started = time.perf_counter_ns()
    check_cancel()
    payload = call_engine("review_payload_builder", build_review_payload, row, research, min_confidence=.75)
    emit('review.base_payload.duration', start_ns=started)
    snap('review.base_payload', payload)
    started = time.perf_counter_ns()
    # Independent evidence verification. No module results/presets in verifier inputs.
    check_cancel()
    verification = call_engine("independent_verifier", verify_final_values, row, ident, research)
    record_items('independent_verifier', verification.get('assertions') or [])
    snap('profile.final_verification', verification)
    # Research is an integral part of PROFILE, never a hidden opt-in.
    # Missing provider credentials are reported to LAB, never silently skipped.
    check_cancel()
    investigation = call_engine("assertion_web_investigator", research_each_assertion, row, ident, source_research=research)
    snap('profile.web_investigation', investigation)
    emit('web.investigation.summary', {
        'status': investigation.get('status'),
        'reason': investigation.get('reason'),
        'searched': investigation.get('searched'),
        'web_citations': sum(len(i.get('citation_urls') or []) for i in investigation.get('items') or [])
    })
    check_cancel()
    local_web_review = call_engine("local_ai_document_review", review_web_items, investigation, ident)
    verification = call_engine("documentary_verdict_merger", attach_documentary_findings, verification, investigation, local_web_review)
    snap('profile.documentary_comparison', verification)
    record_items('local_ai_document_review', local_web_review.get('items') or [])
    snap('profile.local_web_review', local_web_review)
    emit('web.local_review.summary', {'status': local_web_review.get('status'), 'items': len(local_web_review.get('items') or [])})

    # Retain existing LAB draft transport schema, without claiming AI-generated proof.
    ai = {
        'schema': 'EZStudioLab_assistant.ai-profile-review.v1',
        'status': 'completed',
        'provider': 'independent-evidence-verifier',
        'model': None,
        'confidence': None,
        'summary': 'Vérification indépendante des valeurs musicales finales',
        'dimensions': {
            key: {'decision':'not_scored','confidence':None,
                  'reason':'verification_in_final_assertions'}
            for key in ('genre','instrumentation','lead_vocal','backing_vocals','choir')
        },
        'coverage': {'independent_verification': True}
    }
    payload = call_engine("documentary_payload_enricher", enrich_review_payload_with_documentary, payload, row, research)
    payload = call_engine("ai_draft_payload_enricher", enrich_review_payload_with_ai, payload, ai)
    payload.setdefault('comparison', {})['final_verification'] = verification
    payload.setdefault('consensus', {})['final_verification_summary'] = {
        'bon': sum(x['verdict']=='bon' for x in verification['assertions']),
        'faux': sum(x['verdict']=='faux' for x in verification['assertions']),
        'non_trouve': sum(x['verdict']=='non_trouve' for x in verification['assertions'])
    }
    # Score only independent documentary BON/FAUX, never abstentions.
    verdict_counts = payload['consensus']['final_verification_summary']
    scored_count = verdict_counts['bon'] + verdict_counts['faux']
    emit('profile.verdict_counts', {
        'bon': verdict_counts['bon'],
        'faux': verdict_counts['faux'],
        'non_trouve': verdict_counts['non_trouve'],
        'scored_count': scored_count,
        'assertion_count': len(verification['assertions']),
    })
    # A completed inference is an advisory DRAFT, never independent validation.
    payload['status'] = 'draft'
    payload['reviewer'] = 'assistant-lab-ui-qwen'
    digest = hashlib.sha256(json.dumps(
        {'ai': ai, 'verification': verification,
         'documentary': payload.get('comparison', {}).get('documentary_catalogue_suggestions'),
         'run_id': run_id}, sort_keys=True, ensure_ascii=True
    ).encode('utf-8')).hexdigest()[:20]
    payload['revision_key'] = 'profile-ui-qwen-' + str(run_id) + '-' + digest
    snap('lab.submit.request', payload)
    started = time.perf_counter_ns()
    try:
        check_cancel()
        submitted = call_engine("lab_revision_submit", client.submit_profile_review, payload)
    except Exception as exc:
        emit('lab.submit.error', {'type': type(exc).__name__, 'message': str(exc)}, start_ns=started)
        raise
    emit('lab.submit.duration', start_ns=started)
    snap('lab.submit.response', submitted)
    if not submitted.get('ok') or not isinstance((submitted.get('revision') or {}).get('id'), int) or (submitted.get('revision') or {}).get('id') < 1:
        raise RuntimeError('revision_non_confirmee')
    return {
        'run_id': run_id,
        'revision': (submitted.get('revision') or {}).get('id'),
        'ai_status': 'completed',
        'review_status': 'draft',
        'scored_count': scored_count,
        'non_trouve_count': verdict_counts['non_trouve'],
        'assertion_count': len(verification['assertions']),
    }

def once(client, target_job_id=None, target_run_id=None):
    requests = api(PATH)['requests']
    if target_job_id is not None:
        # Fail closed BEFORE any claim: only one FIFO entry, exact job and run.
        if not isinstance(requests, list) or len(requests) != 1:
            raise RuntimeError('one_shot_exclusive_fifo_head_required')
        first = requests[0]
        if (not isinstance(first, dict) or
                type(first.get('job_id')) is not int or
                type(first.get('run_id')) is not int or
                first['job_id'] != target_job_id or
                first['run_id'] != target_run_id):
            raise RuntimeError('one_shot_fifo_identity_mismatch')
        # The existing per-job LAB claim/state endpoints remain authoritative.
    if requests:
        print('LAB_PROFILE_IA_PENDING=' + str(len(requests)), flush=True)
    for item in requests:
        rid = int(item['run_id'])
        job_id = int(item['job_id'])
        claim = api(PATH + '/' + str(rid) + '/claim', {})
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
                with execution_scope(rid, job_id, trace, on_step=lambda step: api(PATH + '/' + str(job_id) + '/checkpoints', {'event_key': 'engine.' + step['engine'], 'phase': 'engine.' + step['engine'], 'outcome': 'ok' if step['status']=='OK' else step['status'].lower(), 'detail': step})):
                    outcome = process(rid, client, check_cancel=lambda: assert_running(rid, job_id))
                assert_running(rid, job_id)
                message = 'Révision IA #' + str(outcome['revision']) + ' enregistrée'
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
    parser = argparse.ArgumentParser()
    parser.add_argument('--once', action='store_true')
    parser.add_argument('--job-id', type=int)
    parser.add_argument('--run-id', type=int)
    parser.add_argument('--poll-seconds', type=int, default=8)
    parser.add_argument('--service-stop-file', type=Path, default=None)
    # Signal owned by the existing WinSW/PHP service; only read between jobs.
    args = parser.parse_args()
    # One-shot calls MUST be bounded; background polling keeps its previous behavior.
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
