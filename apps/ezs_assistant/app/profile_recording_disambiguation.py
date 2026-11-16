"""Strict MusicBrainz recording candidate disambiguation by catalog audio duration.

Prefers studio releases over live versions when multiple pressings match duration.
"""
from __future__ import annotations
import math

def duration_from_catalog(row):
    """Extract declared audio duration in milliseconds from LAB row or final assertions."""
    values = []
    metrics = row.get("metrics") if isinstance(row.get("metrics"), dict) else {}
    for field in ("duration_seconds",):
        value = metrics.get(field)
        if isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(value):
            ms = int(round(value * 1000))
            if 30_000 <= ms <= 3_600_000:
                values.append(ms)
    for field, scale in (("audio_duration_ms", 1), ("duration_ms", 1), ("audio_duration_seconds", 1000), ("duration_seconds", 1000)):
        value = row.get(field)
        if isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(value):
            ms = int(round(value * scale))
            if 30_000 <= ms <= 3_600_000:
                values.append(ms)
    for assertion in row.get("_public_final_assertions") or row.get("final_assertions") or []:
        if isinstance(assertion, dict) and assertion.get("id") in ("duration", "duree"):
            val = assertion.get("value")
            if isinstance(val, (int, float)) and not isinstance(val, bool) and math.isfinite(val):
                ms = int(round(val * 1000))
                if 30_000 <= ms <= 3_600_000:
                    values.append(ms)
    if not values or max(values) - min(values) > 1000:
        return None
    return round(sum(values) / len(values))

def _is_live_candidate(record):
    disamb = str(record.get("disambiguation") or "").casefold()
    title = str(record.get("title") or "").casefold()
    if "live" in disamb or "(live" in title or "[live" in title:
        return True
    releases = record.get("releases") or []
    if releases and all(
        "live" in str(r.get("title") or "").casefold()
        or "live" in [str(t).casefold() for t in ((r.get("release-group") or {}).get("secondary-types") or [])]
        for r in releases if isinstance(r, dict)
    ):
        return True
    return False

def choose_candidate(eligible, duration_ms, *, tolerance_ms=2000, min_margin_ms=4000):
    """Choose the matching studio MusicBrainz recording by duration; ignore undated community stubs."""
    if len(eligible) == 1:
        return {"status": "unique", "candidate": eligible[0], "matching": 1}
    if not isinstance(duration_ms, int):
        return {"status": "duration_unavailable", "matching": len(eligible)}
    matches = []
    for candidate in eligible:
        rec = candidate[1]
        length = rec.get("length")
        if isinstance(length, (int, float)) and not isinstance(length, bool) and math.isfinite(length) and 30_000 <= length <= 3_600_000:
            diff = abs(round(length) - duration_ms)
            if diff <= tolerance_ms:
                is_live = _is_live_candidate(rec)
                matches.append((1 if is_live else 0, diff, candidate))
    if not matches:
        return {"status": "no_duration_match", "matching": 0}
    matches.sort(key=lambda item: (item[0], item[1]))
    best_live, best_diff, best_cand = matches[0]
    # If another candidate has a different release title and is very close in rank/duration, check margin
    if len(matches) > 1:
        second_live, second_diff, second_cand = matches[1]
        best_rels = {str(r.get("title") or "").casefold() for r in (best_cand[1].get("releases") or []) if r.get("title")}
        sec_rels = {str(r.get("title") or "").casefold() for r in (second_cand[1].get("releases") or []) if r.get("title")}
        same_album = bool(best_rels & sec_rels)
        if best_live == second_live and not same_album and (second_diff - best_diff) < min_margin_ms:
            return {"status": "insufficient_duration_margin", "matching": len(matches)}
    return {
        "status": "duration_match",
        "candidate": best_cand,
        "difference_ms": best_diff,
        "is_live": bool(best_live),
        "matching": len(matches),
    }
