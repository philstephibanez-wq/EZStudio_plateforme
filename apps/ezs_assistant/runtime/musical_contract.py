"""Independent musical assertion contract; supplied evidence is NOT proof of Web retrieval."""
from datetime import date
from urllib.parse import urlsplit

UNKNOWN = {'possible', 'inconclusive', 'indéterminé', 'indetermine', 'not_scored', 'unknown', 'none', ''}
FORBIDDEN = {'engine', 'model', 'preset', 'score', 'clap', 'mert', 'madmom', 'probability', 'weights', 'module', 'pipeline', 'logits', 'raw', 'confidence'}

def _clean_text(value, limit=512):
    if not isinstance(value, str) or not value.strip() or len(value) > limit:
        raise ValueError('invalid non-empty text')
    return value.strip()

def _safe_url(value):
    if not isinstance(value, str) or len(value) > 2048:
        return False
    try:
        u = urlsplit(value)
        return u.scheme == 'https' and bool(u.hostname) and not u.username and not u.password and not u.fragment and u.port in (None, 443) and u.hostname.lower() not in ('localhost', '127.0.0.1')
    except ValueError:
        return False

def normalize(payload):
    """Whitelist field contract; reject scientific provenance even if unknown nested fields are supplied."""
    if not isinstance(payload, dict) or set(payload) != {'recording', 'assertions'}:
        raise ValueError('invalid envelope')
    rec = payload['recording']
    if not isinstance(rec, dict) or set(rec) != {'title', 'artist', 'version', 'track_id'}:
        raise ValueError('invalid recording fields')
    recording = {'title': _clean_text(rec['title']), 'artist': _clean_text(rec['artist']),
                 'version': _clean_text(rec['version']) if rec['version'] is not None else None,
                 'track_id': _clean_text(rec['track_id'])}
    assertions = payload['assertions']
    if not isinstance(assertions, list) or not assertions or len(assertions) > 300:
        raise ValueError('invalid assertion list')
    out, ids = [], set()
    for item in assertions:
        if not isinstance(item, dict) or set(item) != {'id', 'characteristic', 'value'}:
            raise ValueError('invalid assertion fields: provenance forbidden')
        aid, characteristic = _clean_text(item['id'], 100), _clean_text(item['characteristic'], 100)
        if aid in ids or any(term in characteristic.casefold() for term in FORBIDDEN):
            raise ValueError('duplicate identifier or forbidden scientific characteristic')
        ids.add(aid)
        value = item['value']
        if value is not None and not isinstance(value, (str, int, float, bool)):
            raise ValueError('invalid final musical value')
        if isinstance(value, str):
            value = value.strip()
            if len(value) > 512:
                raise ValueError('value too long')
        out.append({'id': aid, 'characteristic': characteristic, 'value': value})
    return {'recording': recording, 'assertions': out}

def verify(payload, evidence_by_id=None, *, reviewed_at=None):
    """Abstain by default: HTTPS links and model output are not independently verified."""
    source = normalize(payload)
    if evidence_by_id is None:
        evidence_by_id = {}
    if not isinstance(evidence_by_id, dict):
        raise ValueError('invalid evidence dictionary')
    current = reviewed_at or date.today().isoformat()
    results = []
    for item in source['assertions']:
        value = item['value']
        row = {'id': item['id'], 'characteristic': item['characteristic'], 'lab_value': value,
               'verdict': 'non_trouve', 'confidence_pct': None, 'reference_value': None,
               'evidence': [], 'reason': 'Preuve indépendante insuffisante.', 'reviewed_at': current}
        if value is None or (isinstance(value, str) and value.casefold() in UNKNOWN):
            row['verdict'] = 'sans_valeur_lab'
            row['reason'] = 'Aucune valeur musicale finale proposée.'
        else:
            evidence = evidence_by_id.get(item['id'], [])
            if not isinstance(evidence, list):
                raise ValueError('invalid evidence list')
            for entry in evidence:
                if not isinstance(entry, dict) or not _safe_url(entry.get('url')):
                    raise ValueError('invalid external evidence URL')
        results.append(row)
    return {'recording': source['recording'], 'results': results, 'status': 'unverified_documentary_sources'}
