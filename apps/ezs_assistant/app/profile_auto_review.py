from __future__ import annotations

import html
import hashlib
import json
from pathlib import Path
import re
import unicodedata
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from typing import Any

from profile_review import normalize_track_identity, profile_review_queue_snapshot
from ai_provider import AIReviewProvider, build_ai_provider_from_env
from profile_physical_documents import discover_release_documents, inspect_scans_with_local_ocr
from profile_release_track_credits import track_credits_on_release
from profile_recording_disambiguation import choose_candidate


@dataclass(frozen=True)
class NetworkBudget:
    min_interval_seconds: float = 2.0
    max_response_bytes: int = 262_144
    timeout_seconds: float = 8.0
    max_requests_per_item: int = 10


class ThrottledPublicHttp:
    def __init__(self, budget: NetworkBudget, user_agent: str = "EZStudioLab_assistant/1.2 (PROFILE research)"):
        self.budget = budget
        self.user_agent = user_agent
        self.request_count = 0
        self._last_request_at = 0.0

    def get_json(self, url: str, *, retries: int = 1) -> dict[str, Any]:
        attempts = 0
        while True:
            if self.request_count >= self.budget.max_requests_per_item:
                raise RuntimeError("profile_research_network_request_budget_exhausted")

            now = time.monotonic()
            wait = self.budget.min_interval_seconds - (now - self._last_request_at)
            if wait > 0:
                time.sleep(wait)

            req = urllib.request.Request(
                url,
                headers={
                    "Accept": "application/json",
                    "User-Agent": self.user_agent,
                },
                method="GET",
            )
            self._last_request_at = time.monotonic()
            self.request_count += 1

            try:
                with urllib.request.urlopen(req, timeout=self.budget.timeout_seconds) as response:
                    content_length = response.headers.get("Content-Length")
                    if content_length:
                        try:
                            if int(content_length) > self.budget.max_response_bytes:
                                raise RuntimeError("profile_research_response_too_large")
                        except ValueError:
                            pass

                    raw = response.read(self.budget.max_response_bytes + 1)
                    if len(raw) > self.budget.max_response_bytes:
                        raise RuntimeError("profile_research_response_too_large")

                value = json.loads(raw.decode("utf-8"))
                if not isinstance(value, dict):
                    raise RuntimeError("profile_research_json_object_required")
                return value
            except urllib.error.HTTPError as exc:
                transient = int(getattr(exc, "code", 0)) in {429, 500, 502, 503, 504}
                if transient and attempts < max(0, int(retries)):
                    attempts += 1
                    time.sleep(min(8.0, 1.5 * (2 ** (attempts - 1))))
                    continue
                raise


def _compact(value: Any) -> str:
    return re.sub(r"\s+", " ", str(value or "")).strip()


def _query(params: dict[str, Any]) -> str:
    return urllib.parse.urlencode(params, doseq=True, quote_via=urllib.parse.quote)


def _recording_credits(relations: Any, recording_url: str | None) -> list[dict[str, Any]]:
    """Extract recording-level performer credits; album credits are not track evidence."""
    if not isinstance(relations, list):
        return []
    result = []
    for relation in relations:
        if not isinstance(relation, dict) or relation.get("target-type") != "artist":
            continue
        kind = _compact(relation.get("type")).casefold()
        attributes = [_compact(x) for x in relation.get("attributes") or [] if isinstance(x, str)]
        instruments = []
        instrument_obj = relation.get("instrument")
        if isinstance(instrument_obj, dict) and _compact(instrument_obj.get("name")):
            instruments.append(_compact(instrument_obj["name"]))
        for attribute in attributes:
            if attribute.casefold() not in _NON_CREDIT_ATTRIBUTES and attribute not in instruments:
                instruments.append(attribute)
        if not (kind in {"instrument", "vocal", "performer"} or "vocal" in kind):
            continue
        artist = relation.get("artist")
        musician = _compact(artist.get("name")) if isinstance(artist, dict) else ""
        if not musician:
            continue
        result.append({
            "musician": musician, "role": kind, "instruments": instruments[:8],
            "scope": "recording", "source_url": recording_url,
        })
    return result[:30]


_NON_CREDIT_ATTRIBUTES = {
    "additional", "guest", "solo", "lead", "rhythm", "background",
    "backing", "live", "session", "featured",
}


