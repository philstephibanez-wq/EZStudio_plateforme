from __future__ import annotations

from typing import Any


_REQUIRED_DIMENSIONS = (
    "tempo_meter",
    "key",
    "tuning",
    "semantics",
    "vocals_choirs",
)


def evaluate_profile_gate(report: dict[str, Any]) -> dict[str, Any]:
    """Evaluate the explicit PROFILE -> STEMS scientific promotion gate.

    Input is deliberately a durable, engine-independent summary. Missing
    evidence blocks promotion; it is never interpreted as success.
    """

    blockers: list[str] = []

    preset = str(report.get("preset") or "").strip()
    if not preset:
        blockers.append("preset_missing")

    corpus = report.get("corpus") if isinstance(report.get("corpus"), dict) else {}
    total = int(corpus.get("total") or 0)
    completed = int(corpus.get("completed") or 0)
    failed = int(corpus.get("failed") or 0)
    if total <= 0:
        blockers.append("corpus_empty")
    elif completed != total:
        blockers.append(f"corpus_incomplete:{completed}/{total}")
    if failed:
        blockers.append(f"jobs_failed:{failed}")

    mandatory_errors = int(report.get("mandatory_module_errors") or 0)
    if mandatory_errors:
        blockers.append(f"mandatory_module_errors:{mandatory_errors}")

    silent_fallbacks = int(report.get("silent_fallbacks") or 0)
    if silent_fallbacks:
        blockers.append(f"silent_fallbacks:{silent_fallbacks}")

    dimensions = report.get("dimensions")
    dimensions = dimensions if isinstance(dimensions, dict) else {}
    for name in _REQUIRED_DIMENSIONS:
        row = dimensions.get(name)
        if not isinstance(row, dict):
            blockers.append(f"dimension_missing:{name}")
            continue
        regressions = int(row.get("blocking_regressions") or 0)
        if regressions:
            blockers.append(f"blocking_regressions:{name}:{regressions}")
        unresolved = int(row.get("unresolved_blockers") or 0)
        if unresolved:
            blockers.append(f"unresolved_blockers:{name}:{unresolved}")

    review = report.get("external_review")
    review = review if isinstance(review, dict) else {}
    review_required = int(review.get("required") or 0)
    review_completed = int(review.get("completed") or 0)
    if review_required > review_completed:
        blockers.append(
            f"external_review_incomplete:{review_completed}/{review_required}"
        )

    baseline = report.get("baseline_comparison")
    baseline = baseline if isinstance(baseline, dict) else {}
    if baseline.get("performed") is not True:
        blockers.append("baseline_comparison_missing")

    decision = "READY_FOR_STEMS" if not blockers else "BLOCKED"
    return {
        "schema": "ezstudio.phase-gate.profile.v1",
        "phase": "PROFILE",
        "next_phase": "STEMS",
        "preset": preset or None,
        "decision": decision,
        "blockers": blockers,
        "summary": {
            "corpus_total": total,
            "corpus_completed": completed,
            "corpus_failed": failed,
            "external_review_required": review_required,
            "external_review_completed": review_completed,
        },
    }
