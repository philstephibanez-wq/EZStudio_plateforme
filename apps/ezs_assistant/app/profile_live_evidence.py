from __future__ import annotations
import unicodedata
import re
from collections import Counter
from profile_musical_facts import evaluate_reference

KEY_ALIASES = {"duree":"duration","mesure":"meter","tonalite":"key","voix_principale":"lead_vocal","voix_accompagnement":"backing_vocals","harmonies_vocales":"vocal_harmonies","choeurs":"choir"}
def _canon_key(k):
    k = str(k or "").strip()
    if k.startswith("instruments:"):
        _, idx = k.split(":", 1)
        return "instrument:" + idx
    return KEY_ALIASES.get(k, k)
def _norm(val: str) -> str:
    s = unicodedata.normalize('NFKD', str(val or '').lower().strip())
    return ' '.join(''.join(c for c in s if unicodedata.category(c) != 'Mn').split())

def _reference_value(key, text):
    ck = _canon_key(key)
    text = re.sub(r"For example:[^\n]{0,120}", " ", str(text or ""), flags=re.I)
    text = re.sub(r"(?:half-time|double-time)\s*(?:at)?\s*\d{2,3}\s*bpm", " ", text, flags=re.I)
    if ck == "duration":
        m = re.search(r"\bDuration\s*:\s*(\d{5,7})\s*ms\b", text, re.I)
        if m:
            sec = round(int(m.group(1)) / 1000)
            return f"{sec // 60}:{sec % 60:02d}"
        m2 = re.search(r"\b(?:duration|length|dur[e\u00e9]e)\s*[:=\-]?\s*0?(\d{1,2}:\d{2})\b", text, re.I)
        return m2.group(1) if m2 else None
    pats = {
        "tempo": r"\b(?:tempo(?:\s*\(bpm\))?|detected|bpm)\s*[:=\-]?\s*(\d{2,3}(?:[.,]\d+)?)\s*(?:bpm)?\b|\b(\d{2,3}(?:[.,]\d+)?)\s*bpm\b",
        "meter": r"\b(?:time signature|meter|metre|mesure)\s*[:=\-]?\s*(\d{1,2}/\d{1,2})\b|\b(\d)\s+beats\s+per\s+bar\b",
        "key": r"\b(?:musical key|key signature|key|tonalit[e\u00e9])\s*[:=\-]?\s*([A-G](?:#|b)?\s+(?:major|minor))\b",
    }
    pat = pats.get(ck)
    if not pat: return None
    vals = []
    for m in re.finditer(pat, text, re.I):
        v = next((g for g in m.groups() if g), None)
        if not v: continue
        v = v.replace(",", ".").strip()
        if ck == "tempo":
            if not (20 <= float(v) <= 350): continue
            v += " BPM"
        elif ck == "meter" and "/" not in v:
            v += "/4"
        vals.append(v)
    if not vals: return None
    top_v, _ = Counter(vals).most_common(1).pop(0)
    return top_v

def _focused_reference(key, text, proposed):
    ck = _canon_key(key)
    if ck in ("duration", "tempo", "meter", "key"): return _reference_value(ck, text)
    if ck == "genre":
        for pat in (r"\b(?:genre|style|genres)\s*[:=]\s*([^.;|\n]{3,90})", r"\b(folk punk|celtic punk|folk rock|irish folk|folk|ballad|rock|pop|punk)\b"):
            for m in re.finditer(pat, text, re.I):
                val = m.group(1).strip()
                if _norm(proposed) in _norm(val) or _norm(val) in _norm(proposed): return str(proposed)
                first, *_ = val.split(",")
                first = first.strip()
                if first and len(first) <= 40: return first
        return None
    if ck.startswith("instrument:"):
        raw = str(proposed).strip()
        for v in ([raw, "guitar"] if raw.lower() == "acoustic guitar" else [raw]):
            if v and re.search(r"\b" + re.escape(v) + r"\b", text, re.I): return v
        return None
    vox_pats = {"lead_vocal": r"\b(?:lead vocals?|lead singer|vocals|sung by|singer)\b", "backing_vocals": r"\b(?:backing vocals?|background vocals?|harmony vocals?)\b", "vocal_harmonies": r"\b(?:vocal harmonies|harmonies|harmony vocals?)\b", "choir": r"\b(?:choir|chorus|choral)\b"}
    if ck in vox_pats and re.search(vox_pats.get(ck), text, re.I): return "pr\u00e9sent"
    return None

def evaluate_live_documents(assertion, identity, documents):
    ck = _canon_key(assertion.get("key"))
    title, artist = _norm(identity.get("title")), _norm(identity.get("artist"))
    accepted = []
    for doc in documents:
        if doc.get("status") != "read": continue
        text = str(doc.get("text") or "")
        compact = _norm((doc.get("title") or "") + " " + (doc.get("snippet") or "") + " " + text[:4500])
        if not title or not artist or title not in compact or artist not in compact or any(w in _norm((doc.get("title") or "") + " " + (doc.get("url") or "")) for w in ("live", "cover", "karaoke", "remix", "tribute", "acoustic")): continue
        ref = _focused_reference(ck, text, assertion.get("value"))
        if not ref: continue
        if ck in ("tempo", "meter", "key"):
            f = evaluate_reference({"key": ck, "value": assertion.get("value")}, {"source": "PublicWeb", "url": doc.get("url"), "fact_scope": "recording", "musical_facts": {ck: {"explicit": True, "value": ref, "url": doc.get("url")}}})
            if not f: continue
            verdict = f.get("verdict")
        elif ck == "genre":
            g_compat = {'folk','ballad','celtic folk','irish folk','traditional folk','folk rock','celtic punk'}
            verdict = "bon" if (_norm(ref) in _norm(assertion.get("value")) or _norm(assertion.get("value")) in _norm(ref) or {_norm(ref), _norm(assertion.get("value"))}.issubset(g_compat)) else "faux"
        elif ck in ("lead_vocal", "backing_vocals", "vocal_harmonies", "choir"):
            lv = _norm(assertion.get("value"))
            verdict = "non_trouve" if lv in ("indetermine", "ind termine", "inconclusive") else ("bon" if lv in ("present", "pr sent", "possible") else "faux")
        else:
            verdict = "bon"
        accepted.append({"url": doc.get("url"), "reference_value": ref, "verdict": verdict, "attribution": "title_artist_page"})
    if not accepted:
        return {"verdict": "non_trouve", "references": [], "reason": "Aucune r\u00e9f\u00e9rence explicite trouv\u00e9e dans les pages consult\u00e9es"}
    ranked = Counter((e.get("reference_value").casefold(), e.get("verdict")) for e in accepted).most_common(2)
    top_pair, top_count = ranked.pop(0)
    chosen = next(e for e in accepted if (e.get("reference_value").casefold(), e.get("verdict")) == top_pair)
    return {"verdict": chosen.get("verdict"), "proposed_verdict": chosen.get("verdict"), "references": accepted, "reference_value": chosen.get("reference_value"), "reason": f"Confirm\u00e9 par {top_count} source(s) publique(s) ({chosen.get('reference_value')})"}
