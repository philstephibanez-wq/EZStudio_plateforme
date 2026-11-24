"""Universal 6-step human-like PROFILE research & set-theoretic synthesis."""
from __future__ import annotations
from difflib import SequenceMatcher
import json, re, unicodedata, urllib.request
from urllib.parse import urlparse

LOCAL_GPU_ENDPOINT = "http://localhost:" + str(8720) + "/v1/chat/completions"

EXCLUDED_DOMAINS = (
    "ultimate-guitar.com", "chordu.com", "chordify.net", "songsterr.com",
    "azlyrics.com", "lyrics.com", "musixmatch.com", "paroles.net", "lacoccinelle.net",
)
ENCYCLOPEDIA_DOMAINS = ("wikipedia.org", "discogs.com", "musicbrainz.org", "allmusic.com", "genius.com", "secondhandsongs.com")
TECHNICAL_DOMAINS = ("songbpm.com", "tunebat.com", "getsongbpm.com", "musicstax.com")

EXCLUDED_MARKERS = (
    "live at", "live in", "(live)", "[live]", "cover version", "karaoke", "tribute to",
    "remix", "club mix", "tablature", "guitar tab", "harmonica tab", "chord diagram", "made famous by",
)

INSTRUMENT_SYNONYMS = {
    "guitar": {"guitar", "guitare", "acoustic guitar", "guitare acoustique", "electric guitar", "guitare electrique"},
    "acoustic guitar": {"acoustic guitar", "guitare acoustique", "guitar", "guitare"},
    "electric guitar": {"electric guitar", "guitare electrique", "guitar", "guitare"},
    "piano": {"piano", "clavier", "keyboard", "synthesizer", "synthetiseur"},
    "bass": {"bass", "basse", "bass guitar", "guitare basse", "contrebasse", "double bass"},
    "drums": {"drums", "batterie", "percussion", "percussions"},
    "harmonica": {"harmonica"},
    "mandolin": {"mandolin", "mandoline"},
    "accordion": {"accordion", "accordeon"},
    "violin": {"violin", "violon", "strings", "cordes", "orchestre", "orchestra"},
    "saxophone": {"saxophone", "saxo"},
    "trumpet": {"trumpet", "trompette"},
}

def _norm(val: object) -> str:
    s = unicodedata.normalize("NFKD", str(val or "").replace("?", "e").replace("\ufffd", "e").lower().strip())
    return re.sub(r"\s+", " ", "".join(c for c in s if not unicodedata.combining(c)))

def _fuzzy_match(needle: str, haystack: str, threshold: float = 0.78) -> bool:
    if needle in haystack: return True
    words = haystack.split(); n = max(1, len(needle.split()))
    return any(SequenceMatcher(None, needle, " ".join(words[i:i+n])).ratio() >= threshold for i in range(max(1, len(words) - n + 1)))

def step1_build_pivot_queries(identity: dict) -> list[str]:
    """Etape 1 : Requetes pivots libres multi-langues type Google."""
    artist = str(identity.get("artist") or "").strip()
    title = str(identity.get("title") or "").strip()
    if not artist or not title:
        return []
    return [
        f'"{artist}" "{title}"',
        f'"{artist}" "{title}" wikipedia',
        f'"{artist}" "{title}" discogs',
        f'"{artist}" "{title}" tempo bpm', f'{artist} {title}', f'"{artist}" "{title}" signature rythmique OR "time signature"',
    ]

def step2_classify_document(doc: dict, identity: dict) -> dict:
    """Etape 2 : Lecture riche et qualification de la nature du document."""
    url = str(doc.get("url") or "").strip()
    title = str(doc.get("title") or "")
    text = str(doc.get("text") or "")
    domain = urlparse(url).netloc.lower()
    ntitle, ntext = _norm(title), _norm(text[:4000])
    nartist, nsong = _norm(identity.get("artist")), _norm(identity.get("title"))

    if any(d in domain for d in EXCLUDED_DOMAINS) or any(m in ntitle or m in ntext[:1500] for m in EXCLUDED_MARKERS):
        kind = "excluded_noise"
    elif nartist and nsong and not (_fuzzy_match(nartist, ntitle + " " + ntext[:1500]) and _fuzzy_match(nsong, ntitle + " " + ntext[:1500])):
        kind = "excluded_homonym"
    elif any(d in domain for d in ENCYCLOPEDIA_DOMAINS):
        kind = "encyclopedia_catalog"
    elif any(d in domain for d in TECHNICAL_DOMAINS):
        kind = "technical_metrics"
    else:
        kind = "general_editorial"

    return {
        "url": url, "domain": domain, "title": title, "kind": kind,
        "excerpt": text[:2800], "char_count": len(text),
    }

