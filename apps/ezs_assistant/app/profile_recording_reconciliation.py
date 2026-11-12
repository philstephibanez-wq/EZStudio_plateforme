"""Conservative documentary duration comparison, never automatic recording identity.

No external calls, no access to LAB, no promotion of verdicts.
"""
from __future__ import annotations
import re

def _seconds(value):
    if isinstance(value, bool) or value is None:
        return None
    if isinstance(value, (int, float)):
        return float(value) if 0 < value < 86400 else None
    if not isinstance(value, str):
        return None
    text=value.strip()
    match=re.fullmatch(r"(\d{1,3}):(\d{2})(?:[.,](\d{1,3}))?",text)
    if match and int(match.group(2))<60:
        return int(match.group(1))*60 + int(match.group(2)) + float("0."+(match.group(3) or "0"))
    match=re.fullmatch(r"(\d+(?:[.,]\d+)?)\s*(?:s|sec|seconds|secondes)",text,re.I)
    if match:
        return float(match.group(1).replace(",","."))
    return None

def compare_durations(catalogue, lab_duration, tolerance_seconds=2):
    lab_seconds=_seconds(lab_duration)
    rows=[]
    for candidate in (catalogue.get("candidates") or [])[:5]:
        if not isinstance(candidate,dict):
            continue
        value=candidate.get("duration_ms")
        recorded=value/1000 if type(value) is int and 0<value<86400000 else None
        delta=round(abs(lab_seconds-recorded),3) if lab_seconds is not None and recorded is not None else None
        rows.append({
            "url":candidate.get("url"),
            "catalogue_duration_ms":value,
            "lab_duration_seconds":lab_seconds,
            "difference_seconds":delta,
            "duration_relation":"compatible" if delta is not None and delta<=tolerance_seconds
                else "different" if delta is not None else "inconclusive",
            "identity_verified":False,
            "evidence_eligible":False})
    return {"schema":"ezstudio.profile.duration-reconciliation.v1",
            "lab_duration_available":lab_seconds is not None,
            "tolerance_seconds":tolerance_seconds,
            "candidates":rows,"identity_verified":False,
            "advisory_only":True}