def _musicbrainz(http: ThrottledPublicHttp, title: str, artist: str, *, duration_ms: int | None = None) -> dict[str, Any]:
    # A trailing YEAR is catalogue metadata, not part of the recording title.
    # Preserve actual version qualifiers (live, demo, remix) to avoid wrong takes.
    lookup_title = re.sub(r"\s*\((?:18|19|20)\d{2}\)\s*$", "", title).strip()
    lookup_title = lookup_title or title
    query = f'recording:"{lookup_title}" AND artist:"{artist}"'
    search_url = "https://musicbrainz.org/ws/2/recording/?" + _query(
        {"query": query, "fmt": "json", "limit": 20}
    )
    data = http.get_json(search_url)
    recordings = data.get("recordings") or []
    if not isinstance(recordings, list) or not recordings:
        return {"source": "MusicBrainz", "available": False}

    best = None
    eligible = []
    for row in recordings:
        if not isinstance(row, dict):
            continue
        score = int(row.get("score") or 0)
        credits = row.get("artist-credit") or []
        names = []
        for credit in credits:
            if isinstance(credit, dict):
                names.append(_compact(credit.get("name") or (credit.get("artist") or {}).get("name")))
        candidate_artist = ", ".join(x for x in names if x)
        title_match = _normalize_identity_text(row.get("title")) == _normalize_identity_text(lookup_title)
        artist_match = artist.casefold() in candidate_artist.casefold()
        rank = (1 if title_match else 0, 1 if artist_match else 0, score)
        if rank[0] == 1 and rank[1] == 1:
            eligible.append((rank, row, candidate_artist))
        if best is None or rank > best[0]:
            best = (rank, row, candidate_artist)

    if best is None:
        return {"source": "MusicBrainz", "available": False}

    # Never fetch unrelated first-ranked recordings as alleged evidence.
    if best[0][0] != 1 or best[0][1] != 1:
        return {"source": "MusicBrainz", "available": False,
                "reason": "no_matching_recording", "candidate_count": len(recordings)}

    ids = sorted({_compact(x[1].get('id')) for x in eligible if _compact(x[1].get('id'))})
    selected = choose_candidate(eligible, duration_ms)
    if len(ids) > 1 and selected["status"] != "duration_match":
        return {"source":"MusicBrainz","available":False,
                "reason":"ambiguous_recording_identity","resolution_reason":selected["status"],
                "candidate_count":len(ids),"candidate_recording_urls":
                    ["https://musicbrainz.org/recording/"+x for x in ids[:12]]}
    row = selected["candidate"][1] if selected.get("candidate") else best[1]
    mbid = _compact(row.get("id"))
    detail_url = "https://musicbrainz.org/ws/2/recording/" + urllib.parse.quote(mbid) + "?" + _query(
        {
            "fmt": "json",
            "inc": "artist-credits+releases+tags+genres+artist-rels+url-rels+work-rels",
        }
    )
    detail_status = "available"
    detail_error = None
    try:
        detail = http.get_json(detail_url)
    except Exception as exc:
        detail_status = "unavailable"
        detail_error = type(exc).__name__
        detail = row

    genres = []
    for g in detail.get("genres") or []:
        if isinstance(g, dict) and _compact(g.get("name")):
            genres.append(_compact(g.get("name")))
    tags = []
    for t in detail.get("tags") or []:
        if isinstance(t, dict) and _compact(t.get("name")):
            tags.append(_compact(t.get("name")))

    recording_url = f"https://musicbrainz.org/recording/{mbid}" if mbid else None
    # Preserve the boundary between recording metadata and broad artist/album
    # enrichment. Neither set alone constitutes scientific genre validation.
    recording_genres = list(genres)
    recording_tags = list(tags)
    recording_credits = _recording_credits(detail.get("relations"), recording_url)
    # Work credits belong to the musical work, not to the recording.
    # Fetch only linked MusicBrainz work entities, and remain consultative.
    work_credits = []
    work_ids = []
    for relation in detail.get("relations") or []:
        if isinstance(relation, dict) and relation.get("target-type") == "work":
            work = relation.get("work") or {}
            wid = _compact(work.get("id")) if isinstance(work, dict) else ""
            if re.fullmatch(r"[0-9a-fA-F-]{36}", wid) and wid not in work_ids:
                work_ids.append(wid)
    allowed_roles = {"composer": "composers", "lyricist": "lyricists",
                     "writer": "lyricists", "arranger": "arrangers"}
    for wid in work_ids[:2]:
        if http.request_count >= http.budget.max_requests_per_item:
            break
        try:
            work_detail = http.get_json(
                "https://musicbrainz.org/ws/2/work/" + urllib.parse.quote(wid)
                + "?" + _query({"fmt":"json","inc":"artist-rels"}), retries=1)
        except Exception:
            continue
        work_url = "https://musicbrainz.org/work/" + wid
        for relation in work_detail.get("relations") or []:
            if not isinstance(relation, dict) or relation.get("target-type") != "artist":
                continue
            role = _compact(relation.get("type")).casefold()
            artist_data = relation.get("artist") or {}
            name = _compact(artist_data.get("name")) if isinstance(artist_data, dict) else ""
            if role in allowed_roles and name:
                work_credits.append({"name": name, "role": role,
                                     "field": allowed_roles[role],
                                     "source_url": work_url, "scope": "work",
                                     "status": "candidate"})
    # An edition is not the recording: sleeve/back/booklet scans are only
    # discovery candidates until their text and track attribution are verified.
    edition_ids = [
        _compact(release.get("id"))
        for release in (detail.get("releases") or [])
        if isinstance(release, dict) and release.get("id")
    ]
    edition_track_credits = track_credits_on_release(http, edition_ids, mbid)
    recording_credits.extend(c for c in edition_track_credits if c not in recording_credits)
    physical_documents = discover_release_documents(http, edition_ids[:3])
    physical_credit_review = inspect_scans_with_local_ocr(physical_documents, lookup_title)
    # A relation attribute is not automatically an instrument (e.g. "live").
    # Use only artist/performer relations on the recording itself.
    performers = [
        {"artist": credit["musician"], "type": credit["role"],
         "attributes": credit["instruments"]}
        for credit in recording_credits
    ]
    instruments = sorted({
        instrument
        for credit in recording_credits
        if credit["role"] in ("instrument", "performer")
        for instrument in credit["instruments"]
        if instrument.casefold() not in _NON_CREDIT_ATTRIBUTES
    })
    backing_vocals = any(
        credit["role"] == "vocal"
        and any(a.casefold() in ("backing vocals", "background vocals", "backing vocal",
                                 "background vocal") for a in credit["instruments"])
        for credit in recording_credits
    )
    lead_vocals = any(credit["role"] == "vocal" for credit in recording_credits)

    # Enrichment: release-group and primary artist metadata can carry genre/style
    # even when the recording object itself has no genre tags.
    enrichment_sources = []
    release_group_ids = []
    for release in detail.get("releases") or []:
        if not isinstance(release, dict):
            continue
        rg = release.get("release-group") if isinstance(release.get("release-group"), dict) else {}
        rgid = _compact(rg.get("id"))
        if rgid and rgid not in release_group_ids:
            release_group_ids.append(rgid)

    if release_group_ids and http.request_count < http.budget.max_requests_per_item:
        try:
            rgid = release_group_ids[0]
            rg = http.get_json(
                "https://musicbrainz.org/ws/2/release-group/" + urllib.parse.quote(rgid) + "?" + _query(
                    {"fmt": "json", "inc": "genres+tags"}
                ),
                retries=1,
            )
            for g in rg.get("genres") or []:
                if isinstance(g, dict) and _compact(g.get("name")):
                    genres.append(_compact(g.get("name")))
            for t in rg.get("tags") or []:
                if isinstance(t, dict) and _compact(t.get("name")):
                    tags.append(_compact(t.get("name")))
            enrichment_sources.append("release-group")
        except Exception:
            pass

    artist_ids = []
    for credit in detail.get("artist-credit") or row.get("artist-credit") or []:
        if not isinstance(credit, dict):
            continue
        artist_obj = credit.get("artist") if isinstance(credit.get("artist"), dict) else {}
        aid = _compact(artist_obj.get("id"))
        if aid and aid not in artist_ids:
            artist_ids.append(aid)

    if artist_ids and http.request_count < http.budget.max_requests_per_item:
        try:
            artist = http.get_json(
                "https://musicbrainz.org/ws/2/artist/" + urllib.parse.quote(artist_ids[0]) + "?" + _query(
                    {"fmt": "json", "inc": "genres+tags"}
                ),
                retries=1,
            )
            for g in artist.get("genres") or []:
                if isinstance(g, dict) and _compact(g.get("name")):
                    genres.append(_compact(g.get("name")))
            for t in artist.get("tags") or []:
                if isinstance(t, dict) and _compact(t.get("name")):
                    tags.append(_compact(t.get("name")))
            enrichment_sources.append("artist")
        except Exception:
            pass

    return {
        "source": "MusicBrainz",
        "available": True,
        "url": f"https://musicbrainz.org/recording/{mbid}" if mbid else None,
        "score": int(row.get("score") or 0),
        "canonical_title": _compact(detail.get("title") or row.get("title")),
        "canonical_artist": best[2],
        "genres": sorted(set(genres)),
        "tags": sorted(set(tags)),
        "recording_genres": sorted(set(recording_genres)),
        "recording_tags": sorted(set(recording_tags)),
        "performers": performers[:30],
        "recording_credits": recording_credits,
        "work_credits": work_credits,
        "recording_resolution_method": selected["status"],
        "recording_duration_difference_ms": selected.get("difference_ms"),
        "recording_detail_status": detail_status,
        "recording_detail_error": detail_error,
        "edition_track_credit_count": len(edition_track_credits),
        "physical_documents": physical_documents,
        "physical_credit_review": physical_credit_review,
        "instruments": sorted(set(instruments)),
        "lead_vocals": lead_vocals,
        "backing_vocals": backing_vocals,
        "enrichment_sources": enrichment_sources,
    }


