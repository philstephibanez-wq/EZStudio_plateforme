"""PROFILE P0 R1: evidence-set admissibility, without AI-invented provenance.

This module is a fail-closed component of the existing assistant pipeline.
Its policy is data-driven and supplied by an expertise preset snapshot.
It must not change scientific LAB results or infer recording identity.
"""
from __future__ import annotations

from collections import defaultdict
from urllib.parse import urlsplit, urlunsplit
import re
import unicodedata

SCHEMA = 'ezs.assistant.evidence-sets-policy.v1'
DEFAULT_POLICY = {
    'schema': SCHEMA,
    'require_exact_quote': True,
    'require_source_url': True,
    'require_identity_attestation': False,  # Current document classification is NOT an identity attestation.
    'max_quote_chars': 220,
    'min_quote_chars': 6,
}


def policy_from_preset(preset):
    """Use a snapshot of a versioned expertise preset, not a mutable global variable."""
    cfg = (preset or {}).get('evidence_sets', DEFAULT_POLICY)
    if not isinstance(cfg, dict) or cfg.get('schema') != SCHEMA:
        raise ValueError('invalid_evidence_sets_schema')
    unknown = set(cfg) - set(DEFAULT_POLICY)
    if unknown:
        raise ValueError('unknown_evidence_sets_fields')
    result = {**DEFAULT_POLICY, **cfg}
    # The provenance invariant cannot be overridden by a user preset.
    if result['require_exact_quote'] is not True or result['require_source_url'] is not True:
        raise ValueError('non_negotiable_provenance_invariant')
    for key in ('max_quote_chars', 'min_quote_chars'):
        if type(result[key]) is not int or not 1 <= result[key] <= 500:
            raise ValueError('invalid_quote_length')
    if result['min_quote_chars'] > result['max_quote_chars']:
        raise ValueError('invalid_quote_window')
    if type(result['require_identity_attestation']) is not bool:
        raise ValueError('invalid_identity_policy')
    return result


def _norm(text):
    text = unicodedata.normalize('NFKC', str(text or ''))
    return ' '.join(text.casefold().split())


def _clean_url(url):
    if not isinstance(url, str):
        return None
    try:
        p = urlsplit(url.strip())
        if p.scheme != 'https' or not p.hostname or p.username or p.password:
            return None
        return urlunsplit((p.scheme, p.netloc.lower(), p.path or '/', p.query, ''))
    except ValueError:
        return None


def qualified_reference(fact_key, evidence, sets, policy=None):
    """Return a referenced fact only with a real, exact quotation in that source.

    This is admissibility, NOT independent recording-level certification.
    Source-tier classifications do not prove recording identity.
    """
    cfg = policy_from_preset({'evidence_sets': policy} if policy is not None else {})
    if not isinstance(evidence, dict):
        return []
    item = evidence.get(fact_key)
    if not isinstance(item, dict):
        return []
    url = _clean_url(item.get('url'))
    quote = str(item.get('quote') or '').strip()
    if not url or not cfg['min_quote_chars'] <= len(quote) <= cfg['max_quote_chars']:
        return []
    for category in ('E_enc', 'E_tech', 'E_gen'):
        for document in (sets or {}).get(category, []):
            if not isinstance(document, dict) or _clean_url(document.get('url')) != url:
                continue
            if document.get('kind') in ('excluded_noise', 'excluded_homonym'):
                continue
            # The classifier stores an actual fetched document excerpt, not a search snippet.
            excerpt = document.get('excerpt')
            if not isinstance(excerpt, str) or _norm(quote) not in _norm(excerpt):
                continue
            if cfg['require_identity_attestation'] and document.get('recording_identity_verified') is not True:
                return []
            return [{'url': url, 'quote': quote, 'source_kind': category}]
    return []


def evidence_coverage(facts, sets, policy=None):
    """Report independently qualified citations, not fabricated truth verdicts."""
    observed = {}
    for key in sorted((facts or {}).get('evidence') or {}):
        observed[key] = qualified_reference(key, facts['evidence'], sets, policy)
    return {'schema': 'ezs.assistant.evidence-coverage.v1',
            'eligible_fact_count': sum(bool(value) for value in observed.values()),
            'rejected_fact_count': sum(not value for value in observed.values()),
            'facts': observed, 'identity_certified': False,
            'independence_certified': False}
