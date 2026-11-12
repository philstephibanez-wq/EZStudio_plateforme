"""Pure, read-only integrity contract shared by Assistant and Reports.

This module checks bytes and provenance, never interprets musical assertions.
"""
from __future__ import annotations

import hashlib
import re
from collections.abc import Mapping

SHA256 = re.compile(r"[0-9a-f]{64}\Z")
TRACE = re.compile(r"[0-9a-f]{32}\Z")
PART = re.compile(r"(?:timeline-\d{4}\.jsonl|(?:source-sets|assistant-preset-evidence|stage-observations)\.json\.part-\d{4}|snapshots/[0-9]{4}_[A-Za-z0-9_.-]+\.json\.part-\d{4})\Z")


def validate_manifest(manifest: dict, artifacts: Mapping[str, bytes]) -> int:
    """Fail closed on missing, modified, duplicated or unlisted artifact bytes.

    Return the verified number of artifacts, without writing any file.
    This is an integrity check, NOT attestation of music or LAB completion.
    """
    if not isinstance(manifest, dict) or manifest.get('schema') != 'ezstudio.assistant-profile-raw-archive.v1':
        raise ValueError('archive_contract_schema_invalid')
    if manifest.get('producer') != 'EZStudioLab_assistant':
        raise ValueError('archive_contract_producer_invalid')
    if type(manifest.get('run_id')) is not int or manifest['run_id'] < 1:
        raise ValueError('archive_contract_run_invalid')
    if not isinstance(manifest.get('trace_id'), str) or not TRACE.fullmatch(manifest['trace_id']):
        raise ValueError('archive_contract_trace_invalid')
    revision = manifest.get('revision_id')
    if revision is not None and (type(revision) is not int or revision < 1):
        raise ValueError('archive_contract_revision_invalid')
    rows = manifest.get('artifacts')
    if not isinstance(rows, list) or not rows or type(manifest.get('artifact_count')) is not int or manifest['artifact_count'] != len(rows):
        raise ValueError('archive_contract_count_mismatch')
    if not isinstance(artifacts, Mapping) or len(artifacts) != len(rows):
        raise ValueError('archive_contract_inventory_mismatch')
    seen = set()
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError('archive_contract_record_invalid')
        name, digest, size = row.get('path'), row.get('sha256'), row.get('size_bytes')
        if not isinstance(name, str) or not PART.fullmatch(name) or name in seen:
            raise ValueError('archive_contract_path_invalid_or_duplicate')
        seen.add(name)
        if not isinstance(digest, str) or not SHA256.fullmatch(digest):
            raise ValueError('archive_contract_digest_invalid')
        if type(size) is not int or size < 1:
            raise ValueError('archive_contract_size_invalid')
        raw = artifacts.get(name)
        if not isinstance(raw, bytes) or len(raw) != size:
            raise ValueError('archive_contract_missing_or_wrong_size')
        if hashlib.sha256(raw).hexdigest() != digest:
            raise ValueError('archive_contract_sha256_mismatch')
    if seen != set(artifacts):
        raise ValueError('archive_contract_unlisted_artifact')
    return len(rows)
