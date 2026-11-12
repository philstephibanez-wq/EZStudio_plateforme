"""Declarative Assistant review configuration, deliberately separate from LAB science presets."""
from __future__ import annotations
import hashlib
import json
import os
from pathlib import Path

PHASES = ('profile', 'stems', 'chords_n', 'lyrics')
ALLOWED = {'schema', 'id', 'phase', 'provider', 'prompt', 'output_contract', 'dimension_normalization', 'identity_resolution', 'temperature', 'max_tokens', 'context_max_chars'}
# The review preset path must be explicitly configured and read-only.
# Never silently fall back to a local preset.
DEFAULT_PATH = None


def load_review_preset(phase='profile', path=None):
    """Read on EACH invocation: changes require no worker restart. Fail closed."""
    if phase not in PHASES:
        raise ValueError('review_phase_unknown')
    configured = path or os.getenv('EZSTUDIOLAB_REVIEW_PRESET_PATH')
    if not configured:
        raise ValueError('review_main_preset_path_required')
    selected = Path(configured)
    if os.name == 'nt' and not str(selected).startswith('\\\\'):
        canonical = (Path(__file__).resolve().parents[2] / 'ezs_lab' / 'presets' / 'assistant' / 'profile-baseline-1.json')
        if os.path.normcase(os.path.abspath(str(selected))) != os.path.normcase(os.path.abspath(str(canonical))):
            raise ValueError('review_preset_requires_main_unc_or_local_canonical_path')
    raw = selected.read_bytes()
    if raw.startswith(b'\xef\xbb\xbf'):
        raise ValueError('review_preset_bom_forbidden')
    if len(raw) > 32768:
        raise ValueError('review_preset_oversized')
    data = json.loads(raw.decode('utf-8', errors='strict'))
    if not isinstance(data, dict) or set(data) != ALLOWED:
        raise ValueError('review_preset_invalid_keys')
    if data['schema'] != 'ezs.assistant.review-preset.v1' or data['phase'] != phase:
        raise ValueError('review_preset_schema_phase_mismatch')
    if not isinstance(data['id'], str) or not 1 <= len(data['id']) <= 90 or not all(c.isalnum() or c in '._-' for c in data['id']):
        raise ValueError('review_preset_invalid_id')
    if data['provider'] != 'local':
        raise ValueError('review_preset_provider_not_supported')
    if not isinstance(data['prompt'], str) or not 100 <= len(data['prompt']) <= 12000:
        raise ValueError('review_preset_invalid_prompt')
    contract = data['output_contract']
    if not isinstance(contract, str) or not 100 <= len(contract) <= 12000:
        raise ValueError('review_preset_invalid_output_contract')
    norm = data['dimension_normalization']
    canonical = {'genre', 'instrumentation', 'lead_vocal', 'backing_vocals', 'choir'}
    if not isinstance(norm, dict) or set(norm) != {'aliases', 'uncertainty', 'vocal_group'}:
        raise ValueError('review_preset_invalid_normalization')
    aliases = norm['aliases']
    if not isinstance(aliases, dict) or len(aliases) > 48 or any(
        not isinstance(k, str) or not k or len(k) > 40 or not isinstance(v, str) or v not in canonical
        or k in canonical for k, v in aliases.items()
    ):
        raise ValueError('review_preset_invalid_aliases')
    if not isinstance(norm['uncertainty'], list) or len(norm['uncertainty']) > 30 or any(
        not isinstance(s, str) or not s or len(s) > 40 for s in norm['uncertainty']
    ):
        raise ValueError('review_preset_invalid_uncertainty')
    if not isinstance(norm['vocal_group'], str) or norm['vocal_group'] in canonical or not norm['vocal_group']:
        raise ValueError('review_preset_invalid_vocal_group')
    identity = data['identity_resolution']
    if not isinstance(identity, dict) or set(identity) != {'enabled', 'prompt', 'max_candidates', 'min_mb_score'}:
        raise ValueError('review_preset_invalid_identity_resolution')
    if not isinstance(identity['enabled'], bool) or not isinstance(identity['prompt'], str) or not 80 <= len(identity['prompt']) <= 2000:
        raise ValueError('review_preset_invalid_identity_resolution')
    for key, low, high in (('max_candidates', 1, 5), ('min_mb_score', 70, 100)):
        value = identity[key]
        if isinstance(value, bool) or not isinstance(value, int) or not low <= value <= high:
            raise ValueError('review_preset_invalid_identity_resolution')
    t = data['temperature']
    if isinstance(t, bool) or not isinstance(t, (int, float)) or not 0 <= t <= 1:
        raise ValueError('review_preset_invalid_temperature')
    for field, low, high in [('max_tokens', 200, 16384), ('context_max_chars', 2000, 16000)]:
        n = data[field]
        if isinstance(n, bool) or not isinstance(n, int) or not low <= n <= high:
            raise ValueError('review_preset_invalid_' + field)
    return data, hashlib.sha256(raw).hexdigest()
