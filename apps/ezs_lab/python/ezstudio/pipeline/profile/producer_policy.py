"""PROFILE E04 R3: explicit producer decisions, independent of model inference."""
from __future__ import annotations
import copy
import hashlib
import json

SCHEMA = "ezstudio.profile.producer-policy.v1"
ROLES = ("lead_vocal", "backing_vocals", "vocal_harmonies", "choir")


def _require(ok, reason):
    if not ok:
        raise ValueError("profile_producer_policy:" + reason)


def _keys(value, keys, reason):
    _require(isinstance(value, dict) and set(value) == set(keys), reason)


def _integer(value, low, high, reason):
    _require(type(value) is int and low <= value <= high, reason)


def _labels(value, reason):
    _require(isinstance(value, list), reason)
    _require(all(isinstance(x, str) and x and x == " ".join(x.casefold().split()) for x in value), reason)
    _require(len(value) == len(set(value)), reason)


def validate_policy(policy):
    """Reject missing, ambiguous or invalid policy, never silently substitute values."""
    _keys(policy, ("schema", "instrumentation", "vocals"), "root")
    _require(policy["schema"] == SCHEMA, "schema")
    inst = policy["instrumentation"]
    _keys(inst, ("clap_top_k", "audioset_top_k", "minimum_engine_support", "candidate_limit", "weak_limit", "aliases"), "instrumentation")
    for name in ("clap_top_k", "audioset_top_k", "candidate_limit", "weak_limit"):
        _integer(inst[name], 1, 1000, name)
    _integer(inst["minimum_engine_support"], 1, 3, "minimum_engine_support")
    aliases = inst["aliases"]
    _require(isinstance(aliases, dict) and bool(aliases), "aliases")
    for label, target in aliases.items():
        _labels([label], "alias_label")
        _labels([target], "alias_target")
        _require(aliases.get(target) == target, "alias_target_not_canonical")
    vocals = policy["vocals"]
    _keys(vocals, ("minimum_direct_engines", "high_rank_clap_max", "roles"), "vocals")
    _integer(vocals["minimum_direct_engines"], 1, 3, "minimum_direct_engines")
    _integer(vocals["high_rank_clap_max"], 1, 1000, "high_rank_clap_max")
    _keys(vocals["roles"], ROLES, "roles")
    for name, role in vocals["roles"].items():
        _keys(role, ("clap_direct", "audioset_direct", "audioset_related", "allow_single_high_rank_clap"), name)
        for key in ("clap_direct", "audioset_direct", "audioset_related"):
            _labels(role[key], name + ":" + key)
        _require(type(role["allow_single_high_rank_clap"]) is bool, name + ":allow_single")
        _require(not set(role["audioset_direct"]) & set(role["audioset_related"]), name + ":overlap")
        _require(not ({"background music", "music", "song", "vocal music", "speech", "narration, monologue", "singing"} & (set(role["clap_direct"]) | set(role["audioset_direct"]))), name + ":generic_not_direct")
        if name == "lead_vocal":
            _require("singing" not in role["audioset_direct"], "generic_singing_not_lead")
    return copy.deepcopy(policy)


def policy_sha256(policy):
    validated = validate_policy(policy)
    return hashlib.sha256(json.dumps(validated, ensure_ascii=False, sort_keys=True, separators=(",", ":"), allow_nan=False).encode("utf-8")).hexdigest()


def from_phase_preset(loaded):
    """v2c must supply the policy; only explicitly recognized legacy presets omit it."""
    settings = loaded.value.settings
    revision = loaded.value.revision
    if loaded.value.id == "profile.r3b35c-compat" and revision in {"v2a", "v2b"} and "producer_policy" not in settings:
        return None
    if "producer_policy" not in settings:
        raise ValueError("profile_producer_policy:missing")
    return validate_policy(settings["producer_policy"])
