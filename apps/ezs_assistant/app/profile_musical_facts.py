"""Conservative interpretation of explicitly scoped external musical reference facts.

Only structured, source-provided recording facts. Textual mentions and album/artist
metadata are never enough for a verdict.
"""
from __future__ import annotations
import re

def normalize_key(value):
    tokens = str(value or "").casefold().replace("♭", "b").replace("♯", "#")
    tokens = re.sub(r"\bmajor\b", "major", tokens)
    return re.sub(r"\s+", " ", tokens).strip()

def evaluate_reference(item, source):
    facts = source.get("musical_facts") or {}
    if source.get("fact_scope") != "recording" or not isinstance(facts, dict):
        return None
    key = item["key"]
    raw = facts.get(key)
    if raw is None and key.startswith("instrument:"):
        normalized = str(item["value"]).strip().casefold().replace(" ", "_")
        raw = facts.get("instrument:" + normalized)
    if not isinstance(raw, dict) or raw.get("explicit") is not True:
        return None
    reference = str(raw.get("value") or "").strip()
    fact_url = str(raw.get("url") or "").strip()
    if not reference or not fact_url or fact_url != source.get("url"):
        return None
    proposed = str(item["value"]).strip()
    if key == "tempo":
        a = re.fullmatch(r"(\d+(?:\.\d+)?)\s*BPM", proposed, re.I)
        b = re.fullmatch(r"(\d+(?:\.\d+)?)\s*BPM", reference, re.I)
        if not a or not b:
            return None
        a,b = float(a.group(1)),float(b.group(1))
        if not 20 <= b <= 350:
            return None
        # Ratios are not silently folded; if tempo ambiguous, abstain.
        if any(abs(a-b*k) <= 2 for k in (0.5,2)) and abs(a-b)>2:
            return None
        agree = abs(a-b) <= 2
    elif key == "meter":
        if not re.fullmatch(r"\d{1,2}/\d{1,2}", reference):
            return None
        agree = proposed == reference
    elif key == "key":
        if not re.fullmatch(r"[A-G](?:#|b)?\s+(major|minor)", reference, re.I):
            return None
        agree = normalize_key(proposed)==normalize_key(reference)
    elif key == "genre":
        # Genre taxonomies vary; an inconsistent label is not necessarily false.
        agree = proposed.casefold() == reference.casefold()
        if not agree:
            return None
    elif key.startswith("instrument:"):
        # Positive credit is proof of instrument presence, never of absence.
        if reference.casefold() != proposed.casefold():
            return None
        agree = True
    else:
        return None
    return {"verdict": "bon" if agree else "faux", "reference_value":reference,
            "link":{"title":"Référence indépendante — "+key,"url":fact_url,
                    "evidence_type":"explicit_recording_fact"}}
