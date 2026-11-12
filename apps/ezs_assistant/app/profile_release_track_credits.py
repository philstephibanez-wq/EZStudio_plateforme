"""Acquire performer credits on an exact recording inside a MusicBrainz edition."""
from __future__ import annotations
import re
from urllib.parse import urlencode

UUID = re.compile(r"^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$")

def track_credits_on_release(http, release_ids, recording_id):
    """Only track recording relations matching the selected recording MBID.

    Never generalise release-level relations to all tracks.
    """
    if not UUID.fullmatch(str(recording_id or "")):
        return []
    from profile_auto_review import _recording_credits
    for release_id in list(release_ids or [])[:2]:
        if not UUID.fullmatch(str(release_id or "")):
            continue
        if http.request_count >= http.budget.max_requests_per_item:
            break
        url = "https://musicbrainz.org/ws/2/release/" + release_id
        url += "?" + urlencode({"fmt":"json", "inc":"recordings+recording-level-rels+artist-rels"})
        try:
            release = http.get_json(url, retries=0)
        except Exception:
            continue
        for medium in (release.get("media") or [])[:5]:
            for track in (medium.get("tracks") or [])[:40]:
                recording = track.get("recording") or {}
                if recording.get("id") != recording_id:
                    continue
                credits = _recording_credits(
                    recording.get("relations"), "https://musicbrainz.org/recording/"+recording_id)
                for credit in credits:
                    credit["edition_url"] = "https://musicbrainz.org/release/"+release_id
                if credits:
                    return credits
    return []
