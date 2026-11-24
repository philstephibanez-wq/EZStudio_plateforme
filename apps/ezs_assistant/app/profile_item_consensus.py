"""Consensus documentaire par item PROFILE — R6E R6.

Pure function; no filesystem, network or certification. Compatible with R4 output.
Percentages = source agreement, never probability of correctness.
Numeric tolerances represent *maximum total spread* within a consensus group:
10 BPM for tempo, 15 seconds for duration, 1 year for an explicitly sourced year.
This complete-link requirement prevents nontransitive chain merging.
"""
from __future__ import annotations

from collections import defaultdict
from math import comb
from urllib.parse import urlsplit
import re
import unicodedata

SKIP = {'E_excl', 'E_excluded'}
EXCLUDED_KINDS = {'excluded_noise', 'excluded_homonym'}
DEFAULT_TOLERANCES = {'tempo_bpm': 10.0, 'duration_sec': 15.0, 'year': 1.0}


def _normalize(value):
    s = unicodedata.normalize('NFKD', str(value or '')).casefold()
    return ' '.join(''.join(c for c in s if not unicodedata.combining(c)).split())


def _numeric(value):
    try:
        return float(str(value).replace(',', '.'))
    except (ValueError, TypeError):
        return None


def _values(item, doc, reference_values):
    """Only values explicitly visible in this document; never infer from LAB."""
    excerpt = str(doc.get('excerpt') or '')[:5000]
    text = _normalize(excerpt)
    if item == 'tempo_bpm':
        hits = re.findall(r'(?<!\d)(\d{2,3}(?:[.,]\d+)?)\s*bpm\b', text)
        hits += re.findall(r'\btempo\s*[:=]?\s*(\d{2,3}(?:[.,]\d+)?)\b', text)
        return sorted({round(x, 2) for v in hits if (x := _numeric(v)) is not None and 20 <= x <= 350})
    if item == 'meter':
        hits = re.findall(r'(?<!\d)([2-9]\s*/\s*(?:2|4|8|16))(?!\d)', text)
        return sorted({v.replace(' ', '') for v in hits})
    if item == 'duration_sec':
        hits = re.findall(r'\b(?:duration|duree|length)\s*[:=]?\s*(\d{1,2}:\d{2})\b', text)
        out = set()
        for hit in hits:
            minutes, seconds = map(int, hit.split(':'))
            if seconds < 60:
                out.add(float(60 * minutes + seconds))
        return sorted(out)
    if item in ('year', 'release_year', 'annee'):
        hits = re.findall(r'\b(?:released?|parution|annee|year)\s*(?:in|on|:|=)?\s*((?:19|20)\d\d)\b', text)
        return sorted({float(x) for x in hits})
    # Other dimensions: the existing documentary extractor proposes candidates;
    # require the candidate's text to be literally present in this document.
    out = []
    for value in reference_values:
        if isinstance(value, bool) or not isinstance(value, (str, int, float)):
            continue
        label = _normalize(value)
        if len(label) >= 3 and re.search(r'(?<!\w)' + re.escape(label) + r'(?!\w)', text):
            out.append(str(value).strip())
    return sorted(set(out))


def _equivalent(item, a, b):
    if item in DEFAULT_TOLERANCES or item in ('release_year', 'annee'):
        x, y = _numeric(a), _numeric(b)
        return x is not None and y is not None and abs(x-y) < 0.00001
    return _normalize(a) == _normalize(b)


def _numeric_windows(eligible, tolerance):
    """All maximal distinct vote sets supported by a numeric range <= tolerance.

    Return unique groups of origin votes. Every candidate uses values explicitly
    present in each supporter. A source may quote several competing candidates.
    No chain/connected-components grouping (e.g. 120,130,140 at tolerance 10).
    """
    observations = sorted((float(v), group) for group, rec in eligible.items()
                          for v in rec['values'] if _numeric(v) is not None)
    found = {}
    for i, (start, _) in enumerate(observations):
        window = [(v, g) for v, g in observations[i:] if v - start <= tolerance + 1e-9]
        if not window:
            continue
        group_ids = frozenset(g for _, g in window)
        if not group_ids:
            continue
        # Retain tightest feasible documented range for identical votes.
        lo, hi = min(v for v, _ in window), max(v for v, _ in window)
        spread = hi - lo
        values = sorted(set(v for v, _ in window))
        candidate = {
            'supporting': group_ids,
            'range': [lo, hi],
            'spread': round(spread, 4),
            'value': round((lo+hi)/2, 4),
            'observed_values': values,
        }
        matches = [j for j, previous in enumerate(found.get(group_ids, []))
                   if abs(candidate['value'] - previous['value']) < tolerance]
        if matches:
            index = matches[0]
            previous = found[group_ids][index]
            if (spread, lo) < (previous['spread'], previous['range'][0]):
                found[group_ids][index] = candidate
        else:
            found.setdefault(group_ids, []).append(candidate)
    return [candidate for variants in found.values() for candidate in variants]


