"""Qwen consultatif sur un module scientifique, jamais une écoute audio."""
import json
import re
import urllib.request
from declarative_review import load_review_preset
from profile_trace import snap
from profile_module_compact import module_prompt_payload

# Detect common English narrative markers; short module names/units are exempt.
_ENGLISH_PROSE = re.compile(
    r"\b(?:the (?:module|model|analysis|provided|result|evidence|confidence|tool)|"
    r"this (?:indicates|suggests|means)|the (?:audio|prediction)|"
    r"was (?:performed|trained)|is (?:based|limited)|"
    r"cannot (?:be|confirm)|which (?:indicates|prevents))\b",
    re.IGNORECASE,
)

def _english_review(answer):
    return any(
        isinstance(answer.get(field), str) and _ENGLISH_PROSE.search(answer[field])
        for field in ("summary", "uncertainty")
    )

def infer_module(provider, request):
    ready, reason=provider._health()
    if not ready: return {"status":"error","reason":reason or "model_unavailable"}
    preset,digest=load_review_preset("profile")
    prompt=("Tu es un assistant de contre-analyse musicale consultative. Réponds intégralement en français. "
            "Analyse uniquement les observations du module scientifique fourni et leurs limites ; "
            "tu n'as ni écouté l'audio ni consulté le Web. Ne prétends jamais le contraire. "
            "Retourne un objet JSON avec status (completed ou abstain), summary, uncertainty, "
            "supporting_evidence (liste) et conflicting_evidence (liste). "
            "Dans summary, cite un résultat musical précis du module avec son unité lorsqu'elle existe, "
            "son statut de calibration, et une interprétation prudente utile au musicien. "
            "Dans uncertainty, précise la limite qui empêche toute validation indépendante. "
            "Si des résultats sont discordants ou insuffisants, expose-les ; si rien n'est démontrable, "
            "utilise abstain et explique pourquoi. Ne transforme pas des scores de modèles "
            "hétérogènes en probabilités comparables, n'invente aucune source, "
            "ne valide ni ne promeus de preset.")
    bound=int(preset["context_max_chars"])
    try:
        content, projection = module_prompt_payload(request,bound,prompt)
    except ValueError as exc:
        return {"status":"error","reason":str(exc)}
    snap("qwen.module.projection",{"item_id":request["item_id"],**projection})
    body={"model":"local","messages":[{"role":"system","content":prompt},{"role":"user","content":content}],
          "temperature":preset["temperature"],"max_tokens":preset["max_tokens"],"stream":False,
          "response_format":{"type":"json_object"}}
    snap("qwen.module.request",{"item_id":request["item_id"],"body":body,"preset_sha256":digest})
    req=urllib.request.Request(provider.endpoint+"/v1/chat/completions",data=json.dumps(body,ensure_ascii=False).encode(),
        headers={"Content-Type":"application/json","Accept":"application/json"},method="POST")
    try:
        for attempt in range(2):
            with urllib.request.urlopen(req,timeout=max(10.,float(provider.config.timeout_seconds))) as response:
                raw=response.read(2_000_000).decode("utf-8")
            snap("qwen.module.response",{"item_id":request["item_id"],"attempt":attempt + 1,"raw":raw})
            choice=json.loads(raw)["choices"][0]
            if choice.get("finish_reason")=="length":return {"status":"error","reason":"output_truncated"}
            parsed=provider._decode_local_json(choice["message"]["content"])
            if not isinstance(parsed,dict) or parsed.get("status") not in ("completed","abstain"):
                return {"status":"error","reason":"invalid_model_response"}
            if not _english_review(parsed):
                break
            if attempt:
                return {"status":"abstain","summary":"","uncertainty":"Langue de sortie non conforme après nouvelle tentative.","supporting_evidence":[],"conflicting_evidence":[],"reason":"non_french_model_output","model":str(provider.model_path),"preset_sha256":digest}
            # Same observations; one explicit corrective generation, never translate or fabricate evidence.
            retry_body={**body,"messages":[*body["messages"],
                {"role":"assistant","content":json.dumps(parsed,ensure_ascii=False)},
                {"role":"user","content":"Réécris uniquement les champs textuels en français naturel. Garde strictement les observations, chiffres, unités, incertitudes et le statut ; n'ajoute aucune preuve. Retourne seulement le JSON."}]}
            snap("qwen.module.retry_language",{"item_id":request["item_id"],"attempt":2})
            req=urllib.request.Request(provider.endpoint+"/v1/chat/completions",
                data=json.dumps(retry_body,ensure_ascii=False).encode("utf-8"),
                headers={"Content-Type":"application/json","Accept":"application/json"},method="POST")
        return {"status":parsed["status"],"summary":str(parsed.get("summary") or "")[:2000],
                "uncertainty":str(parsed.get("uncertainty") or "")[:1000],
                "supporting_evidence":parsed.get("supporting_evidence") if isinstance(parsed.get("supporting_evidence"),list) else [],
                "conflicting_evidence":parsed.get("conflicting_evidence") if isinstance(parsed.get("conflicting_evidence"),list) else [],
                "model":str(provider.model_path),"preset_sha256":digest}
    except Exception as exc:
        snap("qwen.module.error",{"item_id":request["item_id"],"type":type(exc).__name__,"message":str(exc)[:200]})
        return {"status":"error","reason":type(exc).__name__}
