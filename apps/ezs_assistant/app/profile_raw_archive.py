"""Append-only, redacted, verifiable PROFILE trace archive for EZStudio_Run_Reports.

This is a diagnostic transport, not a music verifier or scientific preset engine.
"""
from __future__ import annotations

import base64
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
from urllib.parse import urlsplit, urlunsplit, parse_qsl, urlencode
from profile_archive_contract import validate_manifest

DEST = 'philstephibanez-wq/EZStudio_Run_Reports'
CHUNK_BYTES = 45000
SECRET_KEY = re.compile(r'(?:token|secret|password|passwd|authorization|cookie|credential|private[_-]?key|api[_-]?key|client[_-]?secret|access[_-]?key)', re.I)
AUDIO_KEY = re.compile(r'(?:^audio$|raw[_-]?audio|audio[_-]?(?:base64|data|blob|bytes|samples)|(?:wav|mp3|stem)[_-]?(?:bytes|data|base64)|waveform[_-]?bytes)', re.I)
BEARER = re.compile(r'(?i)(\bBearer\s+)[A-Za-z0-9._~+/-]+')
CRED = re.compile(r'(?i)((?:token|api_key|password|secret|authorization|cookie)\s*[=:]\s*)[^\s,;]+')
PERSONAL_PATH = re.compile(r'(?i)(?:[A-Z]:\\Users\\[^\\\s]+|/home/[^/\s]+|/Users/[^/\s]+)')
SENSITIVE_QUERY = re.compile(r'(?i)(?:token|key|secret|password|auth|session|signature|credential)')


def scrub(value):
    """Redact known private fields, not musical observations; never silently truncate."""
    if isinstance(value, dict):
        result = {}
        for key, item in value.items():
            name = str(key)
            if SECRET_KEY.search(name) or AUDIO_KEY.search(name):
                result[name] = '[REDACTED:SENSITIVE_FIELD]'
            else:
                result[name] = scrub(item)
        return result
    if isinstance(value, list):
        return [scrub(item) for item in value]
    if isinstance(value, str):
        value = BEARER.sub(r'\1[REDACTED]', value)
        if not re.fullmatch(r'https?://[^\s<>"\']+', value):
            value = CRED.sub(r'\1[REDACTED]', value)
        value = PERSONAL_PATH.sub('[REDACTED:PERSONAL_PATH]', value)
        # Keep URL paths (needed as evidence) but redact credential-like query arguments.
        if '://' in value:
            match = re.fullmatch(r'https?://[^\s<>"\']+', value)
            if match:
                u = urlsplit(value)
                if u.username or u.password:
                    value = '[REDACTED:URL_USERINFO]'
                else:
                    q = [(k, '[REDACTED]' if SENSITIVE_QUERY.search(k) else v)
                         for k,v in parse_qsl(u.query, keep_blank_values=True)]
                    value = urlunsplit((u.scheme, u.netloc, u.path, urlencode(q), u.fragment))
        return value
    if value is None or isinstance(value, (bool, int, float)):
        return value
    return str(value)


def _utf8_parts(raw, limit=CHUNK_BYTES):
    """Preserve bytes and UTF-8 decoding for each independently viewed chunk."""
    position = 0
    while position < len(raw):
        end = min(position + limit, len(raw))
        while end > position:
            try:
                raw[position:end].decode('utf-8', 'strict')
                break
            except UnicodeDecodeError as exc:
                # If the source is invalid UTF-8, refuse instead of repairing it.
                if exc.reason != 'unexpected end of data':
                    raise ValueError('archive_invalid_utf8') from exc
                end -= 1
        if end == position:
            raise ValueError('archive_utf8_chunk_too_small')
        yield raw[position:end]
        position = end


def _json_line(value):
    return (json.dumps(scrub(value), ensure_ascii=False, sort_keys=True,
                       separators=(',', ':'), allow_nan=False) + '\n').encode('utf-8')




# Documentary stage index only. No musical inference, no verdict calculation.
# The event timeline and snapshots remain the original redacted evidence.
_PROFILE_STAGE_EVENTS = {
    'lab_receipt': ('job.claimed', 'profile.final_assertions.loaded', 'lab.profile.row'),
    'recording_identity': ('identity.resolution', 'research.begin'),
    'public_research': ('research.begin', 'research.duration', 'research.result'),
    'document_reading': ('profile.web_investigation', 'web.investigation.summary'),
    'document_crosscheck': ('profile.local_web_review', 'profile.documentary_comparison',
                            'web.local_review.summary'),
    'musical_verdicts': ('profile.final_verification', 'profile.verdict_counts'),
    'lab_revision_submission': ('lab.submit.request', 'lab.submit.response', 'lab.submit.error'),
    'reports_publication': (),
}


