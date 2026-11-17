"""Independent verification of LAB final musical assertions only.

The verifier never receives, imports or inspects scientific module outputs.
Evidence comes exclusively from identity-matched recording-scoped references.
"""
from __future__ import annotations
import re
from profile_universal_investigator import _load_metric_rules
from datetime import datetime, timezone
from urllib.parse import urlparse
from profile_musical_facts import evaluate_reference

DECISIONS = {"bon", "faux", "non_trouve"}
UNKNOWN = {"", "inconclusive", "unknown", "unavailable", "undetermined", "not_scored", "non déterminé"}
INSTRUMENT_ALIASES = {
    "guitar": "guitar", "electric guitar": "electric guitar",
    "acoustic guitar": "acoustic guitar", "bass guitar": "bass guitar",
    "piano": "piano", "organ": "organ", "drums": "drums",
    "drum kit": "drums", "violin": "violin", "cello": "cello",
    "trumpet": "trumpet", "saxophone": "saxophone",
}
def _clean(value):
    return str(value or "").strip()
def _valid(value):
    return bool(_clean(value)) and _clean(value).casefold() not in UNKNOWN
def _url(source):
    url = source.get("url")
    if not isinstance(url, str):
        return None
    parsed = urlparse(url)
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password:
        return None
    return url

def final_assertions(row):
    """Explicit allowlist: absolutely no raw diagnostic/module/preset leaves this boundary."""
    if "_public_final_assertions" in row:
        published = row["_public_final_assertions"]
        if not isinstance(published, list):
            raise ValueError("invalid_public_assertions")
        result, seen = [], set()
        for item in published:
            if not isinstance(item, dict) or not all(k in item for k in ("id","dimension","value")):
                raise ValueError("invalid_public_assertion")
            key = str(item["id"])
            if not key or key in seen:
                raise ValueError("duplicate_or_empty_assertion_id")
            seen.add(key)
            if not _valid(item["value"]):
                raise ValueError("invalid_public_assertion_value")
            dimension = str(item["dimension"])
            pass  # Conserver instruments:0 canonique
            result.append({"key":key, "label":dimension, "value":_clean(item["value"])})
        return result
    view = (row.get("diagnostics") or {}).get("profile_view") or {}
    metrics = row.get("metrics") or {}
    vox = (view.get("vocal_profile") or {}).get("dimensions") or {}
    out = []
    def put(key, label, value):
        if _valid(value):
            out.append({"key":key,"label":label,"value":_clean(value)})
    duration = metrics.get("duration_seconds")
    if isinstance(duration, (int, float)) and not isinstance(duration, bool) and 0 < duration < 86400:
        total = round(duration)
        put("duration", "Durée", f"{total // 60}:{total % 60:02d}")
    bpm = (metrics.get("tempo") or {}).get("bpm")
    if isinstance(bpm, (int, float)) and not isinstance(bpm, bool) and 20 <= bpm <= 350:
        put("tempo", "Tempo", f"{bpm:.1f} BPM")
    meter = metrics.get("time_signature") or {}
    put("meter","Mesure",meter.get("label") or meter.get("suggested_label"))
    put("key","Tonalité",(view.get("tonal") or {}).get("label") or (metrics.get("key") or {}).get("label"))
    put("genre","Genre musical",((view.get("genre_hierarchy") or {}).get("primary") or {}).get("genre"))
    for i, item in enumerate((view.get("instrumentation") or {}).get("candidates") or []):
        if isinstance(item, dict) and _valid(item.get("label")):
            put("instruments:"+str(i),"Instrument",item["label"])
    for key,label in (("lead_vocal","Voix principale"),("backing_vocals","Voix d'accompagnement"),
                      ("vocal_harmonies","Harmonies vocales"),("choir","Chœurs")):
        decision = (vox.get(key) or {}).get("decision")
        if decision in ("present","detected"):
            put(key,label,"présent")
        elif decision == "absent":
            put(key,label,"absent")
    return out

def _matched_recording_sources(identity, research):
    """A source must be bound to the same artist/title; external research owns matching."""
    title = _clean(identity.get("title"))
    artist = _clean(identity.get("artist"))
    # A heuristic catalog identity may be confirmed by independent source matching.
    # Do not discard a genuine identity-matched recording solely because the
    # original title/artist split was heuristic.
    if not title or not artist:
        return []
    return [s for s in research.get("sources", []) if isinstance(s, dict)
            and s.get("available") is True and s.get("identity_relevant") is True
            and _url(s)]

