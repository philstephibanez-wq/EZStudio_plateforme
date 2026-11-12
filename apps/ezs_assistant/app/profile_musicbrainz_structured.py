"""Structured MusicBrainz discovery (recording candidates only).

Public WS/2 JSON, bounded and read-only. A title/artist match is NOT a
recording identity match, musical measurement, personnel credit, or verdict.
"""
from __future__ import annotations
import json
import re
import urllib.parse
import urllib.request

def _lucene_term(value):
    return '"' + re.sub(r'["\\\\]', ' ', str(value or "").strip())[:140] + '"'

def discover_recordings(title, artist, *, transport=None, limit=5):
    if not str(title or "").strip() or not str(artist or "").strip():
        return {"status":"identity_incomplete","candidates":[]}
    query="recording:"+_lucene_term(title)+" AND artist:"+_lucene_term(artist)
    url="https://musicbrainz.org/ws/2/recording/?" + urllib.parse.urlencode(
        {"query":query,"fmt":"json","limit":max(1,min(int(limit),5))})
    request=urllib.request.Request(url,headers={
        "Accept":"application/json",
        "User-Agent":"EZStudioLabAssistant/1.6 (documentary-candidate-research)"})
    try:
        if transport is None:
            with urllib.request.urlopen(request,timeout=12) as response:
                data=json.loads(response.read(200000))
        else:
            data=transport(request)
        if not isinstance(data,dict) or not isinstance(data.get("recordings"),list):
            return {"status":"invalid_response","candidates":[]}
        candidates=[]
        for item in data["recordings"][:5]:
            if not isinstance(item,dict):
                continue
            mid=item.get("id")
            if not isinstance(mid,str) or not re.fullmatch(
                r"[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}",mid):
                continue
            credits=item.get("artist-credit") or []
            names=[]
            for credit in credits:
                if isinstance(credit,dict):
                    person=credit.get("artist") or {}
                    name=credit.get("name") or person.get("name")
                    if isinstance(name,str):names.append(name[:120])
            candidates.append({
                "url":"https://musicbrainz.org/recording/"+mid,
                "title":str(item.get("title") or "")[:180],
                "artist_credit":names,
                "duration_ms":item.get("length") if isinstance(item.get("length"),int) and not isinstance(item.get("length"),bool) else None,
                "source_scope":"musicbrainz_recording_candidate",
                "recording_identity_verified":False,
                "evidence_eligible":False})
        return {"status":"completed","candidates":candidates}
    except Exception as exc:
        return {"status":"unavailable","error_type":type(exc).__name__,"candidates":[]}