def _wikidata(http: ThrottledPublicHttp, title: str, artist: str) -> dict[str, Any]:
    search = http.get_json(
        "https://www.wikidata.org/w/api.php?" + _query(
            {
                "action": "wbsearchentities",
                "search": f"{title} {artist}",
                "language": "en",
                "format": "json",
                "limit": 5,
                "type": "item",
            }
        )
    )
    candidates = search.get("search") or []
    if not isinstance(candidates, list) or not candidates:
        return {"source": "Wikidata", "available": False}

    best = None
    for row in candidates:
        if not isinstance(row, dict):
            continue
        label = _compact(row.get("label"))
        description = _compact(row.get("description"))
        blob = f"{label} {description}".casefold()
        rank = (
            1 if title.casefold() in blob else 0,
            1 if artist.casefold() in blob else 0,
        )
        if best is None or rank > best[0]:
            best = (rank, row)
    if best is None:
        return {"source": "Wikidata", "available": False}

    qid = _compact(best[1].get("id"))
    entity = http.get_json(
        "https://www.wikidata.org/w/api.php?" + _query(
            {
                "action": "wbgetentities",
                "ids": qid,
                "props": "labels|descriptions|claims|sitelinks",
                "languages": "en",
                "format": "json",
            }
        )
    )
    item = (entity.get("entities") or {}).get(qid) or {}
    claims = item.get("claims") or {}

    def claim_ids(prop: str) -> list[str]:
        out = []
        for claim in claims.get(prop) or []:
            if not isinstance(claim, dict):
                continue
            value = (((claim.get("mainsnak") or {}).get("datavalue") or {}).get("value"))
            if isinstance(value, dict) and _compact(value.get("id")):
                out.append(_compact(value.get("id")))
        return out

    genre_ids = claim_ids("P136")
    performer_ids = claim_ids("P175")
    ids = list(dict.fromkeys(genre_ids + performer_ids))
    labels: dict[str, str] = {}
    if ids:
        label_data = http.get_json(
            "https://www.wikidata.org/w/api.php?" + _query(
                {
                    "action": "wbgetentities",
                    "ids": "|".join(ids[:40]),
                    "props": "labels",
                    "languages": "en",
                    "format": "json",
                }
            )
        )
        for key, val in (label_data.get("entities") or {}).items():
            if isinstance(val, dict):
                labels[key] = _compact(((val.get("labels") or {}).get("en") or {}).get("value"))

    enwiki = _compact((((item.get("sitelinks") or {}).get("enwiki") or {}).get("title")))
    canonical_label = _compact(((item.get("labels") or {}).get("en") or {}).get("value")) or _compact(best[1].get("label"))
    return {
        "source": "Wikidata",
        "available": True,
        "url": f"https://www.wikidata.org/wiki/{qid}" if qid else None,
        "entity_id": qid,
        "label": canonical_label,
        "genres": [labels[x] for x in genre_ids if labels.get(x)],
        "performers": [labels[x] for x in performer_ids if labels.get(x)],
        "wikipedia_title": enwiki or None,
        "description": _compact(((item.get("descriptions") or {}).get("en") or {}).get("value")),
    }


def _wikipedia(http: ThrottledPublicHttp, title: str, artist: str, preferred_title: str | None) -> dict[str, Any]:
    query = preferred_title or f"{title} {artist}"
    data = http.get_json(
        "https://en.wikipedia.org/w/api.php?" + _query(
            {
                "action": "query",
                "generator": "search",
                "gsrsearch": query,
                "gsrlimit": 3,
                "prop": "extracts|info",
                "explaintext": 1,
                "inprop": "url",
                "format": "json",
                "formatversion": 2,
            }
        )
    )
    pages = ((data.get("query") or {}).get("pages") or [])
    if not isinstance(pages, list) or not pages:
        return {"source": "Wikipedia", "available": False}
    # Generator search order is not an identity guarantee: select a page
    # supporting BOTH track title and artist, otherwise abstain.
    ranked = []
    title_norm = _normalize_identity_text(title)
    artist_norm = _normalize_identity_text(artist)
    for candidate in pages:
        if not isinstance(candidate, dict):
            continue
        page_title = _normalize_identity_text(candidate.get("title"))
        page_extract = _normalize_identity_text(candidate.get("extract"))
        title_ok = page_title == title_norm or title_norm in page_extract
        artist_ok = artist_norm in page_title or artist_norm in page_extract
        if title_ok and artist_ok:
            ranked.append((int(page_title == title_norm), candidate))
    if not ranked:
        return {"source": "Wikipedia", "available": False}
    page = max(ranked, key=lambda item: item[0])[1]
    extract = _compact(page.get("extract"))
    # Only a song-specific page, never an artist/album overview, can provide
    # recording-scoped documentary instrument claims.
    article_title = _normalize_identity_text(page.get("title"))
    song_page = article_title == title_norm or (article_title.startswith(title_norm + " ") and artist_norm in article_title and article_title.endswith(" song"))
    facts = {}
    if song_page:
        # Restrict to positive performance phrases; a loose instrument mention
        # elsewhere in a story or list is not recording evidence.
        if re.search(r"(?i)\b(?:played|plays|performed|performs|using|with|on|strums?|strummed)\s+(?:an?\s+)?acoustic guitar\b", extract):
            facts["instrument:acoustic_guitar"] = {"explicit": True, "value": "acoustic guitar",
                "url": _compact(page.get("fullurl"))}
    return {
        "source": "Wikipedia",
        "fact_scope": "recording" if song_page else "unverified",
        "musical_facts": facts,
        "available": bool(extract),
        "url": _compact(page.get("fullurl")) or None,
        "title": _compact(page.get("title")),
        "extract": extract[:8000],
    }



def _normalize_identity_text(value: Any) -> str:
    # Compare Unicode spelling variants without erasing accented letters.
    text = unicodedata.normalize("NFKD", _compact(value).casefold())
    text = "".join(ch for ch in text if not unicodedata.combining(ch))
    text = re.sub(r"\s*\([^)]*\)\s*$", "", text).strip()
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def _source_matches_identity(source: dict[str, Any], title: str, artist: str) -> bool:
    name = _compact(source.get("source"))
    expected_title = _normalize_identity_text(title)
    expected_artist = _normalize_identity_text(artist)
    if not expected_title or not expected_artist:
        return False

    if name == "MusicBrainz":
        canonical_title = _normalize_identity_text(source.get("canonical_title"))
        canonical_artist = _normalize_identity_text(source.get("canonical_artist"))
        score = int(source.get("score") or 0)
        return (
            canonical_title == expected_title
            and expected_artist in canonical_artist
            and score >= 90
        )

    if name == "MusicBrainzWork":
        return (_normalize_identity_text(source.get("canonical_title")) == expected_title
                and _normalize_identity_text(source.get("canonical_artist")) == expected_artist
                and bool(source.get("work_credits")))

    if name == "Wikidata":
        label = _normalize_identity_text(source.get("label"))
        performers = " ".join(
            _normalize_identity_text(x) for x in source.get("performers") or []
        )
        description = _normalize_identity_text(source.get("description"))
        artist_supported = expected_artist in performers or expected_artist in description
        return label == expected_title and artist_supported

    if name == "Wikipedia":
        page_title = _normalize_identity_text(source.get("title"))
        extract = _normalize_identity_text(source.get("extract"))
        title_supported = page_title == expected_title or expected_title in extract
        artist_supported = expected_artist in page_title or expected_artist in extract
        return title_supported and artist_supported

    return False


