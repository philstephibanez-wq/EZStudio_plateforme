"""Advisory-only Qwen synthesis of five PROFILE dimensions from saved LAB/module observations.

No audio, no science engine, no web access, and no external-validation scoring.
"""
from __future__ import annotations
import hashlib
import json
import urllib.request
from ai_provider import _local_profile_evidence
from declarative_review import load_review_preset
from profile_trace import snap

KEYS = ("genre", "instrumentation", "lead_vocal", "backing_vocals", "choir")
CONTRIBUTORS = {
    "genre": ("semantic.clap-open-vocabulary", "semantic.panns-cnn14", "semantic.passt-audioset"),
    "instrumentation": ("semantic.clap-open-vocabulary", "semantic.panns-cnn14", "semantic.passt-audioset"),
    "lead_vocal": ("semantic.clap-open-vocabulary", "semantic.panns-cnn14", "semantic.passt-audioset"),
    "backing_vocals": ("semantic.clap-open-vocabulary", "semantic.panns-cnn14", "semantic.passt-audioset"),
    "choir": ("semantic.clap-open-vocabulary", "semantic.panns-cnn14", "semantic.passt-audioset"),
}
PROMPT = (
    "Tu es un critique musical consultatif, sans accès à l'audio ni au Web. "
    "Tu ne disposes QUE des observations LAB et des avis de moteurs fournis. "
    "Rédige EN FRANÇAIS cinq avis musicaux distincts, un par dimension. "
    "N'invente ni instrument ni genre ni présence de chœurs, n'affirme pas avoir écouté le son. "
    "Distingue observation et inférence : les scores des moteurs sont hétérogènes, non calibrés "
    "entre eux et ne constituent pas des probabilités d'une vérité musicale. "
    "Explique les contradictions si elles existent et les incertitudes ; sinon abstention explicite. "
    "Retourne UNIQUEMENT un objet JSON avec dimensions : genre, instrumentation, lead_vocal, "
    "backing_vocals, choir. Pour chacune : advisory (avis précis et concis en français), "
    "uncertainty (limites en français), contributors (liste d'identifiants des modules réellement transmis). "
    "Ne fournis AUCUN score de validation externe, aucune preuve ou source inventée, aucun changement de preset."
)

def _observations(row, sequential):
    view = _local_profile_evidence(row, {}).get("profile_view", {})
    results = sequential.get("results", [])
    brief = {}
    for item in results:
        name = item.get("item_id")
        assessment = item.get("assessment")
        if name not in CONTRIBUTORS["genre"] or not isinstance(assessment, dict):
            continue
        brief[name] = {
            "status": item.get("status"),
            "summary": str(assessment.get("summary") or "")[:500],
            "uncertainty": str(assessment.get("uncertainty") or "")[:280],
        }
    return {"lab_observations": view, "module_consultations": brief,
            "notice": "Avis consultatifs, pas une écoute ni une validation externe."}

def _validate(value, available):
    dimensions = value.get("dimensions") if isinstance(value, dict) else None
    if not isinstance(dimensions, dict) or set(dimensions) != set(KEYS):
        raise ValueError("dimension_advisory_keys_invalid")
    clean = {}
    for key in KEYS:
        entry = dimensions[key]
        if not isinstance(entry, dict):
            raise ValueError("dimension_advisory_entry_invalid:" + key)
        advisory = entry.get("advisory")
        uncertainty = entry.get("uncertainty")
        contributors = entry.get("contributors")
        if not isinstance(advisory, str) or not advisory.strip() or len(advisory) > 750:
            raise ValueError("dimension_advisory_missing:" + key)
        if not isinstance(uncertainty, str) or not uncertainty.strip() or len(uncertainty) > 500:
            raise ValueError("dimension_uncertainty_missing:" + key)
        if not isinstance(contributors, list) or len(contributors) > 3 or any(
            not isinstance(x, str) or x not in available for x in contributors
        ):
            raise ValueError("dimension_contributors_invalid:" + key)
        clean[key] = {"advisory": advisory.strip(), "uncertainty": uncertainty.strip(),
                      "contributors": list(dict.fromkeys(contributors)),
                      "advisory_only": True}
    return {"schema": "ezstudio.profile-dimension-advisories.v1",
            "status": "completed", "dimensions": clean, "external_validation": False}

