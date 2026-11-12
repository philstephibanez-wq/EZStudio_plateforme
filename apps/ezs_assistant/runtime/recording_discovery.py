"""Independent recording discovery via MusicBrainz; candidates never become musical proofs."""
import json
import re
import unicodedata
from urllib.parse import urlencode
from urllib.request import Request, urlopen


def canonical(text):
    return " ".join(re.findall(r"[a-z0-9]+", unicodedata.normalize("NFKD", text).encode("ascii", "ignore").decode("ascii").lower()))


def discover(recording, *, opener=urlopen):
    """Search public recording catalogue; return identity candidates, no musical verdict."""
    if not isinstance(recording, dict) or any(not isinstance(recording.get(k), str) or not recording[k].strip() for k in ("title", "artist")):
        raise ValueError("recording title and artist required")
    q = f'recording:"{recording["title"]}" AND artist:"{recording["artist"]}"'
    url = "https://musicbrainz.org/ws/2/recording/?" + urlencode({"query": q, "fmt": "json", "limit": 10})
    req = Request(url, headers={"User-Agent": "EZStudioLabAssistant/1.6a (recording-discovery; contact via project repository)", "Accept": "application/json"})
    with opener(req, timeout=20) as resp:
        raw = resp.read(2_000_001)
        if len(raw) > 2_000_000:
            raise ValueError("catalogue response too large")
        payload = json.loads(raw)
    results = []
    for rec in payload.get("recordings", []):
        if not isinstance(rec, dict):
            continue
        title = rec.get("title", "")
        credits = rec.get("artist-credit", [])
        artist = " ".join(part.get("name", "") for part in credits if isinstance(part, dict))
        if not isinstance(title, str):
            continue
        if canonical(title) != canonical(recording["title"]) or canonical(artist) != canonical(recording["artist"]):
            continue
        mbid = rec.get("id", "")
        if not re.fullmatch(r"[0-9a-f-]{36}", mbid):
            continue
        results.append({"recording_mbid": mbid, "title": title, "artist": artist,
                        "source_url": "https://musicbrainz.org/recording/" + mbid,
                        "recording_identity": "candidate_only",
                        "musical_claims_verified": False})
    return {"provider": "musicbrainz", "candidates": results,
            "status": "identity_candidates_not_verified_recording",
            "musical_verdict": None}