def _musicbrainz_work(http: ThrottledPublicHttp, title: str, artist: str) -> dict[str, Any]:
    """Find work authorship independently of an ambiguous recording identity."""
    query = 'work:"' + title.replace('"', ' ') + '"'
    data = http.get_json("https://musicbrainz.org/ws/2/work/?" +
                         _query({"query": query, "fmt": "json", "limit": 8}), retries=0)
    rows = [x for x in (data.get("works") or []) if isinstance(x, dict)
            and _normalize_identity_text(x.get("title")) == _normalize_identity_text(title)]
    if not rows:
        return {"source":"MusicBrainzWork","available":False,"reason":"no_exact_work"}
    for row in rows[:3]:
        wid = _compact(row.get("id"))
        if not re.fullmatch(r"[0-9a-fA-F-]{36}", wid):
            continue
        if http.request_count >= http.budget.max_requests_per_item:
            break
        detail = http.get_json("https://musicbrainz.org/ws/2/work/" + wid +
                               "?" + _query({"fmt":"json","inc":"artist-rels"}), retries=0)
        roles = {"composer":"composers","lyricist":"lyricists",
                 "writer":"lyricists","arranger":"arrangers"}
        credits = []
        credited_names = []
        for rel in detail.get("relations") or []:
            if not isinstance(rel, dict) or rel.get("target-type") != "artist":
                continue
            person = rel.get("artist") or {}
            name = _compact(person.get("name")) if isinstance(person, dict) else ""
            role = _compact(rel.get("type")).casefold()
            if name and role in roles:
                credited_names.append(name)
                credits.append({"name":name,"role":role,"field":roles[role],
                                "scope":"work","status":"candidate",
                                "source_url":"https://musicbrainz.org/work/"+wid})
        if credits and any(_normalize_identity_text(artist) in _normalize_identity_text(n)
                           for n in credited_names):
            return {"source":"MusicBrainzWork","available":True,
                    "canonical_title":_compact(detail.get("title") or row.get("title")),
                    "canonical_artist":artist,"url":"https://musicbrainz.org/work/"+wid,
                    "work_credits":credits,"identity_relevant":True,
                    "recording_identity_verified":False}
    return {"source":"MusicBrainzWork","available":False,
            "reason":"work_artist_not_confirmed"}


def research_public_sources(identity: dict[str, Any], budget: NetworkBudget) -> dict[str, Any]:
    title = _compact(identity.get("title"))
    artist = _compact(identity.get("artist"))
    if not title or not artist:
        return {
            "schema": "EZStudioLab_assistant.profile-public-research.v1",
            "status": "abstain",
            "reason": "identity_incomplete",
            "sources": [],
            "request_count": 0,
        }

    http = ThrottledPublicHttp(budget)
    sources = []
    warnings = []

    try:
        mb = _musicbrainz(http, title, artist, duration_ms=identity.get("catalog_duration_ms"))
        sources.append(mb)
    except Exception as exc:
        warnings.append(f"MusicBrainz:{type(exc).__name__}:{exc}")
        mb = {"source": "MusicBrainz", "available": False}

    # Authorship is work-scoped and can be found despite multiple recording candidates.
    try:
        sources.append(_musicbrainz_work(http, title, artist))
    except Exception as exc:
        warnings.append(f"MusicBrainzWork:{type(exc).__name__}:{exc}")

    # Search Wikipedia before Wikidata: the former can provide a recording-specific
    # narrative; Wikidata may consume multiple calls for an unrelated entity.
    # Do not claim either is relevant without the subsequent identity check.
    try:
        wp = _wikipedia(http, title, artist, None)
        sources.append(wp)
    except Exception as exc:
        warnings.append(f"Wikipedia:{type(exc).__name__}:{exc}")

    try:
        wd = _wikidata(http, title, artist)
        sources.append(wd)
    except Exception as exc:
        warnings.append(f"Wikidata:{type(exc).__name__}:{exc}")

    available = [x for x in sources if isinstance(x, dict) and x.get("available")]
    relevant = []
    for source in available:
        is_relevant = _source_matches_identity(source, title, artist)
        source["identity_relevant"] = is_relevant
        if is_relevant:
            relevant.append(source)
        else:
            warnings.append(f"irrelevant_external_source:{_compact(source.get('source'))}")

    return {
        "schema": "EZStudioLab_assistant.profile-public-research.v1",
        "status": "available" if relevant else "unavailable",
        "reason": None if relevant else "no_reliable_external_reference",
        "sources": sources,
        "warnings": warnings,
        "request_count": http.request_count,
        "relevant_source_count": len(relevant),
    }


def _genre_tokens(labels: list[str]) -> set[str]:
    tokens: set[str] = set()
    vocabulary = {
        "rock": "rock",
        "pop": "pop",
        "progressive": "progressive",
        "prog": "progressive",
        "folk": "folk",
        "country": "country",
        "jazz": "jazz",
        "blues": "blues",
        "soul": "soul",
        "funk": "funk",
        "metal": "metal",
        "punk": "punk",
        "electronic": "electronic",
        "disco": "disco",
        "reggae": "reggae",
        "singer-songwriter": "singer-songwriter",
    }
    for label in labels:
        lc = _compact(label).casefold()
        for key, canonical in vocabulary.items():
            if key in lc:
                tokens.add(canonical)
    return tokens



_NON_INSTRUMENT_ATTRIBUTES = {
    "live",
    "additional",
    "guest",
    "solo",
    "lead",
    "rhythm",
    "background",
    "backing",
}


def _filter_instruments(values: list[Any]) -> list[str]:
    out = []
    for value in values:
        label = _compact(value)
        if not label:
            continue
        if label.casefold() in _NON_INSTRUMENT_ATTRIBUTES:
            continue
        if label not in out:
            out.append(label)
    return out


def _contains_profile_term(text: str) -> bool:
    normalized = _normalize_identity_text(text)
    if not normalized:
        return False
    terms = (
        "genre", "rock", "pop", "folk", "jazz", "blues", "country",
        "guitar", "drum", "drums", "bass", "keyboard", "piano", "organ",
        "vocal", "vocals", "choir", "backing vocal", "background vocal",
    )
    padded = f" {normalized} "
    for term in terms:
        term_norm = _normalize_identity_text(term)
        if f" {term_norm} " in padded:
            return True
    return False


def _source_profile_evidence(source: dict[str, Any], identity_title: str) -> dict[str, Any]:
    name = _compact(source.get("source"))
    genres = [_compact(x) for x in source.get("genres") or [] if _compact(x)]
    if name == "MusicBrainz":
        genres = sorted(set(genres + [_compact(x) for x in source.get("tags") or [] if _compact(x)]))
    instruments = _filter_instruments(source.get("instruments") or [])
    lead = bool(source.get("lead_vocals"))
    backing = bool(source.get("backing_vocals"))

    textual_profile_signal = False
    if name == "Wikipedia":
        page_title = _normalize_identity_text(source.get("title"))
        expected_title = _normalize_identity_text(identity_title)
        extract = _compact(source.get("extract"))
        is_song_page = bool(page_title and expected_title and page_title == expected_title)
        textual_profile_signal = is_song_page and _contains_profile_term(extract)

    return {
        "genres": genres,
        "instruments": instruments,
        "lead_vocals": lead,
        "backing_vocals": backing,
        "textual_profile_signal": textual_profile_signal,
        "has_profile_evidence": bool(genres or instruments or lead or backing or textual_profile_signal),
    }


