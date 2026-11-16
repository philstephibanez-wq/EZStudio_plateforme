"""Structured MusicBrainz discovery & duration-certified recording identity.

Public WS/2 JSON, bounded and read-only.
"""
from __future__ import annotations
import json
import time
import re
import urllib.parse
import urllib.request
from profile_recording_disambiguation import choose_candidate

def _lucene_term(value):
    return '"' + re.sub(r'["\\]', ' ', str(value or "").strip())[:140] + '"'

def discover_recordings(title, artist, *, duration_ms=None, transport=None, limit=20):
    if not str(title or "").strip() or not str(artist or "").strip():
        return {"status": "identity_incomplete", "verified": False, "certified_recording": None, "candidates": []}
    query = "recording:" + _lucene_term(title) + " AND artist:" + _lucene_term(artist)
    url = "https://musicbrainz.org/ws/2/recording/?" + urllib.parse.urlencode(
        {"query": query, "fmt": "json", "limit": max(1, min(int(limit), 25))}
    )
    request = urllib.request.Request(url, headers={
        "Accept": "application/json",
        "User-Agent": "EZStudioLabAssistant/1.7 (documentary-candidate-research)"
    })
    try:
        if transport is None:
            with urllib.request.urlopen(request, timeout=14) as response:
                data = json.loads(response.read(400_000))
        else:
            data = transport(request)
        if not isinstance(data, dict) or not isinstance(data.get("recordings"), list):
            return {"status": "invalid_response", "verified": False, "certified_recording": None, "candidates": []}

        eligible = []
        candidates = []
        for item in data["recordings"]:
            if not isinstance(item, dict):
                continue
            mid = item.get("id")
            if not isinstance(mid, str) or not re.fullmatch(
                r"[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}", mid
            ):
                continue
            credits = item.get("artist-credit") or []
            names = []
            for credit in credits:
                if isinstance(credit, dict):
                    person = credit.get("artist") or {}
                    name = credit.get("name") or person.get("name")
                    if isinstance(name, str):
                        names.append(name[:120])
            releases = []
            for rel in (item.get("releases") or [])[:5]:
                if isinstance(rel, dict) and rel.get("title"):
                    releases.append({
                        "id": rel.get("id"),
                        "title": str(rel.get("title"))[:180],
                        "date": str(rel.get("date") or "")[:32],
                    })
            cand = {
                "mbid": mid,
                "url": "https://musicbrainz.org/recording/" + mid,
                "title": str(item.get("title") or "")[:180],
                "disambiguation": str(item.get("disambiguation") or "")[:120],
                "artist_credit": names,
                "releases": releases,
                "duration_ms": item.get("length") if isinstance(item.get("length"), int) and not isinstance(item.get("length"), bool) else None,
                "source_scope": "musicbrainz_recording_candidate",
                "recording_identity_verified": False,
                "evidence_eligible": False,
            }
            candidates.append(cand)
            eligible.append((mid, item, cand))

        certified = None
        disamb_status = "not_evaluated"
        if eligible and isinstance(duration_ms, int):
            choice = choose_candidate(eligible, duration_ms)
            disamb_status = choice.get("status")
            if disamb_status in ("unique", "duration_match"):
                chosen_mid, _raw, chosen_cand = choice["candidate"]
                chosen_cand["recording_identity_verified"] = True
                chosen_cand["evidence_eligible"] = True
                chosen_cand["duration_delta_ms"] = choice.get("difference_ms", 0)
                certified = chosen_cand

        return {
            "status": "completed",
            "disambiguation_status": disamb_status,
            "verified": certified is not None,
            "certified_recording": certified,
            "candidates": candidates[:8],
        }
    except Exception as exc:
        return {
            "status": "unavailable",
            "error_type": type(exc).__name__,
            "verified": False,
            "certified_recording": None,
            "candidates": [],
        }



import json
import re
import time
import urllib.parse
import urllib.request
from profile_recording_disambiguation import choose_candidate

def _fetch_json(url, timeout=14):
    req = urllib.request.Request(url, headers={
        "Accept": "application/json",
        "User-Agent": "EZStudioLabAssistant/1.7 (documentary-candidate-research)"
    })
    with urllib.request.urlopen(req, timeout=timeout) as res:
        return json.loads(res.read(400_000))

def fetch_certified_recording_details(mbid, *, transport=None):
    if not isinstance(mbid, str) or len(mbid) != 36:
        return {"status": "invalid_mbid"}
    time.sleep(1.1)
    url = "https://musicbrainz.org/ws/2/recording/" + mbid + "?inc=artist-rels+work-rels+tags+genres+releases&fmt=json"
    try:
        data = _fetch_json(url) if transport is None else transport(url)
        if not isinstance(data, dict):
            return {"status": "invalid_response"}
        relations = []
        for rel in data.get("relations") or []:
            if not isinstance(rel, dict):
                continue
            tt = rel.get("target-type")
            rt = rel.get("type")
            attrs = [str(a) for a in (rel.get("attributes") or []) if isinstance(a, str)]
            if tt == "artist" and isinstance(rel.get("artist"), dict):
                relations.append({"role": rt, "artist": rel["artist"].get("name"), "attributes": attrs})
            elif tt == "work" and isinstance(rel.get("work"), dict):
                relations.append({"role": rt, "work_pid": rel["work"].get("id"), "work_title": rel["work"].get("title")})
        tags = [t.get("name") for t in (data.get("tags") or []) if isinstance(t, dict) and t.get("name")]
        genres = [g.get("name") for g in (data.get("genres") or []) if isinstance(g, dict) and g.get("name")]
        return {
            "status": "completed",
            "mbid": mbid,
            "url": "https://musicbrainz.org/recording/" + mbid,
            "title": data.get("title"),
            "duration_ms": data.get("length"),
            "genres": genres,
            "tags": tags,
            "relations": relations,
        }
    except Exception as exc:
        return {"status": "unavailable", "error_type": type(exc).__name__}

def fetch_wikipedia_song_context(title, artist, album=""):
    results = []
    queries = [f'{title} {artist} song']
    if album:
        queries.append(f'{album} {artist} album')
    seen_titles = set()
    for q in queries:
        try:
            search_url = "https://en.wikipedia.org/w/api.php?" + urllib.parse.urlencode({
                "action": "query", "list": "search", "srsearch": q, "srlimit": 2, "format": "json"
            })
            data = _fetch_json(search_url, timeout=10)
            for hit in (data.get("query") or {}).get("search") or []:
                page_title = hit.get("title")
                if not page_title or page_title in seen_titles:
                    continue
                seen_titles.add(page_title)
                extract_url = "https://en.wikipedia.org/w/api.php?" + urllib.parse.urlencode({
                    "action": "query", "prop": "extracts", "explaintext": 1, "exchars": 6000,
                    "titles": page_title, "format": "json"
                })
                pdata = _fetch_json(extract_url, timeout=10)
                pages = (pdata.get("query") or {}).get("pages") or {}
                for pid, page in pages.items():
                    txt = str(page.get("extract") or "").strip()
                    if txt:
                        slug = urllib.parse.quote(page_title.replace(" ", "_"))
                        results.append({
                            "url": "https://en.wikipedia.org/wiki/" + slug,
                            "title": page_title,
                            "source_scope": "wikipedia_encyclopedia",
                            "status": "read",
                            "text": txt[:6000],
                        })
        except Exception:
            continue
    return results
