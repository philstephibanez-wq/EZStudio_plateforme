"""Conservative consolidation of autonomous Web investigator findings.

Only corroborated claims tied to actual web_search citations are retained as
advisory evaluations, NOT as scientifically validated final verdicts.
"""
from __future__ import annotations
import json
from urllib.parse import urlsplit

def read_assessment(text, citations):
    try:
        data=json.loads(text)
    except (TypeError,ValueError):
        return {"status":"invalid_json","verdict":"NON TROUVE","references":[]}
    if not isinstance(data,dict):
        return {"status":"invalid_schema","verdict":"NON TROUVE","references":[]}
    allowed=set(citations)
    refs=[]
    for ref in data.get("source_assessments") or []:
        if not isinstance(ref,dict) or ref.get("url") not in allowed:
            continue
        value=ref.get("reference_value")
        if not isinstance(value,str) or not value.strip():
            continue
        if not ref.get("scope") or not ref.get("trust_rationale"):
            continue
        refs.append({"url":ref["url"],"reference_value":value.strip(),
                     "scope":str(ref["scope"])[:120],
                     "trust_rationale":str(ref["trust_rationale"])[:500],
                     "excerpt":str(ref.get("excerpt") or "")[:400]})
    proposed=str(data.get("verdict") or "").strip().upper().replace("É","E")
    if proposed not in {"BON","FAUX","NON TROUVE"}:
        proposed="NON TROUVE"
    if proposed in {"BON","FAUX"} and not refs:
        proposed="NON TROUVE"
    return {"status":"reviewable","proposed_verdict":proposed,
            "reference_value":str(data.get("reference_value") or "")[:180],
            "references":refs[:12],
            "reasoning":str(data.get("reasoning") or "")[:1200],
            "verified_verdict":None,
            "status_of_verdict":"advisory_only_pending_source_authentication"}