def build_review_payload(row: dict[str, Any], research: dict[str, Any], *, min_confidence: float = 0.75) -> dict[str, Any]:
    identity = normalize_track_identity(row)
    identity_rows = [
        x
        for x in research.get("sources") or []
        if isinstance(x, dict)
        and x.get("available")
        and _source_matches_identity(
            x,
            _compact(identity.get("title")),
            _compact(identity.get("artist")),
        )
    ]
    source_rows = [
        x
        for x in identity_rows
        if _source_profile_evidence(x, _compact(identity.get("title")))["has_profile_evidence"]
    ]

    external_genres: list[str] = []
    external_instruments: list[str] = []
    external_lead = False
    external_backing = False
    source_payload = []

    for source in source_rows:
        name = _compact(source.get("source"))
        profile_evidence = _source_profile_evidence(source, _compact(identity.get("title")))
        genres = profile_evidence["genres"]
        if name == "MusicBrainz":
            genres = sorted(set(genres + [_compact(x) for x in source.get("tags") or [] if _compact(x)]))
        external_genres.extend(genres)

        if name == "MusicBrainz":
            external_instruments.extend(profile_evidence["instruments"])
            external_lead = bool(profile_evidence["lead_vocals"])
            external_backing = bool(profile_evidence["backing_vocals"])

        source_payload.append(
            {
                "name": name,
                "type": "public_reference",
                "url": source.get("url"),
                "identity_relevant": True,
                "evidence": {
                    "genres": genres,
                    "instruments": source.get("instruments") or [],
                    "performers": source.get("performers") or [],
                    "recording_credits": source.get("recording_credits") or [],
                    "description": source.get("description"),
                    "extract": source.get("extract"),
                    "lead_vocals": source.get("lead_vocals"),
                    "backing_vocals": source.get("backing_vocals"),
                },
            }
        )

    # Preserve consulted public URLs without treating them as validated evidence.
    recorded_urls = {v.get("url") for v in source_payload}
    for candidate in research.get("sources") or []:
        if not isinstance(candidate, dict):
            continue
        url = candidate.get("url")
        if not isinstance(url, str) or not url.startswith(("https://", "http://")) or url in recorded_urls:
            continue
        source_payload.append({
            "name": _compact(candidate.get("source")) or "Public reference",
            "type": "consulted_reference",
            "url": url,
            "identity_relevant": candidate in identity_rows,
            "evidence_accepted": False,
            "evidence": {},
        })
        recorded_urls.add(url)

    external_genres = sorted(set(external_genres))
    external_instruments = sorted(set(external_instruments))

    profile_view = (((row.get("diagnostics") or {}).get("profile_view")) or {})
    genre_h = profile_view.get("genre_hierarchy") or {}
    lab_genre = _compact(((genre_h.get("primary") or {}).get("genre")))
    vocal = profile_view.get("vocal_profile") or {}
    instrumentation = profile_view.get("instrumentation") or {}

    lab_genre_tokens = _genre_tokens([lab_genre])
    external_genre_tokens = _genre_tokens(external_genres)
    genre_overlap = sorted(lab_genre_tokens & external_genre_tokens)
    genre_decision = (
        "match"
        if genre_overlap
        else ("mismatch" if lab_genre_tokens and external_genre_tokens else "not_scored")
    )

    lab_instruments = [
        _compact(x.get("label"))
        for x in instrumentation.get("candidates") or []
        if isinstance(x, dict) and _compact(x.get("label"))
    ]
    external_cf = " ".join(external_instruments).casefold()
    instrument_matches = [x for x in lab_instruments if x.casefold() in external_cf]

    dimensions = vocal.get("dimensions") or {}
    lead_decision = _compact((dimensions.get("lead_vocal") or {}).get("decision"))
    backing_decision = _compact((dimensions.get("backing_vocals") or {}).get("decision"))
    choir_decision = _compact((dimensions.get("choir") or {}).get("decision"))

    identity_source_count = len(identity_rows)
    useful_source_count = len(source_rows)
    identity_conf = float(identity.get("confidence") or 0.0)

    external_identity_verified = False
    external_identity_source = None
    for source in identity_rows:
        if _compact(source.get("source")) != "MusicBrainz":
            continue
        canonical_title = _compact(source.get("canonical_title"))
        canonical_artist = _compact(source.get("canonical_artist"))
        mb_score = int(source.get("score") or 0)
        title_match = bool(canonical_title) and canonical_title.casefold() == _compact(identity.get("title")).casefold()
        artist_match = bool(canonical_artist) and _compact(identity.get("artist")).casefold() in canonical_artist.casefold()
        if title_match and artist_match and mb_score >= 90:
            external_identity_verified = True
            external_identity_source = "MusicBrainz"
            break

    effective_identity_conf = max(identity_conf, 0.95) if external_identity_verified else identity_conf
    evidence_conf = min(1.0, 0.45 + 0.18 * useful_source_count)
    overall_confidence = round(min(effective_identity_conf, evidence_conf), 3)

    scored_dimensions = []
    if genre_decision in {"match", "mismatch"}:
        scored_dimensions.append("genre")
    if external_lead and lead_decision == "present":
        scored_dimensions.append("lead_vocal")
    if external_backing and backing_decision in {"present", "possible"}:
        scored_dimensions.append("backing_vocals")
    if external_instruments and instrument_matches:
        scored_dimensions.append("instrumentation")

    external_validation_available = bool(
        external_identity_verified
        and useful_source_count >= 1
        and scored_dimensions
    )
    complete = external_validation_available

    confidence_band = (
        "high"
        if overall_confidence >= float(min_confidence)
        else ("moderate" if external_validation_available else "unavailable")
    )

    return {
        "run_id": int(row["run_id"]),
        "revision_key": f"profile-{int(row['run_id'])}-assistant-auto-v1",
        "reviewer": "assistant-auto",
        "status": "completed" if complete else "draft",
        "identity": {
            **identity,
            "research_request_count": int(research.get("request_count") or 0),
            "external_identity_verified": external_identity_verified,
            "external_identity_source": external_identity_source,
            "effective_confidence": round(effective_identity_conf, 3),
        },
        "sources": source_payload,
        "external_validation": {
            "available": external_validation_available,
            "analysis_dependency": False,
            "unscored_dimensions_unchanged": True,
            "scored_dimensions": scored_dimensions,
            "confidence_band": confidence_band,
            "reason": None if external_validation_available else "no_reliable_dimension_level_reference",
        },
        "consensus": {
            "genre_labels": external_genres,
            "genre_tokens": sorted(external_genre_tokens),
            "instruments_credited_or_related": external_instruments,
            "lead_vocals_supported": external_lead,
            "backing_vocals_supported": external_backing,
            "external_identity_source_count": identity_source_count,
            "external_profile_evidence_source_count": useful_source_count,
        },
        "comparison": {
            "genre": {
                "lab": lab_genre or None,
                "external": external_genres,
                "decision": genre_decision,
                "overlap": genre_overlap,
            },
            "lead_vocal": {
                "lab": lead_decision or None,
                "external_supported": external_lead,
                "decision": (
                    "match"
                    if external_lead and lead_decision == "present"
                    else "not_scored"
                ),
            },
            "backing_vocals": {
                "lab": backing_decision or None,
                "external_supported": external_backing,
                "decision": (
                    "underconfident_match"
                    if external_backing and backing_decision == "possible"
                    else ("match" if external_backing and backing_decision == "present" else "not_scored")
                ),
            },
            "choir": {
                "lab": choir_decision or None,
                "external_supported": False,
                "decision": "not_inferred_from_backing_vocals",
            },
            "instrumentation": {
                "lab_candidates": lab_instruments,
                "external": external_instruments,
                "matched_candidates": instrument_matches,
                "decision": (
                    "partial_match"
                    if instrument_matches and len(instrument_matches) < len(lab_instruments)
                    else ("match" if instrument_matches else "not_scored")
                ),
            },
            "research_warnings": research.get("warnings") or [],
        },
        "confidence": overall_confidence,
    }