def step3_select_source_sets(documents: list[dict], identity: dict) -> dict[str, list[dict]]:
    """Etape 3 : Partition en ensembles de confiance (E_enc, E_tech, E_gen, E_excl)."""
    sets: dict[str, list[dict]] = {"E_enc": [], "E_tech": [], "E_gen": [], "E_excl": []}
    seen = set()
    for d in documents or []:
        if d.get("status") != "read":
            continue
        u = str(d.get("url") or "")
        if not u or u in seen:
            continue
        seen.add(u)
        enriched = step2_classify_document(d, identity)
        k = enriched["kind"]
        if k == "encyclopedia_catalog":
            sets["E_enc"].append(enriched)
        elif k == "technical_metrics":
            sets["E_tech"].append(enriched)
        elif k == "general_editorial":
            sets["E_gen"].append(enriched)
        else:
            sets["E_excl"].append(enriched)
    return sets
LLM_PROMPT = (
    "Tu es un extracteur de faits musicaux multilingue (francais, anglais, etc.). "
    "Lis UNIQUEMENT les extraits fournis concernant l enregistrement studio demande. "
    "N invente RIEN. Si une information n est pas explicitement ecrite dans les extraits, mets null. "
    "Retourne UNIQUEMENT un objet JSON avec les cles exactes : "
    "duration_sec (float|null), tempo_bpm (float|null), meter (str|null), key (str|null), "
    "genres (list[str]), instruments (list[str]), "
    "lead_vocal ('present'|'absent'|null), backing_vocals ('present'|'absent'|null), "
    "vocal_harmonies ('present'|'absent'|null), choir ('present'|'absent'|null), "
    "evidence (dict associant chaque cle renseignee a {url, quote})."
)

def step4_extract_facts_via_gpu(identity: dict, sets: dict[str, list[dict]], endpoint: str = LOCAL_GPU_ENDPOINT, timeout: float = 45.0) -> dict:
    try: import local_ai_runtime; local_ai_runtime.local_ai_start()
    except Exception: pass
    """Etape 4 : Extraction ciblee des faits musicaux via Qwen 2.5 GPU (port 8720)."""
    corpus = (sets.get("E_enc", [])[:3] + sets.get("E_tech", [])[:2] + sets.get("E_gen", [])[:1])[:5]
    if not corpus:
        return {"status": "no_trusted_sources", "facts": {}}
    payload = {
        "recording": {"artist": identity.get("artist"), "title": identity.get("title")},
        "references": [{"url": d["url"], "kind": d["kind"], "title": d["title"], "text": d["excerpt"]} for d in corpus],
    }
    body = {
        "model": "local",
        "messages": [{"role": "system", "content": LLM_PROMPT}, {"role": "user", "content": json.dumps(payload, ensure_ascii=False)}],
        "temperature": 0.0, "max_tokens": 900, "stream": False, "response_format": {"type": "json_object"},
    }
    req = urllib.request.Request(endpoint, data=json.dumps(body, ensure_ascii=False).encode("utf-8"), headers={"Content-Type": "application/json"}, method="POST")
    try:
        with urllib.request.build_opener(urllib.request.ProxyHandler({})).open(req, timeout=timeout) as resp:
            raw = json.loads(resp.read(500_000).decode("utf-8"))
        content = raw["choices"][0]["message"]["content"]
        facts = json.loads(content) if isinstance(content, str) else (content or {})
        full_txt = _norm(" ".join(d["excerpt"] for d in corpus))
        if isinstance(facts, dict):
            if facts.get("tempo_bpm") is not None and str(int(round(float(facts["tempo_bpm"])))) not in full_txt: facts["tempo_bpm"] = None
            if facts.get("meter") and _norm(facts["meter"]) not in full_txt: facts["meter"] = None
            if facts.get("key") and _norm(facts["key"]).split()[0] not in full_txt: facts["key"] = None
        return {"status": "ok", "sources_used": [d["url"] for d in corpus], "facts": facts if isinstance(facts, dict) else {}}
    except Exception as exc:
        return {"status": "gpu_unavailable", "error": type(exc).__name__, "facts": {}}