def _stage_evidence_index(records, run_id, trace_id):
    """Index observed raw events by sequence, without translating outcomes.

    No event in the published timeline attests that its own GitHub upload or
    LAB final acknowledgment succeeded. These steps remain explicitly unknown.
    """
    stages = []
    for stage, names in _PROFILE_STAGE_EVENTS.items():
        evidence = [dict(record) for record in records if record['event'] in names]
        stages.append({
            'stage': stage,
            'capture': {
                'status': 'outside_capture_boundary' if stage == 'reports_publication'
                          else ('observed' if evidence else 'not_observed'),
                'semantics': 'event_presence_only_not_success',
            },
            'raw_evidence_references': evidence,
            'interpretation': {
                'status': 'not_evaluated',
                'reason': 'diagnostic_never_infers_musical_or_operational_success',
            },
        })
    return {
        'schema': 'ezstudio.assistant-profile-stage-evidence.v1',
        'run_id': run_id,
        'trace_id': trace_id,
        'source': 'redacted_timeline_jsonl',
        'capture_boundary': 'trace.finish_before_reports_upload_and_lab_finish_ack',
        'raw_and_interpretation_separate': True,
        'stages': stages,
    }


def package(trace, outcome):
    """Build stable bytes from recorded events. No new scientific calculations."""
    if type(trace.run_id) is not int or trace.run_id < 1 or not re.fullmatch('[0-9a-f]{32}', trace.trace_id):
        raise ValueError('archive_invalid_trace_identity')
    folder = Path(trace.folder)
    path = Path(trace.file)
    if path.is_symlink() or path.parent.resolve() != folder.resolve():
        raise ValueError('archive_unsafe_trace_path')
    prefix = f'diagnostics/assistant/profile/run-{trace.run_id:06d}/trace-{trace.trace_id[:12]}'
    segments = []
    buffer = bytearray()
    total_events = 0
    previous = 0
    timeline_digest = hashlib.sha256()
    last_event_utc = None
    preset_evidence = []
    source_sets = {}
    stage_records = []
    def flush():
        if buffer:
            name = f'timeline-{len(segments):04d}.jsonl'
            raw = bytes(buffer)
            segments.append((name, raw))
            buffer.clear()
    with path.open('rb') as handle:
        for raw_line in handle:
            if not raw_line.strip():
                raise ValueError('archive_empty_timeline_record')
            try:
                record = json.loads(raw_line.decode('utf-8'))
            except (UnicodeDecodeError, ValueError) as exc:
                raise ValueError('archive_invalid_timeline_record') from exc
            if not isinstance(record, dict) or record.get('run_id') != trace.run_id or record.get('trace_id') != trace.trace_id:
                raise ValueError('archive_timeline_identity_mismatch')
            seq = record.get('seq')
            if type(seq) is not int or seq != previous + 1:
                raise ValueError('archive_timeline_sequence_gap')
            previous = seq
            last_event_utc = record.get('at_utc')
            stage_records.append({
                'seq': seq, 'event': record.get('event'),
                'at_utc': record.get('at_utc'),
                'duration_us': record.get('duration_us'),
            })
            # Attest provenance only when explicitly present in a captured
            # runtime event; NEVER infer the loaded preset from filesystem.
            data = record.get('data')
            if record.get('event') in ('assistant.preset.loaded', 'preset.loaded', 'assistant_preset.loaded') and isinstance(data, dict):
                preset_evidence.append({'seq':seq, 'event':record['event'], 'data':scrub(data)})
            # Sets are a diagnostic INDEX of actually consulted documents,
            # NOT a statistical probability or an independent musical verdict.
            if record.get('event') == 'profile.web_investigation' and isinstance(data, dict):
                for item in data.get('items', []):
                    if not isinstance(item, dict):
                        continue
                    key = item.get('key')
                    if not isinstance(key, str) or not key:
                        continue
                    entry = source_sets.setdefault(key, {'consulted':set(), 'read':set(), 'identity_attested':set()})
                    for doc in item.get('documents', []):
                        if not isinstance(doc, dict) or not isinstance(doc.get('url'), str):
                            continue
                        url = scrub(doc['url'])
                        if not isinstance(url, str):
                            continue
                        entry['consulted'].add(url)
                        if doc.get('status') == 'read':
                            entry['read'].add(url)
                        if doc.get('recording_identity_verified') is True:
                            entry['identity_attested'].add(url)
            line = _json_line(record)
            timeline_digest.update(line)
            # Chunk without truncation and only at UTF-8 codepoint boundaries.
            for piece in _utf8_parts(line):
                room = CHUNK_BYTES - len(buffer)
                if len(piece) > room:
                    flush()
                buffer.extend(piece)
            total_events += 1
    flush()
    if not segments:
        raise ValueError('archive_empty_trace')
    # Save each of the per-stage snapshots too, not just the timeline.
    snapshot_files = []
    for file in sorted(folder.glob('*.json')):
        if file.is_symlink() or file.parent.resolve() != folder.resolve():
            raise ValueError('archive_unsafe_snapshot')
        if not re.fullmatch(r'[0-9]{4}_[A-Za-z0-9_.-]+\.json', file.name):
            raise ValueError('archive_invalid_snapshot_name')
        try:
            obj = json.loads(file.read_text(encoding='utf-8'))
        except (UnicodeError, ValueError) as exc:
            raise ValueError('archive_invalid_snapshot_json') from exc
        raw = (json.dumps(scrub(obj), ensure_ascii=False, sort_keys=True,
                          indent=2, allow_nan=False) + '\n').encode('utf-8')
        # Snapshots may be large. Chunk as byte blobs with explicit file metadata.
        snapshot_files.append((file.name, raw))
    # A derived index supports comparing Assistant presets later. It never
    # substitutes for original trace events, nor claims a verdict/confidence.
    source_index = {'schema':'ezstudio.assistant-source-sets.v1',
                    'producer':'EZStudioLab_assistant',
                    'derived_from':'profile.web_investigation trace event',
                    'semantics':'membership_only_no_independence_or_verdict',
                    'items':{key:{name:sorted(urls) for name, urls in entry.items()}
                             for key, entry in sorted(source_sets.items())}}
    preset_index = {'schema':'ezstudio.assistant-preset-evidence.v1',
                    'status':'attested_by_runtime_event' if preset_evidence else 'not_attested',
                    'observations':preset_evidence,
                    'note':'No automatic preset promotion, no inferred loaded configuration'}
    stage_index = _stage_evidence_index(stage_records, trace.run_id, trace.trace_id)
    entries = []
    files = list(segments)
    for name, value in [('source-sets.json',source_index),('assistant-preset-evidence.json',preset_index),
                        ('stage-observations.json',stage_index)]:
        raw = (json.dumps(scrub(value),ensure_ascii=False,sort_keys=True,indent=2,allow_nan=False)+'\n').encode('utf-8')
        for index,part in enumerate(_utf8_parts(raw)):
            files.append((name+f'.part-{index:04d}',part))

    for name, raw in snapshot_files:
        # Preserve the full snapshot as a content-addressed multi-part artifact.
        for index,part in enumerate(_utf8_parts(raw)):
            files.append((f'snapshots/{name}.part-{index:04d}', part))
    seen=set()
    for name, raw in files:
        if name in seen:
            raise ValueError('archive_duplicate_name')
        seen.add(name)
        entries.append({'path': name, 'size_bytes':len(raw), 'sha256':hashlib.sha256(raw).hexdigest()})
    manifest = {
        'schema':'ezstudio.assistant-profile-raw-archive.v1',
        'producer':'EZStudioLab_assistant',
        'run_id':trace.run_id,
        'trace_id':trace.trace_id,
        'revision_id':outcome.get('revision') if isinstance(outcome,dict) and type(outcome.get('revision')) is int else None,
        'source':'trace.timeline_and_snapshots',
        'capture_boundary':'trace.finish; subsequent LAB finish/cancel ack not included',
        'redaction':'secret key/pattern and personal path filtering; never publish restricted audio',
        'timeline_events':total_events,
        'timeline_redacted_sha256':timeline_digest.hexdigest(),
        'snapshot_count':len(snapshot_files),
        'artifact_count':len(files),
        'assistant_preset_provenance_status':preset_index['status'],
        'source_sets_status':'derived_index_not_independent_evidence',
        'artifacts':entries,
        'last_event_at_utc':last_event_utc,
    }
    return prefix, files, manifest