def enrich_review_payload_with_documentary(payload: dict[str, Any], row: dict[str, Any], research: dict[str, Any]) -> dict[str, Any]:
    """Add advisory work/recording metadata to the existing LAB comparison JSON.

    Uses only already consulted identity-relevant sources. Unknown credits remain unknown.
    """
    from profile_catalogue_suggestions import build_catalogue_suggestions
    from profile_documentary_reconcile import reconcile

    identity = row.get("normalized_identity") or normalize_track_identity(row)
    title = _compact(identity.get("title"))
    artist = _compact(identity.get("artist"))
    candidates = []
    for source in research.get("sources") or []:
        if not isinstance(source, dict) or not source.get("available"):
            continue
        if not _source_matches_identity(source, title, artist):
            continue
        if source.get("source") == "MusicBrainz":
            credited = _compact(source.get("canonical_artist"))
            if credited:
                candidates.append({"artist_candidates": [credited]})
    # No snippet-based assertion if the normal workflow did not perform such search.
    documentary = reconcile(title, artist, {"candidates": []}, candidates)
    suggestions = build_catalogue_suggestions(title, artist, documentary)
    # An identity-matched MusicBrainz result supports *candidate* performer
    # metadata, not identification of the precise recording.
    for source in research.get("sources") or []:
        if not isinstance(source, dict) or source.get("source") != "MusicBrainz":
            continue
        if not source.get("available") or not _source_matches_identity(source, title, artist):
            continue
        url = source.get("url")
        if not isinstance(url, str) or not url.startswith("https://musicbrainz.org/recording/"):
            continue
        title_from_source = _compact(source.get("canonical_title"))
        performer_from_source = _compact(source.get("canonical_artist"))
        if title_from_source:
            suggestions["work"]["full_title"] = {
                "value": title_from_source, "original": title,
                "status": "candidate", "confidence": None,
                "sources": [url], "human_validated": False,
            }
        if performer_from_source:
            suggestions["recording"]["performers"] = [{
                "value": performer_from_source, "original": artist or None,
                "status": "candidate", "confidence": None,
                "sources": [url], "human_validated": False,
            }]
            suggestions["missing"]["performers"] = None
        # Recording musician credits are not work authorship/arrangement proof.
        # Retain only explicitly attributed recording credits and their scope.
        credits = []
        for credit in (source.get("recording_credits") or [])[:30]:
            if not isinstance(credit, dict) or credit.get("scope") != "recording":
                continue
            musician, role = _compact(credit.get("musician")), _compact(credit.get("role"))
            credit_url = credit.get("source_url")
            if musician and role and credit_url == url:
                credits.append({"name": musician, "role": role,
                                "source": url, "status": "candidate",
                                "recording_only": True})
        suggestions["recording"]["musician_credits"] = credits
        for work_credit in (source.get("work_credits") or [])[:30]:
            if not isinstance(work_credit, dict) or work_credit.get("scope") != "work":
                continue
            field = work_credit.get("field")
            url_credit = work_credit.get("source_url")
            name = _compact(work_credit.get("name"))
            if field not in ("lyricists", "composers", "arrangers") or not name:
                continue
            if not isinstance(url_credit, str) or not url_credit.startswith("https://musicbrainz.org/work/"):
                continue
            proposal = {"value": name, "original": None, "status": "candidate",
                        "confidence": None, "sources": [url_credit], "human_validated": False}
            destination = suggestions["recording"] if field == "arrangers" else suggestions["work"]
            if not any(item.get("value") == name for item in destination[field]):
                destination[field].append(proposal)
            suggestions["missing"][field] = None
        break
    # Work-level authorship can be recovered even when the specific recording is
    # ambiguous. This remains a candidate, never a verified recording credit.
    for source in research.get("sources") or []:
        if not isinstance(source, dict) or source.get("source") != "MusicBrainzWork":
            continue
        if not source.get("available") or not _source_matches_identity(source, title, artist):
            continue
        for credit in source.get("work_credits") or []:
            if not isinstance(credit, dict) or credit.get("scope") != "work":
                continue
            field = credit.get("field")
            name = _compact(credit.get("name"))
            link = credit.get("source_url")
            if field not in ("lyricists", "composers", "arrangers") or not name:
                continue
            if not isinstance(link, str) or not link.startswith("https://musicbrainz.org/work/"):
                continue
            target = suggestions["recording"] if field == "arrangers" else suggestions["work"]
            if not any(x.get("value") == name for x in target[field]):
                target[field].append({"value":name,"original":None,
                                      "status":"candidate","confidence":None,
                                      "sources":[link],"human_validated":False})
            suggestions["missing"][field] = None
        break
    urls = sorted({x.get("url") for x in (research.get("sources") or [])
                   if isinstance(x, dict) and x.get("available") and
                   _source_matches_identity(x, title, artist) and
                   isinstance(x.get("url"), str) and x["url"].startswith("https://")})
    suggestions["consulted_identity_sources"] = urls
    suggestions["workflow"] = "profile_auto_review"
    result = dict(payload)
    comparison = dict(result.get("comparison") or {})
    comparison["documentary_catalogue_suggestions"] = suggestions
    result["comparison"] = comparison
    return result


def enrich_review_payload_with_ai(
    payload: dict[str, Any],
    ai_review: dict[str, Any],
) -> dict[str, Any]:
    result = dict(payload)
    consensus = dict(result.get("consensus") or {})
    comparison = dict(result.get("comparison") or {})
    compact_ai = {
        "schema": ai_review.get("schema"),
        "status": ai_review.get("status"),
        "provider": ai_review.get("provider"),
        "model": ai_review.get("model"),
        "confidence": ai_review.get("confidence"),
        "summary": ai_review.get("summary"),
        "response_id": ai_review.get("response_id"),
        "reason": ai_review.get("reason"),
    }
    consensus["assistant_ai"] = compact_ai
    comparison["assistant_ai"] = {
        "status": ai_review.get("status"),
        "dimensions": ai_review.get("dimensions") if isinstance(ai_review.get("dimensions"), dict) else {},
        "coverage": ai_review.get("coverage") if isinstance(ai_review.get("coverage"), dict) else None,
        "advisory_only": True,
        "scientific_preset_mutation_allowed": False,
        "automatic_preset_promotion_allowed": False,
    }
    # PROFILE_DRAFT_BRIDGE_R1: preserve validation provenance in persisted JSON.
    comparison["external_validation"] = dict(result.get("external_validation") or {})
    result["consensus"] = consensus
    result["comparison"] = comparison
    if ai_review.get("status") == "completed":
        # Content-addressed history: new conclusions get new revision IDs.
        fingerprint = hashlib.sha256(json.dumps(
            {"ai": ai_review, "external_validation": comparison["external_validation"],
             "documentary_catalogue_suggestions": comparison.get("documentary_catalogue_suggestions")},
            ensure_ascii=False, sort_keys=True, default=str
        ).encode("utf-8")).hexdigest()[:16]
        result["revision_key"] = f"profile-{int(result['run_id'])}-assistant-ai-{fingerprint}"
        result["reviewer"] = "assistant-auto+ai"
    return result