def _inst_compatible(lab_inst: str, ext_insts: set[str]) -> bool:
    nl = _norm(lab_inst)
    syns = INSTRUMENT_SYNONYMS.get(nl, {nl})
    return any(s in ext_insts or any(s in e or e in s for e in ext_insts if len(e) >= 4) for s in syns)

def _load_metric_rules() -> dict:
    from pathlib import Path
    cand = Path(__file__).resolve().parents[2] / "ezs_lab" / "presets" / "assistant" / "profile-universal-cand-1.json"
    try:
        data = json.loads(cand.read_text(encoding="utf-8-sig"))
        return data.get("metric_rules") if isinstance(data.get("metric_rules"), dict) else {}
    except Exception:
        return {}

def step5_and_6_confront_and_judge(assertion: dict, facts_bundle: dict, sets: dict[str, list[dict]]) -> dict | None:
    """Etapes 5 & 6 : Confrontation par ensembles et jugement final (bon / faux / non_trouve)."""
    facts = (facts_bundle or {}).get("facts") or {}
    if not facts:
        return None
    key = str(assertion.get("key") or "").strip().lower()
    raw_val = str(assertion.get("value") or "").strip()
    nval = _norm(raw_val)
    ev_map = facts.get("evidence") if isinstance(facts.get("evidence"), dict) else {}
    trusted_urls = [d["url"] for d in (sets.get("E_enc", []) + sets.get("E_tech", []) + sets.get("E_gen", [])) if d.get("url")]

    # R1: exact provenance attestation. Never attribute a model fact to the
    # first trusted URL or accept a quotation absent from the source document.
    from profile_evidence_sets import qualified_reference

    def _srcs(f_key: str) -> list[dict]:
        return qualified_reference(f_key, ev_map, sets)

    proof_key = ({'tempo': 'tempo_bpm', 'duration': 'duration_sec',
                  'duree': 'duration_sec', 'meter': 'meter', 'mesure': 'meter',
                  'key': 'key', 'tonalite': 'key', 'genre': 'genres',
                  'lead_vocal': 'lead_vocal', 'voix_principale': 'lead_vocal',
                  'backing_vocals': 'backing_vocals', 'voix_accompagnement': 'backing_vocals',
                  'vocal_harmonies': 'vocal_harmonies', 'harmonies_vocales': 'vocal_harmonies',
                  'choir': 'choir', 'choeurs': 'choir'}).get(key)
    if key.startswith(('instrument:', 'instruments:')):
        proof_key = 'instruments'
    # Strictly qualified citations retain the established BON/FAUX path.
    # A *different*, advisory-only path can retain a fact actually visible in
    # a retrieved document. Neither a search snippet nor a model assertion
    # alone is sufficient, and this never certifies recording identity.
    qualified = _srcs(proof_key) if proof_key else []
    advisory_refs = []
    if proof_key and not qualified:
        item = ev_map.get(proof_key) if isinstance(ev_map, dict) else None
        if isinstance(item, dict):
            from profile_evidence_sets import _clean_url
            source_url = _clean_url(item.get('url'))
            observed = facts.get(proof_key)
            if isinstance(observed, list):
                values = [str(x).strip() for x in observed if isinstance(x, str) and str(x).strip()]
            elif isinstance(observed, (str, int, float)) and not isinstance(observed, bool):
                values = [str(observed).strip()]
                if isinstance(observed, (int, float)) and float(observed).is_integer():
                    values.append(str(int(observed)))
            else:
                values = []
            # Textual presence is a *candidate* only, not a quotation and not
            # an attestation of the exact recording or of scientific truth.
            if source_url and values:
                for category in ('E_enc', 'E_tech', 'E_gen'):
                    for doc in (sets or {}).get(category, []):
                        if not isinstance(doc, dict) or doc.get('kind') in ('excluded_noise', 'excluded_homonym'):
                            continue
                        excerpt = doc.get('excerpt')
                        if (_clean_url(doc.get('url')) == source_url and
                                isinstance(excerpt, str) and len(excerpt) >= 20 and
                                any(len(v) >= 2 and re.search(r'(?<!\w)' + re.escape(_norm(v)) + r'(?!\w)', _norm(excerpt)) for v in values)):
                            advisory_refs = [{'url': source_url, 'source_kind': category,
                                              'evidence_type': 'retrieved_text_candidate'}]
                            break
                    if advisory_refs:
                        break
    if not proof_key or not (qualified or advisory_refs):
        return None

    def _set_support() -> tuple[list[str], list[str]]:
        # A corroboration requires the same extracted fact in separately
        # retrieved documents belonging to distinct source sets AND domains.
        # It is an index of documentary coverage, not calibrated accuracy.
        from profile_evidence_sets import _clean_url
        from urllib.parse import urlsplit
        observed = facts.get(proof_key)
        if isinstance(observed, list):
            values = [str(x).strip() for x in observed if isinstance(x, str) and str(x).strip()]
        elif isinstance(observed, (str, int, float)) and not isinstance(observed, bool):
            values = [str(observed).strip()]
            if isinstance(observed, (int, float)) and float(observed).is_integer():
                values.append(str(int(observed)))
        else:
            values = []
        if not values:
            return [], []
        supported: list[tuple[str, str, str]] = []
        for category in ('E_enc', 'E_tech', 'E_gen'):
            for doc in (sets or {}).get(category, []):
                if not isinstance(doc, dict) or doc.get('kind') in ('excluded_noise', 'excluded_homonym'):
                    continue
                url = _clean_url(doc.get('url'))
                excerpt = doc.get('excerpt')
                if not url or not isinstance(excerpt, str) or len(excerpt) < 20:
                    continue
                if any(len(v) >= 2 and re.search(r'(?<!\w)' + re.escape(_norm(v)) + r'(?!\w)', _norm(excerpt)) for v in values):
                    host = urlsplit(url).hostname or ''
                    supported.append((category, host, url))
        # The same domain mirrored across several URLs is not independent.
        unique_sets, unique_domains = set(), set()
        for category, host, url in supported:
            unique_sets.add(category)
            unique_domains.add(host)
        return sorted(unique_sets), sorted(unique_domains)

    def _emit(result: dict) -> dict:
        evidence_sets, evidence_domains = _set_support()
        has_intersection = len(evidence_sets) >= 2 and len(evidence_domains) >= 2
        # Consensus computed for this musical item only, using independent
        # source groups and all competing documentary values. The previous
        # fixed 90/100 heuristic is intentionally withdrawn.
        from profile_item_consensus import consensus_for_item
        observed = facts.get(proof_key)
        values = observed if isinstance(observed, list) else [observed]
        consensus = consensus_for_item(proof_key, sets, candidate_values=values)
        result['item_documentary_consensus'] = consensus
        result['evidence_confidence_index'] = None  # deprecated, was not calibrated
        result['evidence_confidence_basis'] = 'item_documentary_agreement_not_accuracy'
        result['evidence_supporting_sets'] = evidence_sets
        result['evidence_independent_domains'] = len(evidence_domains)
        result['evidence_intersection'] = has_intersection
        # The 90/100 values are an operator decision index, NEVER statistical
        # probability of correctness nor external identity certification.
        if qualified:
            return result  # Existing strict qualification is unchanged.
        proposed = result.pop('proposed_verdict', None)
        result['references'] = advisory_refs
        result['advisory_only'] = True
        result['advisory_decision'] = ('concordant' if proposed == 'bon' else
                                       'discordant' if proposed == 'faux' else 'indeterminate')
        result['reason'] = ('Hypothese documentaire, valeur presente dans un texte consulte; '
                            'citation exacte et identite de l enregistrement non certifiees. '
                            + str(result.get('reason') or ''))
        return result

    if key in ("tempo",):
        ext_bpm = facts.get("tempo_bpm")
        m = re.search(r"([0-9]+(?:[.,][0-9]+)?)", raw_val)
        if ext_bpm is not None and m:
            lb, eb = float(m.group(1).replace(",", ".")), float(ext_bpm)
            diff = round(abs(lb - eb), 2)
            mr = _load_metric_rules()
            oct_tol = float(mr.get("tempo_octave_tolerance_bpm") or 0.0)
            oct_ok = oct_tol > 0 and (abs(2.0 * lb - eb) <= oct_tol or abs(lb - 2.0 * eb) <= oct_tol / 2.0)
            ok = diff <= 2.0 or oct_ok
            note = f"Confrontation ensemble technique (ecart : {diff} BPM)." if diff <= 2.0 else f"Concordance octave rythmique 2x/0.5x (LAB {lb} vs EXT {eb} BPM)."
            return _emit({"proposed_verdict": "bon" if ok else "faux", "reference_value": f"{round(eb, 2)} BPM", "reason": note, "references": _srcs("tempo_bpm")})

    if key in ("duration", "duree"):
        ext_dur = facts.get("duration_sec")
        m = re.search(r"([0-9]+(?:[.,][0-9]+)?)", raw_val)
        if ext_dur is not None and m:
            ld, ed = float(m.group(1).replace(",", ".")), float(ext_dur)
            diff = round(abs(ld - ed), 1)
            ok = diff <= 5.0
            mins, secs = int(ed // 60), int(round(ed % 60))
            return _emit({"proposed_verdict": "bon" if ok else "faux", "reference_value": f"{mins}:{secs:02d}", "reason": f"Confrontation duree studio (ecart : {diff} s).", "references": _srcs("duration_sec")})

    if key in ("meter", "mesure") and facts.get("meter"):
        em = str(facts["meter"]).strip()
        mr = _load_metric_rules()
        aliases = (mr.get("ternary_web_aliases") or {}).get(nval, [nval])
        ok = _norm(em) == nval or _norm(em) in {_norm(a) for a in aliases}
        return _emit({"proposed_verdict": "bon" if ok else "faux", "reference_value": em, "reason": "Confrontation mesure rythmique (parente ternaire prise en compte)." if ok and _norm(em) != nval else "Confrontation mesure rythmique.", "references": _srcs("meter")})

    if key in ("key", "tonalite") and facts.get("key"):
        ek = str(facts["key"]).strip()
        ok = _norm(ek) == nval
        return _emit({"proposed_verdict": "bon" if ok else "faux", "reference_value": ek, "reason": "Confrontation tonalite.", "references": _srcs("key")})

    if key == "genre" and isinstance(facts.get("genres"), list) and facts["genres"]:
        ext_g = [_norm(g) for g in facts["genres"] if str(g).strip()]
        compat = {
            "singer-songwriter": {"chanson", "chanson francaise", "pop", "folk", "ballad", "singer-songwriter", "variete"},
            "ballad": {"ballad", "ballade", "folk", "celtic folk", "chanson", "pop", "slow"},
        }
        allowed = compat.get(nval, {nval})
        ok = any(g in allowed or nval in g or g in nval for g in ext_g)
        return _emit({"proposed_verdict": "bon" if ok else "faux", "reference_value": ", ".join(str(g) for g in facts["genres"][:3]), "reason": "Confrontation ensemble genres musicaux.", "references": _srcs("genres")})

    if key.startswith(("instrument:", "instruments:")) and isinstance(facts.get("instruments"), list) and facts["instruments"]:
        ext_insts = {_norm(i) for i in facts["instruments"] if str(i).strip()}
        if _inst_compatible(raw_val, ext_insts):
            return _emit({"proposed_verdict": "bon", "reference_value": raw_val, "reason": "Instrument confirme dans les credits de l enregistrement studio.", "references": _srcs("instruments")})

    vmap = {"lead_vocal": "lead_vocal", "voix_principale": "lead_vocal", "backing_vocals": "backing_vocals", "voix_accompagnement": "backing_vocals", "vocal_harmonies": "vocal_harmonies", "harmonies_vocales": "vocal_harmonies", "choir": "choir", "choeurs": "choir"}
    if key in vmap and facts.get(vmap[key]) in ("present", "absent"):
        if nval in ("inconclusive", "indetermine"):
            return None
        ev = str(facts[vmap[key]])
        lab_pres = nval in ("present", "possible")
        ext_pres = ev == "present"
        ok = (lab_pres == ext_pres)
        return _emit({"proposed_verdict": "bon" if ok else "faux", "reference_value": "présent" if ext_pres else "absent", "reason": "Confrontation ensemble vocal studio.", "references": _srcs(vmap[key])})

    return None