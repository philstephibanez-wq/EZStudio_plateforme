from __future__ import annotations

from math import floor
from typing import Any


def _quantile(values: list[float], q: float) -> float:
    if not values:
        raise ValueError("empty_score_set")
    ordered = sorted(values)
    if len(ordered) == 1:
        return ordered[0]
    pos = (len(ordered) - 1) * q
    lo = floor(pos)
    hi = min(len(ordered) - 1, lo + 1)
    frac = pos - lo
    return ordered[lo] * (1.0 - frac) + ordered[hi] * frac


def _metrics(scores: dict[str, float], threshold: float) -> dict[str, float]:
    values = list(scores.values())
    return {
        "p50": _quantile(values, 0.50),
        "p10": _quantile(values, 0.10),
        "pass_rate": sum(1 for value in values if value >= threshold) / len(values),
        "min": min(values),
    }


def evaluate_preset_candidate(
    baseline_scores: dict[str, float],
    candidate_scores: dict[str, float],
    *,
    threshold: float = 0.75,
    metric_tolerance: float = 0.0,
    max_song_regression: float = 0.10,
) -> dict[str, Any]:
    baseline = {str(k): float(v) for k, v in baseline_scores.items()}
    candidate = {str(k): float(v) for k, v in candidate_scores.items()}

    if not baseline:
        raise ValueError("baseline_scores_required")
    if set(baseline) != set(candidate):
        return {
            "schema": "EZStudioLab_assistant.preset-guard.v1",
            "decision": "REJECT",
            "reason": "corpus_mismatch",
            "automatic_promotion_allowed": False,
        }

    before = _metrics(baseline, threshold)
    after = _metrics(candidate, threshold)
    deltas = {song: candidate[song] - baseline[song] for song in baseline}
    critical = sorted(
        song for song, delta in deltas.items()
        if delta < -abs(max_song_regression)
    )

    reasons = []
    if after["p50"] < before["p50"] - metric_tolerance:
        reasons.append("p50_regression")
    if after["p10"] < before["p10"] - metric_tolerance:
        reasons.append("p10_regression")
    if after["pass_rate"] + metric_tolerance < before["pass_rate"]:
        reasons.append("pass_rate_regression")
    if critical:
        reasons.append("critical_song_regression")

    return {
        "schema": "EZStudioLab_assistant.preset-guard.v1",
        "decision": "REJECT" if reasons else "CANDIDATE_ACCEPTABLE",
        "reasons": reasons,
        "automatic_promotion_allowed": False,
        "baseline": before,
        "candidate": after,
        "critical_regressions": critical,
        "song_deltas": deltas,
    }
