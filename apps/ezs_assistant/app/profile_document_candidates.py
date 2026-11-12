"""Conservative discovery of music facts stated in retrieved public pages.

Candidates are NOT recording-scoped evidence and MUST NOT create BON/FAUX.
Keep short verbatim context and origin for human/AI examination.
"""
from __future__ import annotations
import re

PATTERNS={
 "duration":[r"(?i)\b(?:duration|length|durée|duree|runtime)\s*[:=–-]?\s*(\d{1,2}:\d{2})\b"],
 "tempo":[r"(?i)\b(?:tempo|bpm)\s*[:=–-]?\s*(\d{2,3}(?:[.,]\d)?)\s*(?:bpm)?\b",
          r"(?i)\b(\d{2,3}(?:[.,]\d)?)\s*bpm\b"],
 "meter":[r"(?i)\b(?:time signature|meter|metre|mesure)\s*[:=–-]?\s*([2-9]\s*/\s*(?:2|4|8|16))\b"],
 "key":[r"(?i)\b(?:key|tonality|tonalité|tonalite)\s*[:=–-]?\s*([A-G](?:#|♯|b|♭)?\s*(?:major|minor|maj|min|majeur|mineur))\b"],
}
def extract_candidates(item):
    key=str(item.get("key") or "")
    expressions=PATTERNS.get(key,[])
    if not expressions:
        return []
    out=[]
    for document in item.get("documents") or []:
        if document.get("status")!="read" or not document.get("url"):
            continue
        text=str(document.get("text") or "")
        # Only candidate context: the title, snippets and descriptions can be
        # about versions/arrangements other than the recording being investigated.
        for pattern in expressions:
            for match in re.finditer(pattern,text[:8000]):
                value=match.group(1).replace(" ","").replace(",",".")
                if key=="tempo" and not 25<=float(value)<=300:
                    continue
                context=text[max(0,match.start()-75):min(len(text),match.end()+85)]
                candidate={"value":value,"url":document["url"],
                           "context":" ".join(context.split())[:190],
                           "scope":"unverified_external_candidate"}
                if not any(x["value"]==value and x["url"]==candidate["url"] for x in out):
                    out.append(candidate)
                if len(out)>=12:
                    return out
    return out
