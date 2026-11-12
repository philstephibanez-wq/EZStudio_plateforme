"""Traitement PROFILE indépendant de l’hôte, sans claim ni requête LAB."""
from __future__ import annotations
import hmac
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
sys.path.insert(0, str(ROOT / "app"))

from profile_assertions_contract import ITEMS, build_assertions, validate_verdicts
from app.profile_dynamic_contract_r2 import project_final_assertions, build_final_reviews, validate_final_reviews
from profile_web_investigator import research_assertions
from profile_recording_reconciliation import compare_durations
from profile_document_consultation import consult
from profile_tesseract_runtime import booklet_ocr_enabled
from profile_physical_documents import inspect_scans_with_local_ocr, _safe_url
from profile_secret_reader import read_profile_token

WEB_KEYS = dict(zip(ITEMS, (
    "duration", "tempo", "meter", "key", "genre", "instrument:0",
    "lead_vocal", "backing_vocals", "vocal_harmonies", "choir",
)))

def verify(payload):
    request = build_assertions(payload)
    finals = project_final_assertions(payload)
    # An explicit public inventory supersedes the legacy search list.
    if finals is not None:
        rows = [{"key": item["search_key"], "value": item["search_value"],
                 "label": item["dimension"]} for item in finals]
    else:
        rows = [{"key": WEB_KEYS[key], "value": request["items"][key],
                 "label": key} for key in ITEMS if request["items"][key] is not None]
    investigation = research_assertions(
        rows, {"title": request["title"], "artist": request["artist"]}
    ) if rows else {"items": [], "status": "no_assertions", "technical_errors": 0}
    by_key = {
        row["key"]: row for row in (investigation.get("items") or [])
        if isinstance(row, dict) and row.get("key") in WEB_KEYS.values()
    } if finals is None else {}
    reviews = {}
    for key in ITEMS:
        row = by_key.get(WEB_KEYS[key], {})
        candidate = row.get("evidence_review") or {}
        absent = request["items"][key] is None
        # Page titre/artiste != preuve de correspondance à l'enregistrement.
        reviews[key] = {
            "verdict": "sans_valeur_lab" if absent else "non_trouve",
            "reference_value": candidate.get("reference_value"),
            "reason": "Valeur LAB absente" if absent else (
                candidate.get("reason") or "Identité de l'enregistrement non confirmée"
            ),
            "evidence": [],
            "confidence_pct": None,
            "consulted_urls": [
                d["url"] for d in (row.get("documents") or [])
                if isinstance(d, dict) and d.get("status") == "read"
                and isinstance(d.get("url"), str)
            ],
            "technical_status": row.get("status", "not_searched"),
            # Evidence candidates are NOT recording-level confirmations.
            "proposed_verdict": candidate.get("proposed_verdict")
                if candidate.get("proposed_verdict") in ("bon", "faux") else None,
            "references": [
                {"url": ref["url"], "reference_value": ref.get("reference_value"),
                 "verdict": ref.get("verdict"), "attribution": ref.get("attribution")}
                for ref in (candidate.get("references") or [])
                if isinstance(ref, dict) and isinstance(ref.get("url"), str)
                and ref["url"].startswith("https://")
            ],
        }
    result = {
        "schema": "ezstudio.profile.review.v1",
        "expertise_type": request["expertise_type"],
        "analysis_id": request["analysis_id"],
        "preset_id": request["preset_id"],
        "identity_confirmed": False,
        "reviews": reviews,
        "technical_status": investigation.get("status"),
        "technical_errors": investigation.get("technical_errors", 0),
    }
    validate_verdicts(request, result)
    if finals is not None:
        result["final_assertions_schema"] = "ezstudio.profile-final-assertions.v1"
        result["final_assertions_count"] = len(finals)
        result["final_reviews"] = build_final_reviews(finals, investigation)
        validate_final_reviews(finals, result)
    # Documentary provenance, not scientific evidence. No raw page text stored.
    source_registry = {}
    item_provenance = {}
    for key in ITEMS:
        investigation_row = by_key.get(WEB_KEYS[key], {})
        sources = []
        for document in (investigation_row.get("documents") or []):
            if not isinstance(document, dict):
                continue
            url = document.get("url")
            if not isinstance(url, str) or not url.startswith("https://"):
                continue
            status = str(document.get("status") or "unread")
            scope = str(document.get("source_scope") or "unknown_candidate")
            priority = document.get("discovery_priority")
            entry = {"url": url, "read_status": status, "source_scope": scope,
                     "discovery_priority": priority,
                     "recording_identity_verified": False,
                     "evidence_eligible": False}
            if url not in source_registry:
                source_registry[url] = dict(entry)
            if all(other["url"] != url for other in sources):
                sources.append(entry)
        item_provenance[key] = {
            "candidate_sources": sources[:25],
            "candidate_count": len(sources),
            "explicit_reference_count": len(reviews[key]["references"]),
            "scientific_verdict": reviews[key]["verdict"],
            "recording_identity_verified": False,
        }
    result["structured_musicbrainz"] = investigation.get("structured_recording_candidates") or {"status":"not_available","candidates":[]}
    result["recording_reconciliation"] = compare_durations(result["structured_musicbrainz"], request["items"]["duree"])
    result["documentary_provenance"] = {
        "schema": "ezstudio.profile.documentary-provenance.v1",
        "distinct_source_count": len(source_registry),
        "readable_distinct_count": sum(
            1 for x in source_registry.values() if x["read_status"] == "read"),
        "items": item_provenance,
        "recording_identity_verified": False,
        "advisory_only": True,
    }
    # Only opt-in release scans supplied by the trusted caller are eligible.
    # Never infer release/recording identity from a cover or an OCR line.
    candidate_documents = payload.get("release_documents")
    approved_documents = []
    if isinstance(candidate_documents, list):
        for document in candidate_documents[:2]:
            if not isinstance(document, dict):
                continue
            url = _safe_url(document.get("document_url"))
            if url:
                approved_documents.append({
                    "document_url": url,
                    "release_id": str(document.get("release_id") or "")[:80],
                })
    if approved_documents:
        ocr = inspect_scans_with_local_ocr(approved_documents, request["title"], max_scans=2)
    else:
        ocr = {"status": "not_executed_no_scan_supplied", "scans": []}
    result["ocr_consultation"] = {
        "status": ocr.get("status"),
        "scans": [
            {"document_url": scan.get("document_url"),
             "status": scan.get("status"),
             "release_id": scan.get("release_id"),
             "credit_candidates": scan.get("credit_candidates", []),
             "evidence_eligible": False}
            for scan in ocr.get("scans", []) if isinstance(scan, dict)
        ],
        "advisory_only": True,
    }
    # Evidence and verdicts are finalized before advisory AI is consulted.
    result["ai_consultation"] = consult(
        {"title":request["title"],"artist":request["artist"]}, reviews
    ) if rows and finals is None else {"status": "not_executed_dynamic_contract" if finals is not None else "not_executed_no_assertions"}
    if result["ai_consultation"]["status"] == "error":
        result["technical_status"] = "partial"
        result["technical_errors"] += 1
    result["tool_audit"] = {
        "qwen":result["ai_consultation"]["status"],
        "ocr":result["ocr_consultation"]["status"],
        "ocr_enabled":booklet_ocr_enabled(),
        "translation":"qwen_advisory_notes_only",
        "document_search":investigation.get("status"),
    }
    return result

def process(envelope, token_reader=read_profile_token):
    if not isinstance(envelope, dict):
        raise ValueError("invalid_envelope")
    supplied = envelope.get("_auth_token")
    stored = token_reader()
    if not isinstance(supplied, str) or not stored or not hmac.compare_digest(supplied, stored):
        raise PermissionError("unauthorized")
    request = {k: v for k, v in envelope.items() if k != "_auth_token"}
    return verify(request)

def main():
    try:
        envelope = json.load(sys.stdin)
        answer = process(envelope)
        print(json.dumps(answer, ensure_ascii=False, allow_nan=False))
        return 0
    except PermissionError:
        print('{"error":"unauthorized"}')
        return 3
    except ValueError as exc:
        print(json.dumps({"error": "invalid_request", "reason": str(exc)[:120]}))
        return 2
    except Exception as exc:
        print(json.dumps({"error": "processing_failed", "type": type(exc).__name__}))
        return 1

if __name__ == "__main__":
    raise SystemExit(main())
