"""Source scope for music documentary evidence; never infer an exact recording.

Ranking is discovery-only. A matching artist/title is NOT a verified recording.
"""
from __future__ import annotations
import re
import unicodedata

def tokens(value):
    normalized=unicodedata.normalize("NFKD",str(value or "").casefold())
    normalized="".join(c for c in normalized if not unicodedata.combining(c))
    return re.findall(r"[a-z0-9]+",normalized)

ARRANGEMENT=re.compile(r"(?i)\b(arrang(?:ed|ement|er)|full score|concert band|jazz ensemble|"
                       r"satb|ssa|ttbb|choir score|choral|tribute|medley|sheet music|"
                       r"marching band|orchestrat(?:ion|ed))\b")
LIVE=re.compile(r"(?i)\b(live|concert|remix|cover version|karaoke|tribute|medley)\b")
OFFICIAL=re.compile(r"(?i)\b(official|publisher|éditeur|edition|édition|"
                   r"published by|hal leonard|universal music publishing)\b")

def classify_source(document,identity):
    headline=str(document.get("title") or "")
    snippet=str(document.get("snippet") or "")
    url=str(document.get("url") or "")
    text=headline+" "+snippet+" "+str(document.get("text") or "")[:1600]
    haystack=set(tokens(text+" "+url))
    title=tokens(identity.get("title"))
    artist=tokens(identity.get("artist"))
    title_match=bool(title) and all(t in haystack for t in title)
    artist_match=bool(artist) and all(t in haystack for t in artist)
    is_arrangement=bool(ARRANGEMENT.search(headline+" "+snippet))
    is_other_version=bool(LIVE.search(headline+" "+snippet))
    is_published=bool(OFFICIAL.search(text))
    if not title_match:
        scope="unrelated_or_uncertain"
    elif is_arrangement:
        scope="arrangement_or_score"
    elif is_other_version:
        scope="version_requires_matching"
    elif artist_match:
        scope="artist_title_matched_recording_unconfirmed"
    else:
        scope="work_only"
    return {
        "scope":scope,"title_mentioned":title_match,"artist_mentioned":artist_match,
        "publisher_indication":is_published,
        "recording_verified":False,
        "evidence_eligible_for_lab":False,
    }
