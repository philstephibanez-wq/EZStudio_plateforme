"""PROFILE R2 : inventaire public des assertions musicales finales LAB.

Aucun nom de moteur, score interne ou configuration scientifique n'est transféré.
Les preuves restent candidates tant que l'enregistrement n'est pas vérifié.
"""
from __future__ import annotations

import math
import re
from app.profile_assertions_contract import ITEMS, _value

ID_PATTERN = re.compile(r"^[a-z][a-z0-9_.:-]{0,119}$")
MUSICAL_DIMENSIONS = frozenset(ITEMS)
FORBIDDEN = re.compile(r"(?:^|[_.:-])(?:engine|module|model|score|preset|checkpoint|config|diagnostic|metric|token|path|prompt)(?:$|[_.:-])")
KEYS = {
    'duree': 'duration', 'tempo': 'tempo', 'mesure': 'meter',
    'tonalite': 'key', 'genre': 'genre', 'voix_principale': 'lead_vocal',
    'voix_accompagnement': 'backing_vocals',
    'harmonies_vocales': 'vocal_harmonies', 'choeurs': 'choir',
}


def project_final_assertions(source):
    """None = ancien client ; [] = inventaire public explicitement vide."""
    keys = {'final_assertions', 'final_assertions_schema', 'final_assertions_count'}
    present = keys.intersection(source)
    if not present:
        return None
    if present != keys:
        raise ValueError('r2_final_assertions_incomplete_envelope')
    if source.get('final_assertions_schema') != 'ezstudio.profile-final-assertions.v1':
        raise ValueError('r2_final_assertions_schema_invalid')
    raw = source['final_assertions']
    if not isinstance(raw, list) or type(source.get('final_assertions_count')) is not int or source['final_assertions_count'] != len(raw):
        raise ValueError('r2_final_assertions_cardinality_invalid')
    results, seen = [], set()
    for entry in raw:
        if not isinstance(entry, dict) or set(entry) != {'id', 'dimension', 'value'}:
            raise ValueError('r2_final_assertion_shape_invalid')
        id_, dimension = entry['id'], entry['dimension']
        if not isinstance(id_, str) or not ID_PATTERN.fullmatch(id_) or id_ in seen:
            raise ValueError('r2_final_assertion_id_invalid')
        if not isinstance(dimension, str) or dimension not in MUSICAL_DIMENSIONS or FORBIDDEN.search(id_):
            raise ValueError('r2_final_assertion_dimension_invalid')
        if dimension == 'instruments':
            if not id_.startswith('instruments:') or not id_.split(':', 1)[1].isdigit():
                raise ValueError('r2_instrument_id_invalid')
        elif id_ != dimension and not id_.startswith(dimension + ':'):
            raise ValueError('r2_final_assertion_id_dimension_mismatch')
        value = _value(entry['value'])
        if value is None or isinstance(value, (dict, list, bool)):
            raise ValueError('r2_final_assertion_value_invalid')
        if isinstance(value, float) and not math.isfinite(value):
            raise ValueError('r2_final_assertion_value_invalid')
        seen.add(id_)
        key = ('instrument:' + id_.split(':', 1)[1]) if dimension == 'instruments' else KEYS.get(dimension, id_)
        readable_value = f'{value} BPM' if dimension == 'tempo' and isinstance(value, (int, float)) else str(value)
        results.append({'id': id_, 'dimension': dimension, 'value': value,
                        'search_key': key, 'search_value': readable_value})
    return results


def build_final_reviews(finals, investigation):
    """Une entrée par item LAB ; toutes les conclusions restent conservatrices."""
    found = {row.get('key'): row for row in investigation.get('items', []) if isinstance(row, dict)}
    results = []
    for item in finals:
        row = found.get(item['search_key']) or {}
        candidate = row.get('evidence_review') or {}
        results.append({
            'id': item['id'], 'dimension': item['dimension'], 'lab_value': item['value'],
            'verdict': 'non_trouve', 'confidence_pct': None, 'calibration_id': None,
            'reference_value': None, 'evidence': [],
            'reason': candidate.get('reason') or 'Enregistrement exact non confirmé par une preuve indépendante',
            'technical_status': row.get('status', 'not_searched'),
        })
    return results


def validate_final_reviews(finals, result):
    rows = result.get('final_reviews')
    if not isinstance(rows, list) or len(rows) != len(finals):
        raise ValueError('r2_final_reviews_cardinality_invalid')
    for expected, actual in zip(finals, rows):
        if (not isinstance(actual, dict) or actual.get('id') != expected['id']
                or actual.get('dimension') != expected['dimension']
                or actual.get('lab_value') != expected['value']):
            raise ValueError('r2_final_review_identity_invalid')
        if actual.get('verdict') != 'non_trouve' or actual.get('evidence') != [] or actual.get('confidence_pct') is not None:
            raise ValueError('r2_unattested_verdict_forbidden')
    return True