def _single_dimension(value, key, available):
    """Do not accept module descriptions as musical interpretation."""
    if not isinstance(value, dict):
        raise ValueError("dimension_response_not_object")
    entry = value.get(key, value)
    if not isinstance(entry, dict):
        raise ValueError("dimension_entry_not_object")
    advisory = entry.get("advisory")
    uncertainty = entry.get("uncertainty")
    if not isinstance(advisory, str) or not advisory.strip() or len(advisory) > 450:
        raise ValueError("dimension_advisory_missing")
    if not isinstance(uncertainty, str) or not uncertainty.strip() or len(uncertainty) > 240:
        raise ValueError("dimension_uncertainty_missing")
    text = advisory.casefold()
    if any(x in text for x in ("bollywood", "clap open vocabulary",
            "panns cnn14", "pasST audioset".casefold(), "le module", "le modèle")):
        raise ValueError("dimension_off_topic")
    topics = {
        "genre": ("genre", "style", "rock", "pop", "folk", "cabaret", "chanson", "jazz", "blues", "singer"),
        "instrumentation": ("instrument", "guitare", "guitar", "basse", "bass", "piano", "batterie", "orgue"),
        "lead_vocal": ("voix", "chant", "vocal", "chanteur", "chanteuse"),
        "backing_vocals": ("voix", "chant", "vocal", "harmonie", "choriste"),
        "choir": ("chœur", "choeur", "chorale", "choriste", "voix"),
    }
    if not any(term in text for term in topics[key]):
        raise ValueError("dimension_topic_missing")
    return {"advisory": advisory.strip(), "uncertainty": uncertainty.strip(),
            "contributors": sorted(available), "advisory_only": True}


def _abstention(key, reason):
    return {"advisory": "Avis musical non établi : les données ou la réponse IA ne permettent pas une conclusion fiable.",
            "uncertainty": "Synthèse indisponible (" + reason + "). Analyse humaine requise.",
            "contributors": [], "advisory_only": True, "status": "abstain",
            "reason": reason}


def synthesize_dimensions(provider, row, sequential):
    """Five independently bounded requests. One malformed answer cannot erase the other four."""
    observations = _observations(row, sequential)
    preset, preset_sha = load_review_preset("profile")
    ready, health_reason = provider._health()
    outcomes = {}
    statuses = {}
    for key in KEYS:
        if not ready:
            statuses[key] = health_reason or "model_unavailable"
            outcomes[key] = _abstention(key, statuses[key])
            continue
        modules = {name: {"status": value["status"]} for name, value in
                   observations["module_consultations"].items() if name in CONTRIBUTORS[key]}
        available = {name for name, item in modules.items() if item.get("status") == "completed"}
        prompt = (
            "Tu es un assistant musical consultatif SANS accès à l'audio. "
            "Réponds strictement en français, en JSON. Pour la dimension '" + key + "' uniquement, "
            "retourne un objet JSON avec advisory (avis musical ciblé, 30 mots maximum), "
            "uncertainty (limite explicite, 20 mots maximum). Aucun nom de moteur dans la réponse. "
            "Ne répète pas la description des logiciels. Ne compare jamais des scores non calibrés. "
            "Aucune preuve externe inventée, aucune certitude musicale, aucun changement de preset. "
            "Pour une dimension vocale, parle uniquement de chant ou de voix. Pour le genre, parle de style ; pour l'instrumentation, des instruments. Si tu ne peux pas conclure, abstention explicite."
        )
        observation = {"dimension": key, "lab": observations["lab_observations"].get(
            "vocals" if key in ("lead_vocal", "backing_vocals", "choir") else key, {}),
            "module_consultations": modules}
        body = {"model": "local", "messages": [
            {"role": "system", "content": prompt},
            {"role": "user", "content": json.dumps(observation, ensure_ascii=False)}],
            "temperature": preset["temperature"], "max_tokens": min(1024, preset["max_tokens"]),
            "stream": False, "response_format": {"type": "json_object"}}
        encoded = json.dumps(body, ensure_ascii=False).encode("utf-8")
        if len(encoded) > int(preset["context_max_chars"]):
            statuses[key] = "dimension_context_over_budget"
            outcomes[key] = _abstention(key, statuses[key])
            continue
        try:
            req = urllib.request.Request(provider.endpoint + "/v1/chat/completions",
                data=encoded, headers={"Content-Type": "application/json",
                                       "Accept": "application/json"}, method="POST")
            with urllib.request.urlopen(req, timeout=max(10., float(provider.config.timeout_seconds))) as response:
                raw = response.read(200_000).decode("utf-8")
            choice = json.loads(raw)["choices"][0]
            snap("qwen.dimension.response_metadata", {"dimension": key,
                "finish_reason": choice.get("finish_reason"),
                "response_sha256": hashlib.sha256(raw.encode("utf-8")).hexdigest()})
            if choice.get("finish_reason") == "length":
                raise ValueError("dimension_output_truncated")
            parsed = provider._decode_local_json(choice["message"]["content"])
            outcomes[key] = _single_dimension(parsed, key, available)
            statuses[key] = "completed"
        except Exception as exc:
            code = str(exc) if isinstance(exc, ValueError) and str(exc).startswith("dimension_") else type(exc).__name__
            statuses[key] = code
            outcomes[key] = _abstention(key, code)
        snap("qwen.dimension.result", {"dimension": key, "status": statuses[key]})
    complete = all(statuses[key] == "completed" for key in KEYS)
    snap("qwen.dimension.synthesis", {"status": "completed" if complete else "partial",
        "statuses": statuses, "preset_sha256": preset_sha})
    return {"schema": "ezstudio.profile-dimension-advisories.v1",
            "status": "completed" if complete else "partial",
            "dimensions": outcomes, "dimension_statuses": statuses,
            "external_validation": False}
