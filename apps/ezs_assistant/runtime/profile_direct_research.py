"""On-demand Web investigation from Assistant UI. Reads JSON on stdin; emits JSON.
No LAB mutation, no fake reference, no secrets or scientific changes.
"""
import json
import os
import sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "app"))
from profile_web_investigator import research_assertions
from profile_web_local_review import review_web_items
from profile_document_candidates import extract_candidates
from profile_source_identity import classify_source
from profile_auto_review import research_public_sources, NetworkBudget
from profile_tesseract_runtime import resolve_tesseract

LABELS = {
    "duration": "Durée", "tempo": "Tempo", "meter": "Mesure", "key": "Tonalité",
    "genre": "Genre", "instrument:0": "Instruments",
    "lead_vocal": "Voix principale", "backing_vocals": "Voix d’accompagnement",
    "vocal_harmonies": "Harmonies vocales", "choir": "Chœurs",
}

def main():
    request = json.load(sys.stdin)
    title = str(request.get("title") or "").strip()
    artist = str(request.get("artist") or "").strip()
    if not title or not artist or len(title) > 180 or len(artist) > 180:
        raise ValueError("titre_et_interprete_requis")
    wanted = list(LABELS)  # Contract PROFILE: never user-selected or partial.
    values = request.get("values") or {}
    if not isinstance(values, dict):
        raise ValueError("valeurs_invalides")
    assertions = []
    for key in wanted:
        if key not in LABELS or any(row["key"] == key for row in assertions):
            continue
        value = str(values.get(key) or "non déterminé").strip()[:120]
        assertions.append({"key": key, "label": LABELS[key], "value": value,
                           "no_lab_proposition": value == "non déterminé"})
    if not assertions:
        raise ValueError("aucun_item_recherchable")
    identity = {"title": title, "artist": artist}
    # OCR direct : use the existing recording/release discovery and transient
    # Tesseract pipeline, without changing worker settings or stored models.
    ocr_runtime=resolve_tesseract()
    source_research={"sources":[]}
    ocr_preflight={"status":ocr_runtime.get("status","unavailable"),"scans":[]}
    if ocr_runtime.get("status")=="ready":
        previous=os.environ.get("EZS_PROFILE_BOOKLET_OCR")
        try:
            os.environ["EZS_PROFILE_BOOKLET_OCR"]="1"
            source_research=research_public_sources(identity,NetworkBudget())
        except Exception as exc:
            ocr_preflight={"status":"discovery_error","reason":type(exc).__name__,"scans":[]}
        finally:
            if previous is None:
                os.environ.pop("EZS_PROFILE_BOOKLET_OCR",None)
            else:
                os.environ["EZS_PROFILE_BOOKLET_OCR"]=previous
    investigation = research_assertions(assertions, identity, source_research=source_research)
    if ocr_preflight["status"]!="ready":
        investigation["ocr_audit"]=ocr_preflight
    else:
        scanned=investigation["ocr_audit"].get("scans",[])
        statuses=[(x.get("physical_credit_review") or {}).get("status") for x in source_research.get("sources",[]) if isinstance(x,dict)]
        investigation["ocr_audit"]["status"]="scanned" if scanned else "no_scan" if "completed" in statuses else next((v for v in statuses if v),"no_release_documents")
    ai = review_web_items(investigation, identity)
    # Never expose fetched full page bodies to the browser.
    safe = []
    by_key = {x.get("key"): x for x in ai.get("items", [])}
    for item in investigation.get("items", []):
        safe.append({"key": item.get("key"), "label": LABELS.get(item.get("key")),
                     "value": item.get("value"),
                     "status": item.get("status"), "reason": item.get("reason"),
                     "query": item.get("query"),
                     "sources": [{"url": doc.get("url"), "title": doc.get("title"),
                                  "identity": classify_source(doc,identity),
                                  "status": doc.get("status"),
                                  "reason": doc.get("reason") or doc.get("error_type")}
                                 for doc in item.get("documents", [])],
                     "candidates": extract_candidates(item),
                     "ai": by_key.get(item.get("key"))})
    read_count=sum(sum(doc.get("status")=="read" for doc in item.get("documents",[])) for item in investigation.get("items",[]))
    verified_count=sum(bool((by_key.get(item.get("key")) or {}).get("citations")) for item in investigation.get("items",[]))
    outcome="sources_analyzed" if verified_count else "documents_without_confirmed_values" if read_count else "no_readable_sources"
    print(json.dumps({"ok": True, "schema": "ezs.profile.direct-research.v1",
        "outcome":outcome,"documents_read":read_count,"items_with_citations":verified_count,
        "title": title, "artist": artist,
        "status": investigation.get("status"), "reason": investigation.get("reason"),
        "provider": investigation.get("search_provider"), "items": safe,
        "ocr": investigation.get("ocr_audit"),
        "ai_status": ai.get("status"), "ai_reason": ai.get("reason"),
        "persistence": "none_direct_research"}, ensure_ascii=False))

if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(json.dumps({"ok": False, "error": type(exc).__name__,
                          "reason": str(exc)[:240]}, ensure_ascii=False))
        sys.exit(1)