def _instrument_matches(proposed, credited):
    """A specific credited guitar entails generic guitar, but not vice versa."""
    proposed = _clean(proposed).casefold().replace("guitare", "guitar")
    credited = _clean(credited).casefold().replace("guitare", "guitar")
    if proposed == credited:
        return True
    guitar_family = {"guitar", "electric guitar", "acoustic guitar", "classical guitar"}
    return proposed == "guitar" and credited in guitar_family


def _vocal_role(credit):
    if credit.get("scope") != "recording":
        return None
    role = _clean(credit.get("role")).casefold()
    attributes = " ".join(_clean(x).casefold() for x in credit.get("instruments") or [])
    if role not in {"vocal", "vocals", "performer"}:
        return None
    if "backing vocal" in attributes or "background vocal" in attributes:
        return "backing"
    if role in {"vocal", "vocals"} and ("lead" in attributes or not attributes):
        return "lead"
    return None


def _evidence_for_assertion(item, source):
    """Return corroborating recording evidence, never infer absence from missing credit."""
    if source.get("source") != "MusicBrainz":
        return None
    value = _clean(item["value"]).casefold()
    key = item["key"]
    if key.startswith(("instruments:", "instrument:")):
        credits = source.get("recording_credits") or []
        for credit in credits:
            if not isinstance(credit, dict) or credit.get("scope") != "recording":
                continue
            instruments = {_clean(v).casefold() for v in credit.get("instruments") or []}
            if any(_instrument_matches(value, name) for name in instruments):
                return ("MusicBrainz — crédits de l'enregistrement", "recording_credit")
    if key == "genre":
        # Explicit recording-level taxonomy only. Artist/release tags are not proof.
        tags = {_clean(t).casefold() for t in source.get("recording_genres") or []}
        if value in tags:
            return ("MusicBrainz — genre de l'enregistrement", "recording_genre")
    if key in {"lead_vocal", "backing_vocals", "voix_principale", "voix_accompagnement"} and value in {"présent", "present"}:
        roles = {_vocal_role(c) for c in source.get("recording_credits") or []
                 if isinstance(c, dict)}
        target = "lead" if key == "lead_vocal" else "backing"
        if target in roles:
            return ("MusicBrainz — voix créditées pour cet enregistrement",
                    "recording_vocal_credit")
    return None

def verify_final_values(row, identity, research):
    """One conservative, separately persisted verdict for EVERY final musical assertion.

    Only explicit recording-level evidence can return BON. FAUX requires positive
    contradictory recording evidence and is intentionally not guessed here.
    """
    assertions = final_assertions(row)
    sources = _matched_recording_sources(identity, research)
    result=[]
    audit = {
        "identity_complete": bool(_clean(identity.get("title")) and _clean(identity.get("artist"))),
        "research_status": research.get("status"),
        "research_reason": research.get("reason"),
        "request_count": research.get("request_count"),
        "warnings": [str(x)[:180] for x in (research.get("warnings") or [])][:12],
        "sources": [
            {"provider": _clean(x.get("source")),
             "available": x.get("available") is True,
             "identity_relevant": x.get("identity_relevant") is True,
             "recording_credit_count": len(x.get("recording_credits") or []),
             "recording_detail_status": x.get("recording_detail_status"),
             "recording_detail_error": x.get("recording_detail_error"),
             "edition_track_credit_count": int(x.get("edition_track_credit_count") or 0),
             "recording_genre_count": len(x.get("recording_genres") or []),
             "physical_document_count": len(x.get("physical_documents") or []),
             "physical_ocr_status": (x.get("physical_credit_review") or {}).get("status"),
             "physical_candidate_count": sum(len(z.get("credit_candidates") or [])
                 for z in (x.get("physical_credit_review") or {}).get("scans", [])
                 if isinstance(z, dict)),
             "reason": ("not_available" if x.get("available") is not True else
                        "identity_not_matched" if x.get("identity_relevant") is not True else
                        "eligible")}
            for x in (research.get("sources") or []) if isinstance(x, dict)
        ],
    }
    for item in assertions:
        decision="non_trouve"
        links=[]
        reference=None
        for source in sources:
            structured = evaluate_reference(item, source)
            if structured and _url(source) == structured["link"]["url"]:
                decision = structured["verdict"]
                reference = structured["reference_value"]
                links = [structured["link"]]
                break
            finding=_evidence_for_assertion(item, source)
            if not finding:
                continue
            safe_url=_url(source)
            if not safe_url:
                continue
            decision="bon"
            reference=item["value"]
            links=[{"title":finding[0],"url":safe_url,"evidence_type":finding[1]}]
            break
        result.append({**item,"verdict":decision,"confidence_pct":None,
                       "reference_value":reference,"sources":links,
                       "reason":("Source indépendante concordante" if decision=="bon" else "Source indépendante contradictoire") if links else
                                "Référence indépendante suffisante non trouvée"})
    return {"schema":"ezstudio.profile-final-verification.v1",
            "status":"completed","mode":"independent_recording_evidence",
            "verified_at":datetime.now(timezone.utc).isoformat(),
            "assertions":result,"evidence_diagnostic":audit,
            "scientific_preset_mutation_allowed":False}


