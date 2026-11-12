from __future__ import annotations

from pathlib import Path
import json
from typing import Any


SEPARATORS = (" - ", " – ", " — ")


def normalize_track_identity(row: dict[str, Any]) -> dict[str, Any]:
    import re

    raw_title = str(row.get("title") or "").strip()
    raw_artist = str(row.get("artist") or "").strip()

    if raw_artist:
        return {
            "title": raw_title,
            "artist": raw_artist,
            "raw_title": raw_title,
            "raw_artist": raw_artist,
            "method": "catalog",
            "confidence": 1.0,
            "requires_verification": False,
        }

    working = re.sub(r"\s+", " ", raw_title).strip()
    metadata_removed: list[str] = []

    # Remove trailing technical metadata segments such as "- 121bpm - G#min".
    technical_segment = re.compile(
        r"^(?:\d{2,3}\s*bpm|[A-G](?:#|b)?(?:maj|min|major|minor|m)?)$",
        re.IGNORECASE,
    )
    while True:
        match = re.search(r"\s+[-–—]\s*([^-–—]+?)\s*$", working)
        if not match:
            break
        tail = match.group(1).strip()
        if not technical_segment.fullmatch(tail):
            break
        metadata_removed.insert(0, tail)
        working = working[:match.start()].rstrip()

    # A title/artist delimiter is accepted when the dash has whitespace
    # on at least one side. This preserves ordinary hyphenated names.
    matches = list(re.finditer(r"(?:\s+[-–—]\s*|[-–—]\s+)", working))
    if matches:
        split = matches[-1]
        title = working[:split.start()].strip()
        artist = working[split.end():].strip()

        artist_before_cleanup = artist
        artist = re.sub(
            r"\s*\((?:[A-G](?:#|b)?(?:maj|min|major|minor|m)?|(?:19|20)\d{2})\)\s*$",
            "",
            artist,
            flags=re.IGNORECASE,
        ).strip()
        if artist != artist_before_cleanup:
            metadata_removed.append(artist_before_cleanup[len(artist):].strip())

        if title and artist:
            return {
                "title": title,
                "artist": artist,
                "raw_title": raw_title,
                "raw_artist": raw_artist,
                "method": (
                    "title_artist_metadata_heuristic"
                    if metadata_removed
                    else "title_suffix_heuristic"
                ),
                "confidence": 0.65,
                "requires_verification": True,
                "metadata_removed": metadata_removed,
            }

    return {
        "title": raw_title,
        "artist": raw_artist,
        "raw_title": raw_title,
        "raw_artist": raw_artist,
        "method": "catalog_incomplete",
        "confidence": 0.25,
        "requires_verification": True,
        "metadata_removed": metadata_removed,
    }
def profile_review_queue_snapshot(client: Any, *, limit: int = 100) -> dict[str, Any]:
    jobs = client.profile_review_queue(limit=limit)
    normalized = []
    for row in jobs:
        item = dict(row)
        item["normalized_identity"] = normalize_track_identity(item)
        normalized.append(item)

    return {
        "schema": "EZStudioLab_assistant.profile-review-queue.v1",
        "review_execution_enabled": False,
        "automatic_preset_promotion_enabled": False,
        "count": len(normalized),
        "reviews": normalized,
    }


def load_review_payload(path: str | Path) -> dict[str, Any]:
    payload = json.loads(Path(path).read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise ValueError("profile_review_payload_must_be_object")
    return payload
