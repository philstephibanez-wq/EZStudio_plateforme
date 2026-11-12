"""Read-only, targeted PROFILE review. No DB writes, no watcher state writes."""
from __future__ import annotations

from typing import Any

from profile_review import profile_review_queue_snapshot
from profile_auto_review import NetworkBudget, research_public_sources, _run_ai_profile_review
from ai_provider import build_ai_provider_from_env


def diagnose_profile(client: Any, run_id: int, *, limit: int = 500,
                     ai_provider: Any = None, research_fn: Any = None) -> dict:
    if run_id <= 0:
        raise ValueError('run_id_must_be_positive')
    queue = profile_review_queue_snapshot(client, limit=limit)
    matches = [row for row in queue.get('reviews', [])
               if int(row.get('run_id') or 0) == run_id]
    if len(matches) != 1:
        return {'schema': 'ezstudio.profile-diagnostic.v1', 'run_id': run_id,
                'status': 'not_found' if not matches else 'ambiguous',
                'submitted': False, 'message': 'run_not_available_in_review_queue'}
    row = matches[0]
    identity = row.get('normalized_identity') or {}
    research = (research_fn or research_public_sources)(identity,
                 NetworkBudget(max_requests_per_item=2))
    review = _run_ai_profile_review(ai_provider or build_ai_provider_from_env(), row, research)
    dims = review.get('dimensions') if isinstance(review.get('dimensions'), dict) else {}
    return {
        'schema': 'ezstudio.profile-diagnostic.v1', 'run_id': run_id,
        'status': review.get('status'), 'submitted': False,
        'model': review.get('model'), 'provider': review.get('provider'),
        'confidence': review.get('confidence'), 'summary': review.get('summary'),
        'reason': review.get('reason'),
        'research_reason': research.get('reason') if isinstance(research, dict) else None,
        'dimensions': {key: {
            'decision': val.get('decision'), 'confidence': val.get('confidence'),
            'evidence': val.get('evidence'), 'rationale': val.get('rationale')
        } for key, val in dims.items() if isinstance(val, dict)},
        'advisory_only': True, 'external_validation': False,
        'automatic_preset_promotion_allowed': False,
    }