def _parse_sec(val):
    s = str(val or "").strip().lower()
    m = re.fullmatch(r"(\d{1,2}):(\d{2})(?::(\d{2}))?", s)
    if m:
        return int(m.group(1))*3600 + int(m.group(2))*60 + int(m.group(3)) if m.group(3) else int(m.group(1))*60 + int(m.group(2))
    m2 = re.search(r"([0-9]+(?:[.,][0-9]+)?)", s)
    return float(m2.group(1).replace(",", ".")) if m2 else None

def _documentary_type_ok(key, value):
    """Avoid incompatible units/values being marked as documentary agreement."""
    import re
    v = str(value or "").strip()
    if not v:
        return False
    if key == "tempo":
        return bool(re.fullmatch(r"\d{1,3}(?:[.,]\d+)?\s*(?:bpm)?", v, re.I))
    if key in ("duration", "duree"):
        return bool(re.fullmatch(r"\d{1,3}:\d{2}(?::\d{2})?", v) or re.fullmatch(r"\d{1,4}(?:[.,]\d+)?\s*(?:s|sec|secondes?)?", v, re.I))
    if key in ("meter", "mesure"):
        return bool(re.fullmatch(r"\d{1,2}\s*/\s*\d{1,2}", v))
    if key.startswith(("instruments:", "instrument:")) or key in ("lead_vocal", "voix_principale", "backing_vocals", "voix_accompagnement", "vocal_harmonies", "harmonies_vocales", "choir", "choeurs", "genre", "key", "tonalite"):
        return not bool(re.search(r"\b\d+(?:[.,]\d+)?\s*bpm\b", v, re.I) or re.fullmatch(r"\d+(?:[.,]\d+)?", v))
    return True


def _compare_documentary(key, lab, reference):
    """Compute item-specific documentary comparisons, independently of model votes."""
    if not _documentary_type_ok(key, reference):
        return "indeterminate"
    a, b = str(lab or "").strip().casefold(), str(reference).strip().casefold()
    if key in ("duration", "duree"):
        try:
            def seconds(v):
                v = v.replace("secondes", "").replace("seconde", "").replace("sec", "").replace("s", "").strip()
                if ":" not in v:
                    return float(v.replace(",", "."))
                p = [int(x) for x in v.split(":")]
                return 60*p[0]+p[1] if len(p)==2 else 3600*p[0]+60*p[1]+p[2]
            return "concordant" if abs(seconds(a)-seconds(b)) <= 3 else "discordant"
        except (ValueError, IndexError):
            return "indeterminate"
    if key == "tempo":
        try:
            x,y = (float(z.replace("bpm","").strip().replace(",",".")) for z in (a,b))
            # Reuse the strict tolerance from the official evaluator.
            if abs(x-y) <= 2:
                return "concordant"
            # The beat can be interpreted at half/double tempo. Never report
            # a misleading provisional discrepancy in this ambiguous case.
            if any(abs(x-y*k) <= 2 for k in (0.5, 2)):
                return "indeterminate"
            return "discordant"
        except ValueError:
            return "indeterminate"
    if key in ("meter", "mesure"):
        return "concordant" if a.replace(" ","")==b.replace(" ","") else "discordant"
    if key == "genre":
        if not b: return "indeterminate"
        return "concordant" if (a == b or a in b or b in a) else "discordant"
    if key.startswith(("instruments:", "instrument:")):
        return "concordant" if _instrument_matches(a,b) else "indeterminate"
    if key in ("lead_vocal","backing_vocals","vocal_harmonies","choir"):
        aliases={"present":"présent","detected":"présent","yes":"présent","no":"absent"}
        a,b=aliases.get(a,a),aliases.get(b,b)
        return ("concordant" if a==b else "discordant") if a in ("présent","absent") and b in ("présent","absent") else "indeterminate"
    return "indeterminate"


