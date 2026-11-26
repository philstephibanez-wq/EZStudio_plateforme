"""Additive PROFILE item view. Never recalculates or overwrites source evidence."""
from __future__ import annotations
import hashlib
import json
import math
import re
from pathlib import Path


def obj(value):
    return value if isinstance(value, dict) else {}


def rows(value):
    return [x for x in value if isinstance(x, dict)] if isinstance(value, list) else []


def txt(value):
    return value.strip() if isinstance(value, str) and value.strip() else None


def positive(value):
    return value if type(value) in (int, float) and math.isfinite(value) and value > 0 else None


def entry(value=None, ref=None, *, observed=False, uncalibrated=False, kind="scientific_projection"):
    return {"state": "known" if value is not None else ("inconclusive" if observed else "not_analyzed"),
            "value": value, "confidence": {"kind": "uncalibrated" if uncalibrated else "not_calculated", "value": None},
            "provenance": [{"kind": kind, "ref": ref}] if ref and (observed or value is not None) else []}


def load_policy(contract_dir):
    def read(name):
        raw = (Path(contract_dir) / name).read_bytes()
        return json.loads(raw.decode("utf-8")), hashlib.sha256(raw).hexdigest()
    items, items_hash = read("PROFILE_ITEMS.json")
    policy, policy_hash = read("profile-projection.v1.json")
    if items.get("schema") != "ezstudio.profile.items.v1" or policy.get("schema") != "ezstudio.profile.projection.v1":
        raise ValueError("profile_projection_contract_version")
    if policy.get("source_contract") != items["schema"] or {x["id"] for x in items["items"] if x["domain"] == "scientific"} != set("23456789"):
        raise ValueError("profile_projection_items_mismatch")
    families = policy["instrument_families"]
    labels = [label for group in families.values() for label in group]
    if len(labels) != len(set(labels)) or set(labels) & set(policy["generic_instruments"]):
        raise ValueError("profile_projection_taxonomy_overlap")
    if set(policy["vocal_roles"]) != {"backing_vocals", "vocal_harmonies", "choir"} or set(policy["vocal_roles"].values()) != {"backing", "harmony", "choir"}:
        raise ValueError("profile_projection_vocal_roles")
    if policy.get("excluded_vocal_evidence") != {"backing_vocals": ["background music"]}:
        raise ValueError("profile_projection_excluded_vocal_evidence")
    policy["provenance"] = {"items_sha256": items_hash, "policy_sha256": policy_hash}
    return policy


def vocal_part(role, presence):
    return {"id": role, "role": role, "delivery": "unknown", "articulation": "unknown", "languages": None,
            "presence": presence, "intervals": None}