def _put_and_verify(path, raw, gh):
    endpoint=f'repos/{DEST}/contents/{path}'
    try:
        prev=gh(endpoint)
    except RuntimeError as exc:
        # An authentication or permission error must never be treated as 404.
        if 'repository_or_path_not_found' not in str(exc):
            raise
        prev=None
    if prev is not None:
        existing=base64.b64decode(prev.get('content',''))
        if existing == raw:
            return 'unchanged'
        raise RuntimeError('archive_immutable_path_conflict')
    data={'message':'archive: assistant profile raw artifact (redacted)',
          'branch':'main','content':base64.b64encode(raw).decode('ascii')}
    gh(endpoint,payload=data)
    verified=gh(endpoint)
    if hashlib.sha256(base64.b64decode(verified.get('content',''))).digest()!=hashlib.sha256(raw).digest():
        raise RuntimeError('archive_roundtrip_sha256_mismatch')
    return 'published'


def publish(trace, outcome, gh):
    prefix, files, manifest=package(trace, outcome)
    # Validate every redacted artifact before the first remote upload.
    validate_manifest(manifest, dict(files))
    for name, raw in files:
        _put_and_verify(prefix+'/'+name,raw,gh)
    # Manifest LAST: incomplete upload never looks complete.
    raw=(json.dumps(manifest,sort_keys=True,indent=2,ensure_ascii=False)+'\n').encode('utf-8')
    result=_put_and_verify(prefix+'/manifest.json',raw,gh)
    return 'raw_published' if result=='published' else 'raw_unchanged'