def attach_documentary_findings(verification, investigation, local_review):
    """Merge post-verification documentary observations without promoting proof.

    BON/FAUX from the independent verifier remain unchanged. Every provisional
    reference remains visible with provenance and an explicit identity caveat.
    """
    import copy
    merged = copy.deepcopy(verification)
    web_by_key = {str(item["key"]): item for item in (investigation.get("items") or [])
                  if isinstance(item, dict) and item.get("key")}
    ai_by_key = {str(item["key"]): item for item in (local_review.get("items") or [])
                 if isinstance(item, dict) and item.get("key")}
    is_excerpt = False
    for assertion in merged.get("assertions") or []:
        key = str(assertion.get("key") or "")
        if not key or assertion.get("verdict") in ("bon", "faux"):
            continue
        web = web_by_key.get(key) or {}
        review = web.get("evidence_review") or {}
        candidate = ai_by_key.get(key) or {}
        value = review.get("reference_value") or candidate.get("reference_value")
        urls = []
        for ref in review.get("references") or []:
            if isinstance(ref, dict):
                url = ref.get("url")
                if isinstance(url, str) and url.startswith("https://") and url not in urls:
                    urls.append(url)
        for url in candidate.get("citations") or []:
            if isinstance(url, str) and url.startswith("https://") and url not in urls:
                urls.append(url)
        proposed = review.get("proposed_verdict")
        if proposed not in ("bon", "faux"):
            proposed = candidate.get("proposed_verdict")
        if proposed not in ("bon", "faux"):
            proposed = None
        incompatible = value is not None and not _documentary_type_ok(key, value)
        comparison = "indeterminate" if incompatible else _compare_documentary(key, assertion.get("value"), value)
        id_confirmed = bool((investigation.get("identity") or {}).get("recording_identity_verified")) or bool(urls)
        reason_txt = review.get("reason") or candidate.get("explanation") or ("Valeur documentaire incompatible avec le type de cet item" if incompatible else "Aucune valeur documentaire exploitable")
        final_verdict = None
        if value is not None and urls and id_confirmed and not incompatible:
            if proposed == "bon" or comparison == "concordant":
                final_verdict = "bon"
            elif proposed == "faux" or comparison == "discordant":
                final_verdict = "faux"
        # Calcul explicite de l'ecart pour chaque item des qu'une valeur externe existe
        gap_note = ""
        if value is not None:
            try:
                if key in ("duration", "duree"):
                    def _sec(v):
                        s0 = str(v).lower().replace("secondes","").replace("seconde","").replace("sec","").replace("s","").strip()
                        if ":" not in s0: return float(s0.replace(",","."))
                        p0 = [int(x) for x in s0.split(":")]
                        return 60*p0[0]+p0[1] if len(p0)==2 else 3600*p0[0]+60*p0[1]+p0[2]
                    diff_s = round(abs(_sec(assertion.get("value")) - _sec(value)), 1)
                    gap_note = f" (écart : {diff_s} s)"
                elif key == "tempo":
                    x0 = float(str(assertion.get("value")).lower().replace("bpm","").strip().replace(",","."))
                    y0 = float(str(value).lower().replace("bpm","").strip().replace(",","."))
                    gap_note = f" (écart : {round(abs(x0 - y0), 2)} BPM)"
                elif comparison == "concordant":
                    gap_note = " (concordant)"
                elif comparison == "discordant":
                    gap_note = " (écart / divergence)"
            except Exception:
                gap_note = ""
        if str(assertion.get("value") or "").strip().lower() in ("inconclusive", "indéterminé", "indetermine", "ind?termin?"):
            assertion["verdict"] = "non_trouve"
            continue
        if key in ("lead_vocal", "voix_principale", "backing_vocals", "voix_accompagnement", "vocal_harmonies", "harmonies_vocales", "choir", "choeurs") and value is not None:
            lv_s, ev_s = str(assertion.get("value") or "").lower(), str(value).lower()
            if ("sent" in lv_s and "sent" in ev_s) or ("absent" in lv_s and "absent" in ev_s):
                final_verdict = "bon"; comparison = "concordant"
            elif ("sent" in lv_s and "absent" in ev_s) or ("absent" in lv_s and "sent" in ev_s):
                final_verdict = "faux"; comparison = "discordant"
        if key == "tempo" and value is not None and final_verdict == "faux":
            mr = _load_metric_rules(); tol = float(mr.get("tempo_octave_tolerance_bpm") or 0.0)
            m1 = re.search(r"([0-9]+(?:[.,][0-9]+)?)", str(assertion.get("value") or "")); m2 = re.search(r"([0-9]+(?:[.,][0-9]+)?)", str(value))
            if tol > 0 and m1 and m2:
                lb, eb = float(m1.group(1).replace(",",".")), float(m2.group(1).replace(",","."))
                if abs(2.0 * lb - eb) <= tol or abs(lb - 2.0 * eb) <= tol / 2.0:
                    final_verdict = "non_trouve"; comparison = "related_metric"; reason_txt = f"Hypothèse métrique double/mi-temps 2x/0.5x (LAB {lb} vs EXT {eb} BPM — non équivalent strict)."
        if key in ("meter", "mesure") and value is not None and final_verdict == "faux":
            mr = _load_metric_rules(); lv = str(assertion.get("value") or "").strip().lower(); ev = str(value).strip().lower()
            if lv != ev and ev in [str(x).lower() for x in ((mr.get("ternary_web_aliases") or {}).get(lv) or [])]:
                final_verdict = "non_trouve"; comparison = "related_metric"; reason_txt = f"Parenté métrique ternaire ({lv} vs {ev} — non équivalent strict)."
        if key in ("duration", "duree") and value is not None:
            _ls, _es = _parse_sec(assertion.get("value")), _parse_sec(value)
            if _ls is not None and _es and _es > 0 and (_ls / _es) < 0.45:
                is_excerpt = True
        if is_excerpt and (key in ("duration", "duree", "choir", "choeurs", "backing_vocals", "voix_accompagnement", "vocal_harmonies", "harmonies_vocales") or key.startswith("instrument")):
            final_verdict = "non_trouve"; comparison = "excerpt_uncomparable"; reason_txt = "Extrait audio ou version partielle (< 45 s ou < 45 % de la durée studio) : comparaison globale non applicable."
        if comparison == "discordant" and final_verdict == "bon":
            final_verdict = "faux"
        elif comparison == "concordant" and final_verdict == "faux":
            final_verdict = "bon"
        elif comparison in ("indeterminate", "related_metric", "excerpt_uncomparable", "unverified", "not_scored") or not comparison:
            final_verdict = "non_trouve"
        has_valid_url = any(isinstance(s, str) and s.startswith("https://") for s in (urls or []))
        if final_verdict in ("bon", "faux") and (not has_valid_url or not bool((investigation or {}).get("available", True))):
            final_verdict = "non_trouve"; comparison = "unverified"; reason_txt = "Traçabilité documentaire insuffisante (URL source ou validation externe absente)."
        assertion["verdict"] = final_verdict if final_verdict in ("bon", "faux") else "non_trouve"
        if value is not None:
            assertion["reference_value"] = (str(value).strip() + gap_note)[:160]
        if urls:
            assertion["citations"] = urls[:12]
            assertion["sources"] = [{"title": u, "url": u, "evidence_type": "documentary_web_evidence"} for u in urls[:12]]
        if reason_txt:
            assertion["reason"] = reason_txt
        assertion["documentary_observation"] = {
            "status": "verified" if final_verdict in ("bon", "faux") else ("provisional" if value else "not_found"),
            "reference_value": str(value)[:160] if value is not None else None,
            "comparison": comparison,
            "citations": urls[:12],
            "recording_identity_confirmed": id_confirmed,
            "scientific_validation": False,
            "reason": reason_txt,
        }
    merged["web_investigation"] = investigation
    merged["local_web_review"] = local_review
    return merged