def _classes(item, eligible, tolerance):
    if item in DEFAULT_TOLERANCES or item in ('release_year', 'annee'):
        return _numeric_windows(eligible, tolerance)
    return [{
        'supporting': frozenset(g for g, rec in eligible.items()
                                if any(_equivalent(item, val, v) for v in rec['values'])),
        'value': val,
        'observed_values': [val],
        'range': None,
        'spread': None,
    } for val in sorted({v for r in eligible.values() for v in r['values']}, key=str)]


def consensus_for_item(item: str, sets: dict, *, candidate_values=(), tempo_tolerance=10.0,
                       duration_tolerance=15.0, year_tolerance=1.0):
    """Documentary agreement per item, with complete-link musical tolerances.

    Independent origins have one vote per *candidate group*, even when several
    URLs repeat the same fact. Percentages use only groups with usable values;
    coverage separately accounts for consulted groups with no usable value.
    """
    if not isinstance(item, str) or not item or not isinstance(sets, dict):
        raise ValueError('invalid_consensus_arguments')
    tolerances = {'tempo_bpm': tempo_tolerance, 'duration_sec': duration_tolerance,
                  'year': year_tolerance, 'release_year': year_tolerance,
                  'annee': year_tolerance}
    for value in tolerances.values():
        if isinstance(value, bool) or not isinstance(value, (int, float)) or not 0 <= value <= 60:
            raise ValueError('invalid_musical_tolerance')
    groups = defaultdict(lambda: {'urls': set(), 'values': set(), 'sets': set()})
    seen = set()
    for category, sources in sets.items():
        if category in SKIP or not str(category).startswith('E_') or not isinstance(sources, list):
            continue
        for doc in sources:
            if not isinstance(doc, dict) or doc.get('kind') in EXCLUDED_KINDS:
                continue
            url = doc.get('url')
            if not isinstance(url, str):
                continue
            try:
                parsed = urlsplit(url)
            except ValueError:
                continue
            if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password:
                continue
            if not isinstance(doc.get('excerpt'), str):
                continue
            url_key = url.split('#')[0]
            if url_key in seen:
                continue
            seen.add(url_key)
            origin = str(doc.get('origin_domain') or parsed.hostname).lower().removeprefix('www.').strip()
            if not origin:
                continue
            rec = groups[origin]
            rec['urls'].add(url_key)
            rec['sets'].add(category)
            rec['values'].update(_values(item, doc, candidate_values))
    consulted = len(groups)
    eligible = {group: record for group, record in groups.items() if record['values']}
    voted = len(eligible)
    candidates = []
    for group_candidate in _classes(item, eligible, tolerances.get(item, 0.0)):
        supporting = group_candidate['supporting']
        if not supporting:
            continue
        candidates.append({
            'value': group_candidate['value'],
            'observed_values': group_candidate['observed_values'],
            'compatible_range': group_candidate['range'],
            'value_spread': group_candidate['spread'],
            'applied_tolerance': tolerances.get(item, 0.0),
            'support_count': len(supporting),
            'compatible_support_count': len(supporting),
            'eligible_count': voted, 'consulted_count': consulted,
            'agreement_pct': round(100 * len(supporting) / voted, 2) if voted else None,
            'coverage_pct': round(100 * voted / consulted, 2) if consulted else None,
            'independent_groups': sorted(supporting),
            'source_sets': sorted({cat for g in supporting for cat in eligible[g]['sets']}),
            'urls': sorted({u for g in supporting for u in eligible[g]['urls']}),
            'intersections_by_order': {str(order): comb(len(supporting), order)
                                       for order in range(2, len(supporting)+1)},
        })
    candidates.sort(key=lambda c: (-c['support_count'],
                                   c['value_spread'] if c['value_spread'] is not None else 0,
                                   -len(c['source_sets']), str(c['value'])))
    top = candidates[0]['support_count'] if candidates else 0
    leaders = [c['value'] for c in candidates if c['support_count'] == top]
    return {
        'schema': 'ezs.assistant.item-consensus.v1', 'item': item,
        'consulted_groups': consulted, 'eligible_groups': voted,
        'coverage_pct': round(100 * voted/consulted, 2) if consulted else None,
        'candidates': candidates,
        'leading_values': leaders,
        'ambiguous_leader': len(leaders) > 1,
        'has_global_intersection': bool(candidates and top == voted and voted >= 2),
        'group_values': {g: {'values': sorted(r['values'], key=str), 'source_sets': sorted(r['sets'])}
                         for g, r in sorted(eligible.items())},
        'unresolved_independence': True,
        'calibrated_accuracy': False,
        'recording_identity_certified': False,
        'scientific_validation': False,
    }
