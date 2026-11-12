"""Conservative evidence comparison from live, retrieved public documents.

No generated reference, no simulated provider, no automatic scientific correction.
Only explicit measurements in pages mentioning the target title and artist.
"""
from __future__ import annotations
import re
from profile_musical_facts import evaluate_reference

def _norm(s):
    return re.sub(r"[^a-z0-9]+"," ",str(s).casefold()).strip()

def _reference_value(key,text):
    patterns={
        "tempo":r"\b(?:tempo|bpm)\s*[:=\-]?\s*(\d{2,3}(?:[.,]\d+)?)\s*(?:bpm)?\b|\b(\d{2,3}(?:[.,]\d+)?)\s*bpm\b",
        "meter":r"\b(?:time signature|meter|metre)\s*[:=\-]?\s*(\d{1,2}/\d{1,2})\b",
        "key":r"\b(?:musical key|key signature|key)\s*[:=\-]?\s*([A-G](?:#|b)?\s+(?:major|minor))\b"
    }
    pattern=patterns.get(key)
    if not pattern:return None
    values=[]
    for match in re.finditer(pattern,text,re.I):
        value=next((v for v in match.groups() if v),None)
        if value:
            value=value.replace(",",".").strip()
            if key=="tempo":
                if not 20<=float(value)<=350:continue
                value=value+" BPM"
            values.append(value)
    distinct={v.casefold():v for v in values}
    return next(iter(distinct.values())) if len(distinct)==1 else None

def _focused_reference(key, text, proposed):
    """Extract explicit facts from nearby label/value text, not site-wide stray numbers."""
    if key in ("tempo","meter","key"):
        return _reference_value(key,text)
    # Explicit statements of presence only. No genre taxonomy guessed from biography.
    labels={
        "genre": [r"\b(?:genre|style)\s*[:=]\s*([^.;|\n]{3,65})"],
        "lead_vocal":[r"\b(?:lead vocals?|lead singer|vocalist)\s*[:=]\s*([^.;|\n]{2,60})"],
        "backing_vocals":[r"\b(?:backing vocals?|background vocals?)\s*[:=]\s*([^.;|\n]{2,60})"],
        "choir":[r"\b(?:choir|chorus vocals?)\s*[:=]\s*([^.;|\n]{2,60})"],
        "vocal_harmonies":[r"\b(?:vocal harmonies)\s*[:=]\s*([^.;|\n]{2,60})"]
    }
    if key.startswith("instrument:"):
        # Instrument mentioned in an explicit credits/instruments line, not generic prose.
        token=re.escape(str(proposed).strip())
        if re.search(r"\b(?:instruments?|personnel|credits?|performed by)\s*[:=]\s*[^.;\n]{0,160}\b"+token+r"\b",text,re.I):
            return str(proposed)
        return None
    for pattern in labels.get(key,[]):
        for match in re.finditer(pattern,text,re.I):
            value=match.group(1).strip()
            if key=="genre" and _norm(value)==_norm(proposed):
                return str(proposed)
            if key!="genre" and _norm(value) not in ("none","no","absent","unknown","not available"):
                return "présent"
    return None

def evaluate_live_documents(assertion, identity, documents):
    """Keep citation-linked findings. Reject ambiguous/conflicting external references."""
    key=assertion["key"]
    title=_norm(identity.get("title"))
    artist=_norm(identity.get("artist"))
    accepted=[]
    for doc in documents:
        if doc.get("status")!="read":continue
        text=str(doc.get("text") or "")
        # Context only, do not treat arbitrary global mentions as proof of a song.
        compact=_norm((doc.get("title") or "")+" "+(doc.get("snippet") or "")+" "+text[:2500])
        if not title or not artist or title not in compact or artist not in compact:
            continue
        reference=_focused_reference(key,text,assertion["value"])
        if not reference:continue
        if key in ("tempo","meter","key"):
            candidate={"source":"PublicWeb","url":doc["url"],"fact_scope":"recording",
                      "musical_facts":{key:{"explicit":True,"value":reference,"url":doc["url"]}}}
            finding=evaluate_reference(assertion,candidate)
            if not finding:continue
            verdict=finding["verdict"]
        else:
            verdict="bon"
        accepted.append({"url":doc["url"],"reference_value":reference,"verdict":verdict,
                         "attribution":"title_artist_page; specific_recording_not_yet_confirmed"})
    if not accepted:
        return {"verdict":"non_trouve","references":[],"reason":"Aucune référence explicite suffisamment attribuable dans les pages consultées"}
    conclusions={entry["verdict"] for entry in accepted}
    values={entry["reference_value"].casefold() for entry in accepted}
    if len(conclusions)!=1 or len(values)!=1:
        # Prefer a documented majority as a provisional reference, without
        # upgrading it to a verified verdict or pretending popularity proves
        # that the recording matches LAB's audio.
        from collections import Counter
        counts=Counter((entry["reference_value"].casefold(),entry["verdict"])
                       for entry in accepted)
        ranked=counts.most_common(2)
        if ranked and ranked[0][1]>=2 and (
                len(ranked)==1 or ranked[0][1]>ranked[1][1]):
            chosen=next(entry for entry in accepted
                        if (entry["reference_value"].casefold(),entry["verdict"])==ranked[0][0])
            return {"verdict":"non_trouve","proposed_verdict":chosen["verdict"],
                    "reference_value":chosen["reference_value"],"references":accepted,
                    "reason":"Hypothèse majoritaire parmi les sources consultées ; enregistrement exact non confirmé"}
        return {"verdict":"non_trouve","references":accepted,
                "reason":"Références contradictoires sans majorité documentaire"}
    # Document claims are candidates until their recording/version is independently confirmed.
    return {"verdict":"non_trouve","proposed_verdict":accepted[0]["verdict"],
            "references":accepted,"reference_value":accepted[0]["reference_value"],
            "reason":"Valeur citée par des pages publiques ; correspondance exacte à l'enregistrement non démontrée"}
