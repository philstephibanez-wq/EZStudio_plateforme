"""Read-only, bounded LAB PROFILE observations for the advisory review.

These are NOT external evidence or calibrated conclusions.
"""
from __future__ import annotations

def describe_lab_profile(row):
    diagnostics = row.get("diagnostics") if isinstance(row, dict) else None
    diagnostics = diagnostics if isinstance(diagnostics, dict) else {}
    view = diagnostics.get("profile_view")
    view = view if isinstance(view, dict) else {}
    pieces = []
    genre = view.get("genre_hierarchy")
    genre = genre if isinstance(genre, dict) else {}
    primary = genre.get("primary")
    if isinstance(primary, dict):
        value = primary.get("genre") or primary.get("genre_family")
        if isinstance(value, str) and value.strip():
            pieces.append("Genre proposé par LAB : " + value.strip()[:70])
    inst = view.get("instrumentation")
    inst = inst if isinstance(inst, dict) else {}
    candidates = inst.get("candidates")
    if isinstance(candidates, list):
        labels = []
        for item in candidates[:8]:
            if not isinstance(item, dict):
                continue
            name = item.get("label")
            if not isinstance(name, str) or not name.strip():
                continue
            label = name.strip()[:45]
            support = item.get("support")
            source_count = item.get("source_count")
            count = source_count if isinstance(source_count, int) and not isinstance(source_count, bool) else support
            if isinstance(count, int) and not isinstance(count, bool) and count >= 0:
                label += " (" + str(count) + " moteurs)"
            labels.append(label)
        if labels:
            pieces.append("Instruments proposés par LAB : " + ", ".join(labels[:5]))
    vocals = view.get("vocal_profile")
    vocals = vocals if isinstance(vocals, dict) else {}
    dims = vocals.get("dimensions")
    dims = dims if isinstance(dims, dict) else {}
    captions = (("lead_vocal","voix principale"), ("backing_vocals","voix d'accompagnement"),
                ("choir","chœurs"))
    for key, title in captions:
        entry = dims.get(key)
        if isinstance(entry, dict):
            decision = entry.get("decision")
            if isinstance(decision, str) and decision.strip():
                pieces.append("LAB " + title + " : " + decision.strip()[:32])
    if not pieces:
        return "Observations LAB PROFILE : aucune donnée structurée exploitable dans cette révision."
    return "Observations LAB (hypothèses des moteurs, NON validées) : " + " ; ".join(pieces)[:1150] + "."

def attach_lab_observations(ai, row):
    result = dict(ai)
    current = result.get("summary")
    prefix = describe_lab_profile(row)
    suffix = current.strip() if isinstance(current, str) else ""
    result["summary"] = (prefix + (" Avis Qwen / validation documentaire : " + suffix[:850] if suffix else ""))[:2200]
    return result
