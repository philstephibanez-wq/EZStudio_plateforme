"""Public web search for each PROFILE item, without paid API keys.
Search and read original webpages. A search result is not a musical verdict.
"""
from __future__ import annotations
import re
import unicodedata
from difflib import SequenceMatcher
from profile_final_verifier import final_assertions
from profile_public_web import search, fetch_document
from profile_live_evidence import evaluate_live_documents

from declarative_review import load_review_preset
from profile_musicbrainz_structured import discover_recordings, fetch_certified_recording_details, fetch_wikipedia_song_context

def _load_doc_research_cfg():
    try:
        preset = load_review_preset("profile")[0]
        return preset.get("documentary_research") or {}
    except Exception:
        return {}

def _is_blocked_page(text, cfg):
    low = str(text or "").casefold()
    if len(low.strip()) < 120:
        return True
    sigs = cfg.get("blocked_page_signatures") or [
        "verifying your browser",
        "just a moment...",
        "javascript is required to access this page",
        "please enable javascript",
    ]
    return any(str(s).casefold() in low for s in sigs)


def _tokens(value):
    value=unicodedata.normalize("NFKD",str(value).casefold())
    value="".join(c for c in value if not unicodedata.combining(c))
    return re.findall(r"[a-z0-9]+",value)

def _is_song_related(hit, title, artist):
    # Discovery tolerance only; NEVER recording-identity proof.
    haystack=_tokens((hit.get('title') or '')+' '+(hit.get('snippet') or '')+' '+(hit.get('url') or ''))
    artist_tokens=_tokens(artist)
    if artist_tokens and not all(t in haystack for t in artist_tokens):
        return False
    wanted=_tokens(title)
    if not wanted: return False
    for token in wanted:
        if token in haystack: continue
        if len(token)<5 or not any(SequenceMatcher(None,token,word).ratio()>=0.80 for word in haystack if len(word)>=5):
            return False
    return True


def _source_priority(hit):
    """Rank discovery URLs, never certify the exact recording."""
    from urllib.parse import urlsplit
    try:
        host=(urlsplit(hit.get("url") or "").hostname or "").lower()
    except ValueError:
        host=""
    if host=="musicbrainz.org":
        return (0,"musicbrainz_recording_or_release_candidate")
    if host in ("discogs.com","www.discogs.com"):
        return (1,"discogs_release_candidate")
    if host=="coverartarchive.org" or host.endswith(".archive.org"):
        return (2,"printed_document_candidate")
    return (3,"general_web_candidate")


TERMS={"duration":"track duration length minutes seconds","duree":"track duration length minutes seconds","tempo":"BPM tempo","meter":"time signature meter","mesure":"time signature meter","key":"musical key tonality","tonalite":"musical key tonality","genre":"genre style","lead_vocal":"lead vocals singer","voix_principale":"lead vocals singer","backing_vocals":"backing vocals","voix_accompagnement":"backing vocals","choir":"choir choral vocals","choeurs":"choir choral vocals","vocal_harmonies":"vocal harmonies","harmonies_vocales":"vocal harmonies"}

def research_each_assertion(row, identity, *, source_research=None, **unused):
    assertions=final_assertions(row)
    present={a["key"] for a in assertions}
    fr_map = {"backing_vocals":"voix_accompagnement","vocal_harmonies":"harmonies_vocales","choir":"choeurs","lead_vocal":"voix_principale"}
    for key,label in (("backing_vocals","Voix d’accompagnement"),
                      ("vocal_harmonies","Harmonies vocales"),("choir","Chœurs"),
                      ("lead_vocal","Voix principale")):
        if key not in present and fr_map[key] not in present:
            assertions.append({"key":key,"label":label,"value":"non déterminé",
                               "no_lab_proposition":True})
    return research_assertions(assertions, identity, source_research=source_research)