def _run_ai_profile_review(
    provider: AIReviewProvider,
    row: dict[str, Any],
    research: dict[str, Any],
) -> dict[str, Any]:
    try:
        result = provider.review_profile(row, research)
        if isinstance(result, dict):
            return result
    except Exception as exc:
        return {
            "schema": "EZStudioLab_assistant.ai-profile-review.v1",
            "status": "error",
            "provider": getattr(provider, "provider_name", "unknown"),
            "model": None,
            "confidence": 0.0,
            "summary": None,
            "dimensions": {},
            "sources": [],
            "reason": f"{type(exc).__name__}:{exc}"[:500],
        }
    return {
        "schema": "EZStudioLab_assistant.ai-profile-review.v1",
        "status": "error",
        "provider": getattr(provider, "provider_name", "unknown"),
        "model": None,
        "confidence": 0.0,
        "summary": None,
        "dimensions": {},
        "sources": [],
        "reason": "ai_provider_invalid_result",
    }


def _profile_ai_has_scored_evidence(ai_review: dict[str, Any]) -> bool:
    """A syntactically completed generation is not a reviewed musical dimension."""
    dimensions = ai_review.get("dimensions")
    if not isinstance(dimensions, dict):
        return False
    for key in ("genre", "instrumentation", "lead_vocal", "lead_vocals", "backing_vocals", "choir"):
        item = dimensions.get(key)
        if not isinstance(item, dict) or item.get("decision") not in {"match", "mismatch"}:
            continue
        conf = item.get("confidence")
        if isinstance(conf, bool) or not isinstance(conf, (int, float)) or not 0.0 < float(conf) <= 1.0:
            continue
        evidence = item.get("evidence")
        if isinstance(evidence, str) and evidence.strip():
            return True
        if isinstance(evidence, list) and any(
            (isinstance(entry, str) and entry.strip())
            or (isinstance(entry, dict) and bool(entry))
            for entry in evidence
        ):
            return True
    return False


def _profile_review_should_submit(payload: dict[str, Any], ai_review: dict[str, Any]) -> bool:
    """Persist advisory drafts without asserting verified external evidence."""
    if ai_review.get("status") != "completed":
        return False
    if not str(payload.get("revision_key") or "").startswith("profile-"):
        return False
    if payload.get("status") not in {"draft", "completed"}:
        return False
    # Completed remains reserved for independently verified sources.
    if payload.get("status") == "completed":
        validation = payload.get("external_validation")
        if not isinstance(validation, dict):
            return False
        dimensions = validation.get("scored_dimensions")
        identity = payload.get("identity")
        return (
            validation.get("available") is True
            and isinstance(dimensions, list) and bool(dimensions)
            and validation.get("analysis_dependency") is False
            and isinstance(identity, dict)
            and identity.get("external_identity_verified") is True
            and bool(payload.get("sources"))
        )
    # A draft is stored as an observation only; never promoted or externally validated.
    return True


def auto_review_once(
    client: Any,
    *,
    limit: int = 100,
    dry_run: bool = True,
    min_confidence: float = 0.75,
    budget: NetworkBudget | None = None,
    ai_provider: AIReviewProvider | None = None,
) -> dict[str, Any]:
    snapshot = profile_review_queue_snapshot(client, limit=limit)
    rows = snapshot.get("reviews") or []
    if not rows:
        return {
            "schema": "EZStudioLab_assistant.profile-auto-review-once.v1",
            "status": "idle",
            "dry_run": bool(dry_run),
            "submitted": False,
        }

    selected = None
    for row in rows:
        identity = row.get("normalized_identity") or normalize_track_identity(row)
        if _compact(identity.get("title")) and _compact(identity.get("artist")):
            selected = row
            break

    if selected is None:
        return {
            "schema": "EZStudioLab_assistant.profile-auto-review-once.v1",
            "status": "abstain",
            "reason": "no_resolvable_identity",
            "dry_run": bool(dry_run),
            "submitted": False,
        }

    identity = selected.get("normalized_identity") or normalize_track_identity(selected)
    research = research_public_sources(identity, budget or NetworkBudget())
    payload = build_review_payload(selected, research, min_confidence=min_confidence)
    payload = enrich_review_payload_with_documentary(payload, selected, research)
    resolved_ai = ai_provider or build_ai_provider_from_env()
    ai_review = _run_ai_profile_review(resolved_ai, selected, research)
    payload = enrich_review_payload_with_ai(payload, ai_review)

    result = {
        "schema": "EZStudioLab_assistant.profile-auto-review-once.v1",
        "status": payload["status"],
        "dry_run": bool(dry_run),
        "submitted": False,
        "run_id": int(selected["run_id"]),
        "identity": identity,
        "network": {
            "request_count": research.get("request_count", 0),
            "max_requests_per_item": (budget or NetworkBudget()).max_requests_per_item,
            "min_interval_seconds": (budget or NetworkBudget()).min_interval_seconds,
            "max_response_bytes": (budget or NetworkBudget()).max_response_bytes,
        },
        "payload": payload,
        "ai": {
            "status": ai_review.get("status"),
            "provider": ai_review.get("provider"),
            "model": ai_review.get("model"),
            "confidence": ai_review.get("confidence"),
            "reason": ai_review.get("reason"),
        },
    }

    if dry_run:
        return result

    if not _profile_review_should_submit(payload, ai_review):
        result["reason"] = "external_validation_unavailable" if payload["status"] == "draft" else "revision_not_submittable"
        return result

    submit = client.submit_profile_review(payload)
    result["submitted"] = bool(submit.get("ok"))
    result["submit_result"] = submit
    result["status"] = payload["status"] if result["submitted"] else "submit_failed"
    return result

