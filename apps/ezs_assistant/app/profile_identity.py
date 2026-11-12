"""Preset-driven candidate proposal followed by independent MusicBrainz title/artist verification.

No model-generated performer is considered authoritative. Multiple possible artists
produce an abstention. No catalogue row, audio or scientific preset is modified.
"""
from __future__ import annotations
import json
import urllib.parse
import urllib.request
from declarative_review import load_review_preset
from profile_auto_review import NetworkBudget, ThrottledPublicHttp


def _clean(value):
    return " ".join(value.split()).casefold() if isinstance(value, str) else ""


def confirm_candidates(title, candidates, response, min_score):
    """Resolve only an unambiguous candidate among title-exact MusicBrainz recordings."""
    artists = set()
    for record in response.get("recordings", []):
        if not isinstance(record, dict) or _clean(record.get("title")) != _clean(title):
            continue
        score = record.get("score", 0)
        try:
            if int(score) < min_score:
                continue
        except (TypeError, ValueError):
            continue
        names = [c.get("name") or (c.get("artist") or {}).get("name")
                 for c in record.get("artist-credit", []) if isinstance(c, dict)]
        for name in names:
            if _clean(name) in {_clean(c) for c in candidates}:
                artists.add(name.strip())
    return next(iter(artists)) if len(artists) == 1 else None


def resolve_missing_artist(title, provider, *, preset=None, http=None):
    preset = preset or load_review_preset("profile")[0]
    settings = preset["identity_resolution"]
    abstain = lambda count=0: {"status": "unresolved", "artist": None, "candidate_count": count}
    if not settings["enabled"] or not _clean(title):
        return abstain()
    ready, _reason = provider._health()
    if not ready:
        return abstain()
    body = {"model": "local", "messages": [
        {"role": "system", "content": settings["prompt"]},
        {"role": "user", "content": json.dumps({"title": title}, ensure_ascii=False)}
    ], "temperature": preset["temperature"], "max_tokens": 256,
        "stream": False, "response_format": {"type": "json_object"}}
    req = urllib.request.Request(provider.endpoint + "/v1/chat/completions",
        data=json.dumps(body, ensure_ascii=False).encode("utf-8"),
        headers={"Content-Type": "application/json"}, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=max(10, float(provider.config.timeout_seconds))) as res:
            reply = json.loads(res.read(250_000))
        content = reply["choices"][0]["message"]["content"]
        parsed = provider._decode_local_json(content)
        names = parsed.get("artists")
        if not isinstance(names, list):
            return abstain()
        candidates = [s.strip() for s in names[:settings["max_candidates"]]
                      if isinstance(s, str) and 1 <= len(s.strip()) <= 120]
        if not candidates:
            return abstain()
        transport = http or ThrottledPublicHttp(NetworkBudget())
        query = urllib.parse.urlencode({"query": 'recording:"' + title.replace('"', '') + '"',
                                        "fmt": "json", "limit": 20})
        records = transport.get_json("https://musicbrainz.org/ws/2/recording/?" + query)
        confirmed = confirm_candidates(title, candidates, records, settings["min_mb_score"])
        if confirmed:
            return {"status": "resolved", "artist": confirmed, "candidate_count": len(candidates)}
        return abstain(len(candidates))
    except (ValueError, KeyError, IndexError, OSError, TypeError, RuntimeError):
        return abstain()