def research_assertions(assertions, identity, *, source_research=None):
    from profile_engine_diagnostic import record_item
    from time import perf_counter_ns as _profile_clock
    # Reuse the real release scans already inspected by the existing Tesseract
    # pipeline. Never OCR a second time, never mistake album credits for song credits.
    ocr_sources=[]
    for source in (source_research or {}).get("sources",[]):
        if not isinstance(source,dict):continue
        review=source.get("physical_credit_review") or {}
        for scan in review.get("scans",[]):
            if isinstance(scan,dict):
                ocr_sources.append({"url":scan.get("document_url"),
                    "release_id":scan.get("release_id"),
                    "status":scan.get("status"),
                    "credit_candidates":scan.get("credit_candidates",[]),
                    "evidence_eligible":False})

    title=str(identity.get("title") or "").strip()
    artist=str(identity.get("artist") or "").strip()
    # Legacy LAB imports sometimes store "Song - Artist" in the title field.
    # This is a *search hint*, never an authenticated recording identity.
    if not artist:
        match=re.fullmatch(r"(.+?)\s*[-–—]\s+([^–—]+)",title)
        if match and match.group(1).strip() and match.group(2).strip():
            title,artist=match.group(1).strip(),match.group(2).strip()
    structured=discover_recordings(title,artist,duration_ms=identity.get('catalog_duration_ms'))
    priority_docs = []
    album_for_wiki = ""
    if isinstance(structured, dict) and structured.get('verified') and isinstance(structured.get('certified_recording'), dict):
        cert_rec = structured['certified_recording']
        rels = cert_rec.get('releases') or []
        if rels and isinstance(rels[0], dict):
            album_for_wiki = str(rels[0].get('title') or '').strip()
        det = fetch_certified_recording_details(cert_rec.get('mbid'))
        structured['certified_details'] = det
        if isinstance(det, dict) and det.get('status') == 'completed':
            mb_summary = f"MusicBrainz Certified Recording: {det.get('title')} by {artist}. Duration: {det.get('duration_ms')} ms. Album: {album_for_wiki}. Genres: {', '.join(det.get('genres') or [])}. Tags: {', '.join(det.get('tags') or [])}. Relations: {det.get('relations')}"
            priority_docs.append({
                "url": det.get("url"),
                "title": f"MusicBrainz: {title} - {artist}",
                "snippet": mb_summary[:400],
                "discovery_priority": 0,
                "source_scope": "musicbrainz_certified_recording",
                "recording_identity_verified": True,
                "status": "read",
                "text": mb_summary[:8000],
            })
    if title and artist:
        for wdoc in fetch_wikipedia_song_context(title, artist, album=album_for_wiki):
            priority_docs.append({
                "url": wdoc["url"],
                "title": f"Wikipedia: {wdoc['title']}",
                "snippet": wdoc["text"][:300],
                "discovery_priority": 1,
                "source_scope": "wikipedia_encyclopedia",
                "recording_identity_verified": bool((structured or {}).get('verified')),
                "status": "read",
                "text": wdoc["text"][:8000],
            })
    items=[]
    document_cache={}
    effective_identity={"title":title,"artist":artist,"recording_identity_verified":bool((structured or {}).get("verified")),"certified_recording":(structured or {}).get("certified_recording")}
    ocr_audit={"status":"not_available" if not source_research else "recorded", "scans":ocr_sources}
    # Search broadly once, then reuse relevant sources for every item.
    base_hits=[]
    base_error=None
    base_errors=[]
    if title and artist:
        # Preserve successful hits if the other global query fails.
        for query, limit in (
            (artist+" "+title+" song BPM key credits instruments", 12),
            (artist+" "+title+" musicbrainz recording credits", 8),
        ):
            try:
                base_hits.extend(h for h in search(query,max_results=limit)
                                 if _is_song_related(h,title,artist))
            except Exception as exc:
                base_errors.append(type(exc).__name__)
        base_hits.sort(key=lambda h:_source_priority(h)[0])
        base_error="; ".join(base_errors) if base_errors else None
    for assertion in assertions:
        _profile_assertion_start = _profile_clock()
        key=assertion["key"]
        item={"key":key,"value":assertion["value"],"status":"read" if priority_docs else "not_searched","citation_urls":[d["url"] for d in priority_docs],
              "documents":list(priority_docs),"eligible_for_verdict":bool(priority_docs)}
        if not title:
            item["status"]="identity_unavailable"
            items.append(item)
            record_item("assertion_web_investigator", item, _profile_assertion_start)
            continue
        cfg = _load_doc_research_cfg()
        templates = cfg.get("dimension_query_templates") or {}
        dim_key = "instruments" if key.startswith(("instruments:", "instrument:")) else key
        tpl = templates.get(dim_key)
        album_hint = ""
        cert = (structured or {}).get("certified_recording") if isinstance(structured, dict) else None
        if isinstance(cert, dict) and cert.get("releases"):
            first_rel = cert["releases"][0] if isinstance(cert["releases"], list) and cert["releases"] and isinstance(cert["releases"][0], dict) else {}
            album_hint = str(first_rel.get("title") or "").strip()
        if tpl:
            query = tpl.format(
                artist=artist,
                title=title,
                album=album_hint,
                value=str(assertion.get("value") or "")
            )
            query = " ".join(query.split())
        else:
            topic = TERMS.get(key, f"{assertion.get('value', '')} instruments credits personnel" if key.startswith(("instruments:", "instrument:")) else key)
            query = artist + ' ' + title + ' song music ' + topic if artist else title + ' song music ' + topic
        item["query"]=query
        try:
            try:
                specific_hits=search(query,max_results=8)
            except Exception as exc:
                specific_hits=[]
                item["specific_search_error"]=type(exc).__name__
            hits=sorted(base_hits+specific_hits,key=lambda h:_source_priority(h)[0])
            item["search_hits"]=len(hits)
            if base_error:item["base_search_error"]=base_error
            search_errors=list(base_errors)
            if item.get("specific_search_error"):
                search_errors.append(item["specific_search_error"])
            item["search_error_count"]=len(search_errors)
            item["search_query_count"]=3 if title and artist else 1
            seen_urls=set()
            for hit in hits:
                if not _is_song_related(hit,title,artist):
                    continue
                url=hit.get("url")
                if not url or url in seen_urls:continue
                seen_urls.add(url)
                priority,source_scope=_source_priority(hit)
                doc={"url":url,"title":hit.get("title"),"snippet":hit.get("snippet"),
                     "discovery_priority":priority,"source_scope":source_scope,
                     "recording_identity_verified":False,
                     "status":"unread"}
                try:
                    if url not in document_cache:
                        document_cache[url]=fetch_document(url)
                    source=document_cache[url]
                    doc["status"]=source.get("status")
                    if source.get("reason"): doc["reason"]=source["reason"]
                    if source.get("status")=="read":
                        txt = str(source.get("text") or "")
                        if _is_blocked_page(txt, _load_doc_research_cfg()):
                            doc["status"] = "blocked_js_challenge"
                        else:
                            doc["url"]=source.get("url") or url
                            doc["text"]=txt[:8000]  # Source text only: search snippets cannot become evidence.
                except Exception as exc:
                    doc["status"]="read_error"
                    doc["error_type"]=type(exc).__name__
                item["documents"].append(doc)
                if doc["status"]=="read":
                    item["citation_urls"].append(doc["url"])
            item["rejected_irrelevant"]=len(hits)-len(item["documents"])
            item["status"]="documents_read" if any(d.get("status")=="read" for d in item["documents"]) else (
                "error" if search_errors and not hits else
                "no_results" if not hits else "no_relevant_results" if not item["documents"] else "sources_unreadable")
            if item["status"]=="error":
                item["reason"]="web_search_unavailable"
        except Exception as exc:
            item["status"]="error"
            item["reason"]=type(exc).__name__+":"+str(exc)[:180]
        item["evidence_review"]=evaluate_live_documents(assertion,effective_identity,item["documents"])
        item["ocr_scans"]=ocr_sources
        items.append(item)
        record_item("assertion_web_investigator", item, _profile_assertion_start)
    failed=sum(item.get("status")=="error" for item in items)
    degraded=sum(bool(item.get("search_error_count")) for item in items)
    shared = {d["url"]: d for it in items for d in (it.get("documents") or []) if d.get("status") == "read" and d.get("url")}
    if shared:
        for it in items:
            seen = {d.get("url") for d in (it.get("documents") or []) if d.get("status") == "read"}
            for u, sd in shared.items():
                if u not in seen:
                    it.setdefault("documents", []).append(dict(sd))
                    it.setdefault("citation_urls", []).append(u)
            it["status"] = "documents_read"
            it["eligible_for_verdict"] = True
            it["evidence_review"] = evaluate_live_documents({"key": it["key"], "value": it["value"]}, effective_identity, it["documents"])
    return {"status":"error" if failed==len(items) and items else "partial" if (failed or degraded) else "completed" if items else "no_assertions",
            "reason":"web_search_failed_for_all_items" if failed==len(items) and items else
                     "web_search_partially_unavailable" if degraded else None,
            "technical_errors":sum(item.get("status")=="error" or bool(item.get("search_error_count")) for item in items),
            "unique_documents_fetched":len(document_cache),
            "structured_recording_candidates":structured,
            "ocr_audit":ocr_audit,
            "searched":len(items),"items":items,
            "search_provider":"ddgs_native","automatic_verdicts":False}
