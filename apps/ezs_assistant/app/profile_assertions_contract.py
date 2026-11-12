"""Contrat strict des assertions PROFILE de LAB vers l'Assistant.

Module indépendant des moteurs scientifiques. Le raccordement au worker reste distinct.
"""
from __future__ import annotations

import math
from urllib.parse import urlsplit

EXPERTISE_TYPES = ("profile", "stems", "chords_n", "lyrics")

ITEMS = ("duree", "tempo", "mesure", "tonalite", "genre", "instruments",
         "voix_principale", "voix_accompagnement", "harmonies_vocales", "choeurs")
ABSENT = {"", "possible", "inconclusive", "not_scored", "indetermine", "indéterminé"}


def _value(value, *, allow_list=False):
    if value is None or isinstance(value, bool):
        return None
    if isinstance(value, str):
        return None if value.strip().casefold() in ABSENT else value
    if isinstance(value, (int, float)):
        if not math.isfinite(value):
            raise ValueError("profile_item_value_invalid")
        return value
    if isinstance(value, list) and allow_list:
        usable = [_value(entry) for entry in value]
        return [entry for entry in usable if entry is not None] or None
    # Never forward objects, nested arrays or non-finite numerics from LAB.
    raise ValueError("profile_item_value_invalid")

def build_assertions(source):
    """Projection par liste blanche, sans transmettre engine/config/diagnostics."""
    if not isinstance(source, dict):
        raise ValueError("profile_source_object_required")
    if source.get("expertise_type") not in EXPERTISE_TYPES:
        raise ValueError("expertise_type_invalid")
    if source["expertise_type"] != "profile":
        raise ValueError("expertise_type_not_implemented")
    value = source.get("analysis_id")
    if not isinstance(value, int) or isinstance(value, bool) or value <= 0:
        raise ValueError("analysis_id_required")
    preset = source.get("preset_id")
    if preset is not None and (not isinstance(preset, (int, str)) or isinstance(preset, bool) or not str(preset).strip()):
        raise ValueError("preset_id_invalid")
    if not isinstance(source.get("title"), str) or not source["title"].strip():
        raise ValueError("title_required")
    if source.get("artist") is not None and not isinstance(source["artist"], str):
        raise ValueError("artist_invalid")
    items = source.get("items")
    if not isinstance(items, dict) or set(items) != set(ITEMS):
        raise ValueError("exactly_ten_items_required")
    return {
        "schema": "ezstudio.profile.assertions.v1",
        "expertise_type": "profile",
        "analysis_id": source["analysis_id"],
        "preset_id": source["preset_id"],
        "title": source["title"].strip(),
        "artist": (source.get("artist") or "").strip(),
        "recording_id": source.get("recording_id") if isinstance(source.get("recording_id"), str) else None,
        "version": source.get("version") if isinstance(source.get("version"), str) else None,
        "items": {item: _value(items[item], allow_list=item == "instruments") for item in ITEMS},
    }


def validate_verdicts(request, result):
    """Validation structurelle: une preuve déclarée reste à vérifier indépendamment."""
    if not isinstance(result, dict):
        raise ValueError("review_object_required")
    if any(result.get(key) != request[key] for key in ("analysis_id", "preset_id", "expertise_type")):
        raise ValueError("review_identity_mismatch")
    rows = result.get("reviews")
    if not isinstance(rows, dict) or set(rows) != set(ITEMS):
        raise ValueError("exactly_ten_reviews_required")
    for item, row in rows.items():
        if not isinstance(row, dict):
            raise ValueError("invalid_review")
        verdict = row.get("verdict")
        if verdict not in ("bon", "faux", "non_trouve", "sans_valeur_lab"):
            raise ValueError("invalid_verdict")
        if (request["items"][item] is None) != (verdict == "sans_valeur_lab"):
            raise ValueError("missing_value_mismatch")
        confidence = row.get("confidence_pct")
        if confidence is not None and (
            not isinstance(confidence, (int, float)) or isinstance(confidence, bool)
            or not 0 <= confidence <= 100 or not row.get("calibration_id")
        ):
            raise ValueError("uncalibrated_confidence")
        evidence = row.get("evidence", [])
        if not isinstance(evidence, list):
            raise ValueError("invalid_evidence")
        if verdict in ("bon", "faux") and (
            not evidence or row.get("reference_value") is None or not row.get("reason")
        ):
            raise ValueError("unsupported_verdict")
        if verdict in ("non_trouve", "sans_valeur_lab") and evidence:
            raise ValueError("evidence_on_abstention")
        for ev in evidence:
            if not isinstance(ev, dict) or not isinstance(ev.get("url"), str):
                raise ValueError("invalid_evidence")
            url = urlsplit(ev["url"])
            if (url.scheme != "https" or not url.hostname or url.username or url.password
                or any(char.isspace() for char in ev["url"])
                or not ev.get("source") or not ev.get("scope")
                or ev.get("recording_match_verified") is not True):
                raise ValueError("inadmissible_evidence")
    return True