def project_scientific_items(characteristics, view, consensus, policy):
    c, v = obj(characteristics), obj(view)
    result = {}
    duration = positive(c.get("duration_seconds"))
    result["2"] = entry(duration, "characteristics.duration_seconds", observed="duration_seconds" in c)
    meter = obj(c.get("time_signature"))
    # A suggested label is not an accepted signature.
    match = re.fullmatch(r"([1-9][0-9]*)/(1|2|4|8|16|32|64)", txt(meter.get("label")) or "")
    value = {"numerator": int(match[1]), "denominator": int(match[2])} if match else None
    result["3"] = entry(value, "characteristics.time_signature.label", observed=bool(meter), uncalibrated=meter.get("confidence") is not None)
    tempo = obj(c.get("tempo")); metric = obj(tempo.get("metric_level")); alternate = obj(v.get("tempo_metric"))
    tracker = positive(tempo.get("bpm"))
    primary = obj(metric.get("primary")); bpm = positive(primary.get("bpm")); unit = txt(primary.get("beat_unit"))
    # Preserve tracker; abstain on contradictory metric projections or missing unit.
    if metric.get("decision") != "accepted" or not unit or (alternate and alternate != metric):
        bpm = None
    value = {"tracker_bpm": tracker, "primary_bpm": bpm, "beat_unit": unit if bpm is not None else None} if tracker is not None or bpm is not None else None
    result["4"] = entry(value, "characteristics.tempo", observed=bool(tempo), uncalibrated=tempo.get("confidence") is not None or metric.get("calibrated") is False)
    key = obj(c.get("key")); tonic, mode = txt(key.get("tonic")), txt(key.get("mode"))
    result["5"] = entry({"tonic": tonic, "mode": mode} if tonic and mode else None, "characteristics.key", observed=bool(key), uncalibrated=key.get("calibrated") is False or key.get("confidence") is not None)
    genre = obj(v.get("genre_hierarchy")); genres = []
    for row in rows(genre.get("candidates")):
        family = txt(row.get("genre_family"))
        if family:
            item = {"family": family, "genre": txt(row.get("genre")), "subgenre": txt(row.get("subgenre"))}
            if item not in genres: genres.append(item)
    result["6"] = entry(genres or None, "profile_view.genre_hierarchy.candidates", observed=bool(genre), uncalibrated=bool(genre))
    vocal = obj(v.get("vocal_profile")); dims = obj(vocal.get("dimensions")); lead = obj(dims.get("lead_vocal"))
    # Generic singing does not establish lead count or even a lead role. Only explicit lead labels support a hypothesis.
    lead_evidence = [r for r in rows(lead.get("evidence")) if r.get("role") == "direct" and (txt(r.get("label")) or "").casefold() in {"male lead vocal", "female lead vocal"}]
    leads = [vocal_part("lead", "possible")] if lead_evidence and lead.get("decision") in {"present", "possible"} else []
    result["7"] = entry({"coverage": "partial", "entries": leads} if leads else None, "profile_view.vocal_profile.dimensions.lead_vocal", observed=bool(lead), uncalibrated=bool(lead))
    others = []
    for name, role in policy["vocal_roles"].items():
        dim = obj(dims.get(name))
        excluded = policy["excluded_vocal_evidence"].get(name, [])
        evidence = rows(dim.get("evidence"))
        if name in policy["excluded_vocal_evidence"]:
            # A generic background-music tag is not evidence of a second voice.
            direct = [r for r in evidence if r.get("role") == "direct"
                      and (txt(r.get("label")) or "").casefold() not in excluded]
            if not direct:
                continue
        if dim.get("decision") in {"present", "possible"}:
            # Existing output cannot establish articulated words/syllables.
            others.append(vocal_part(role, "possible"))
    result["8"] = entry({"coverage": "partial", "entries": others} if others else None, "profile_view.vocal_profile.dimensions", observed=bool(dims), uncalibrated=bool(dims))
    # Speech is evidence of speech, not of secondary narration. Keep evidence separately.
    speech = []
    semantic = obj(obj(consensus).get("semantic"))
    for family, labels in policy["spoken_labels"].items():
        for module, records in obj(obj(semantic.get(family)).get("per_engine")).items():
            for rank, row in enumerate(rows(records), 1):
                label = txt(row.get("label"))
                if label and label.casefold() in labels:
                    speech.append({"module": module, "family": family, "label": label, "rank": rank})
    inst = obj(v.get("instrumentation")); instruments = []
    specific = {label: family for family, labels in policy["instrument_families"].items() for label in labels}
    generic = policy["generic_instruments"]
    seen = set()
    for row in rows(inst.get("candidates")) + rows(inst.get("weak")):
        label = txt(row.get("label"))
        if not label or label in seen: continue
        seen.add(label)
        if label in generic:
            family, name = generic[label], None
        else:
            family, name = specific.get(label, "unclassified"), label
        instruments.append({"id": label, "family": family, "instrument": name, "presence": "possible", "intervals": None})
    # Keep generic evidence in the original consensus; do not count it as another instrument.
    specific_families = {r["family"] for r in instruments if r["instrument"] is not None}
    instruments = [r for r in instruments if r["instrument"] is not None or r["family"] not in specific_families]
    result["9"] = entry({"coverage": "partial", "entries": instruments} if instruments else None, "profile_view.instrumentation", observed=bool(inst), uncalibrated=bool(inst))
    return {"schema": "ezstudio.profile.scientific-items.v1", "items": result, "speech_evidence": speech,
            "speech_role": "undetermined", "usable_for_downstream": False, "policy_provenance": dict(policy["provenance"])}
