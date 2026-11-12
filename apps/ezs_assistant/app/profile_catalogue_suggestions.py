"""R37 documentary catalogue suggestions. Read-only, never authoritative."""
from __future__ import annotations
from collections import defaultdict
from profile_adaptive_keywords import terms
from profile_documentary_reconcile import _similar, _artist_from_hit

SCHEMA = "ezstudio.assistant.catalogue-suggestions.v1"

def _field(value=None, status="unknown", sources=None, original=None):
    return {"value": value, "original": original, "status": status,
            "confidence": None, "sources": list(sources or []),
            "human_validated": False}

def _person(value, sources):
    return _field(value, "candidate" if value else "unknown", sources)

def build_catalogue_suggestions(title, artist_hint, reconciliation):
    """Prepare evidence-linked proposals without assigning authors/composers by guesswork."""
    raw_title = str(title or "").strip()
    hint = str(artist_hint or "").strip() or None
    links = reconciliation.get("documentary_links") or []
    names = defaultdict(set)
    for hit in links:
        if not isinstance(hit, dict):
            continue
        url = hit.get("url")
        if not isinstance(url, str) or not url.startswith("https://"):
            continue
        for value in hit.get("artist_candidates_extracted") or []:
            if isinstance(value, str) and value.strip():
                names[value.strip()].add(url)
    for item in reconciliation.get("musicbrainz_artist_matches") or []:
        if isinstance(item, str) and item.strip():
            names.setdefault(item.strip(), set())
    candidates = []
    for name, urls in sorted(names.items()):
        match_hint = bool(hint and _similar(hint, name))
        if match_hint:
            candidates.append((name, sorted(urls)))
    # A lone unsupported filename hint must not become an identified performer.
    if len(candidates) == 1:
        chosen, sources = candidates[0]
        performer = _field(chosen, "corroborated" if sources else "candidate",
                           sources, hint)
    else:
        performer = _field(original=hint)
    # Filename normalization cannot recover canonical capitalization/punctuation reliably.
    # Keep title only as an unverified candidate; do not claim 'official' formatting.
    full_title = _field(raw_title if raw_title else None,
                        "candidate" if raw_title else "unknown",
                        original=raw_title or None)
    return {
        "schema": SCHEMA,
        "advisory_only": True,
        "lab_write_allowed": False,
        "work": {"full_title": full_title, "lyricists": [], "composers": []},
        "recording": {"performers": [performer] if performer["value"] else [],
                      "arrangers": []},
        "missing": {
            "lyricists": "not_documented", "composers": "not_documented",
            "arrangers": "not_documented",
            "performers": "not_documented" if not performer["value"] else None},
        "identity_verified": False,
        "recording_identity_verified": False
    }
