from __future__ import annotations

from typing import Any


class DecisionError(ValueError):
    pass


def _score(row: dict[str, Any]) -> float:
    value = row.get("score")
    try:
        return float(value)
    except (TypeError, ValueError):
        return float("-inf")


def apply_decision(rows: list[dict[str, Any]], policy: dict[str, Any] | None) -> dict[str, Any]:
    """Apply generic declarative decision primitives.

    V1 supports compatibility_top_k and multilabel_with_abstention. No silent
    fallback is allowed: unknown modes raise explicitly.
    """
    policy = dict(policy or {})
    mode = str(policy.get("mode") or "compatibility_top_k")
    ordered = sorted(
        [dict(row) for row in rows if isinstance(row, dict)],
        key=_score,
        reverse=True,
    )

    if mode == "compatibility_top_k":
        top_k = max(0, int(policy.get("top_k", len(ordered))))
        return {
            "mode": mode,
            "decision": "accepted" if ordered[:top_k] else "abstained",
            "selected": ordered[:top_k],
            "rejected": ordered[top_k:],
            "abstained": not bool(ordered[:top_k]),
        }

    if mode == "multilabel_with_abstention":
        threshold = float(policy.get("threshold", 0.0))
        max_labels = max(1, int(policy.get("max_labels", len(ordered) or 1)))
        min_margin = float(policy.get("min_margin", 0.0))
        selected = [row for row in ordered if _score(row) >= threshold][:max_labels]

        if selected and min_margin > 0.0 and len(ordered) >= 2:
            if _score(ordered[0]) - _score(ordered[1]) < min_margin:
                selected = []

        selected_ids = {id(row) for row in selected}
        rejected = [row for row in ordered if id(row) not in selected_ids]
        return {
            "mode": mode,
            "decision": "accepted" if selected else "abstained",
            "selected": selected,
            "rejected": rejected,
            "abstained": not bool(selected),
            "threshold": threshold,
            "min_margin": min_margin,
        }

    raise DecisionError(f"decision_mode_not_supported:{mode}")