def auto_review_sweep(
    client: Any,
    *,
    limit: int = 100,
    max_items: int = 5,
    dry_run: bool = True,
    min_confidence: float = 0.75,
    budget_factory: Any | None = None,
    skip_run_ids: set[int] | None = None,
    ai_provider: AIReviewProvider | None = None,
) -> dict[str, Any]:
    snapshot = profile_review_queue_snapshot(client, limit=limit)
    rows = snapshot.get("reviews") or []
    skipped_ids = {int(x) for x in (skip_run_ids or set())}
    eligible_rows = [
        row
        for row in rows
        if int(row.get("run_id") or 0) not in skipped_ids
    ]

    results = []
    submitted_count = 0
    completed_count = 0
    draft_count = 0
    abstain_count = 0
    resolved_ai = ai_provider or build_ai_provider_from_env()

    for row in eligible_rows[:max(1, int(max_items))]:
        identity = row.get("normalized_identity") or normalize_track_identity(row)
        if not _compact(identity.get("title")) or not _compact(identity.get("artist")):
            results.append(
                {
                    "run_id": int(row.get("run_id") or 0),
                    "status": "abstain",
                    "reason": "identity_incomplete",
                    "submitted": False,
                }
            )
            abstain_count += 1
            continue

        budget = budget_factory() if callable(budget_factory) else NetworkBudget()
        research = research_public_sources(identity, budget)
        payload = build_review_payload(row, research, min_confidence=min_confidence)
        payload = enrich_review_payload_with_documentary(payload, row, research)
        ai_review = _run_ai_profile_review(resolved_ai, row, research)
        payload = enrich_review_payload_with_ai(payload, ai_review)

        item = {
            "run_id": int(row["run_id"]),
            "identity": identity,
            "status": payload["status"],
            "confidence": payload.get("confidence"),
            "submitted": False,
            "network_request_count": int(research.get("request_count") or 0),
            "research_reason": research.get("reason"),
            "warnings": research.get("warnings") or [],
            "payload": payload if dry_run else None,
            "ai": {
                "status": ai_review.get("status"),
                "provider": ai_review.get("provider"),
                "model": ai_review.get("model"),
                "confidence": ai_review.get("confidence"),
                "reason": ai_review.get("reason"),
            },
        }

        if payload["status"] == "completed":
            completed_count += 1
        elif payload["status"] == "draft":
            draft_count += 1
        else:
            abstain_count += 1

        if not dry_run and _profile_review_should_submit(payload, ai_review):
            submit = client.submit_profile_review(payload)
            item["submitted"] = bool(submit.get("ok"))
            item["submit_result"] = submit
            if item["submitted"]:
                submitted_count += 1
            else:
                item["reason"] = "submit_failed"

        results.append(item)

    return {
        "schema": "EZStudioLab_assistant.profile-auto-review-sweep.v1",
        "dry_run": bool(dry_run),
        "examined": len(results),
        "completed": completed_count,
        "draft": draft_count,
        "abstain": abstain_count,
        "submitted": submitted_count,
        "skipped_cooldown": max(0, len(rows) - len(eligible_rows)),
        "queue_count": len(rows),
        "results": results,
    }

def _load_watch_state(path: str | Path) -> dict[str, Any]:
    state_path = Path(path)
    if not state_path.is_file():
        return {"schema": "EZStudioLab_assistant.profile-auto-review-watch-state.v1", "runs": {}}
    try:
        data = json.loads(state_path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {"schema": "EZStudioLab_assistant.profile-auto-review-watch-state.v1", "runs": {}}
    if not isinstance(data, dict):
        return {"schema": "EZStudioLab_assistant.profile-auto-review-watch-state.v1", "runs": {}}
    runs = data.get("runs")
    if not isinstance(runs, dict):
        runs = {}
    return {
        "schema": "EZStudioLab_assistant.profile-auto-review-watch-state.v1",
        "runs": runs,
    }


def _save_watch_state(path: str | Path, state: dict[str, Any]) -> None:
    state_path = Path(path)
    state_path.parent.mkdir(parents=True, exist_ok=True)
    tmp = state_path.with_suffix(state_path.suffix + ".tmp")
    tmp.write_text(json.dumps(state, ensure_ascii=True, indent=2), encoding="utf-8")
    tmp.replace(state_path)


def auto_review_watch(
    client: Any,
    *,
    state_file: str | Path,
    poll_seconds: float = 900.0,
    draft_cooldown_seconds: float = 86400.0,
    abstain_cooldown_seconds: float = 604800.0,
    limit: int = 100,
    max_items: int = 5,
    iterations: int = 0,
    min_confidence: float = 0.75,
    budget_factory: Any | None = None,
    now_fn: Any | None = None,
    sleep_fn: Any | None = None,
    emit_fn: Any | None = None,
    ai_provider: AIReviewProvider | None = None,
) -> dict[str, Any]:
    now_fn = now_fn or time.time
    sleep_fn = sleep_fn or time.sleep
    emit_fn = emit_fn or (lambda event: print(json.dumps(event, ensure_ascii=True), flush=True))

    state = _load_watch_state(state_file)
    cycle = 0
    totals = {
        "examined": 0,
        "completed": 0,
        "draft": 0,
        "abstain": 0,
        "submitted": 0,
    }

    while True:
        now = float(now_fn())
        runs_state = state.setdefault("runs", {})
        cooldown_ids = set()
        for key, value in list(runs_state.items()):
            try:
                run_id = int(key)
            except (TypeError, ValueError):
                continue
            if not isinstance(value, dict):
                continue
            next_retry = float(value.get("next_retry_epoch") or 0.0)
            if next_retry > now:
                cooldown_ids.add(run_id)

        sweep = auto_review_sweep(
            client,
            limit=limit,
            max_items=max_items,
            dry_run=False,
            min_confidence=min_confidence,
            budget_factory=budget_factory,
            skip_run_ids=cooldown_ids,
            ai_provider=ai_provider,
        )

        cycle += 1
        for key in totals:
            totals[key] += int(sweep.get(key) or 0)

        for item in sweep.get("results") or []:
            if not isinstance(item, dict):
                continue
            run_id = int(item.get("run_id") or 0)
            if run_id <= 0:
                continue
            key = str(run_id)
            status = str(item.get("status") or "")
            if status == "completed" and bool(item.get("submitted")):
                runs_state.pop(key, None)
                continue

            previous = runs_state.get(key) if isinstance(runs_state.get(key), dict) else {}
            attempts = int(previous.get("attempts") or 0) + 1
            if status == "draft":
                cooldown = max(60.0, float(draft_cooldown_seconds))
            else:
                cooldown = max(60.0, float(abstain_cooldown_seconds))
            runs_state[key] = {
                "status": status or "abstain",
                "attempts": attempts,
                "last_checked_epoch": now,
                "next_retry_epoch": now + cooldown,
                "reason": item.get("reason") or item.get("research_reason"),
                "confidence": item.get("confidence"),
            }

        state["updated_at_epoch"] = now
        _save_watch_state(state_file, state)

        event = {
            "schema": "EZStudioLab_assistant.profile-auto-review-watch-cycle.v1",
            "cycle": cycle,
            "state_file": str(state_file),
            "cooldown_count": len(cooldown_ids),
            "sweep": {
                "queue_count": sweep.get("queue_count"),
                "examined": sweep.get("examined"),
                "completed": sweep.get("completed"),
                "draft": sweep.get("draft"),
                "abstain": sweep.get("abstain"),
                "submitted": sweep.get("submitted"),
                "skipped_cooldown": sweep.get("skipped_cooldown"),
            },
            "totals": dict(totals),
        }
        emit_fn(event)

        if iterations > 0 and cycle >= int(iterations):
            return {
                "schema": "EZStudioLab_assistant.profile-auto-review-watch-summary.v1",
                "cycles": cycle,
                "state_file": str(state_file),
                "totals": totals,
            }

        sleep_fn(max(60.0, float(poll_seconds)))

