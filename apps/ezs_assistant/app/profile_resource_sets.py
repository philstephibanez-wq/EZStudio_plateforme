"""Experimental resource sets: discover documents, scan all final items, intersect facts."""
from __future__ import annotations
from collections import defaultdict
import re
import unicodedata

def normalize(value):
    value=unicodedata.normalize("NFKD",str(value or "").casefold())
    return " ".join(re.findall(r"[a-z0-9]+","".join(c for c in value if not unicodedata.combining(c))))

def extract(text,assertions,url):
    """Literal auditable occurrences only; not semantic proof."""
    findings=[]
    for item in assertions:
        key=str(item.get("id") or item.get("key") or "")
        value=str(item.get("value") or "").strip()
        if not key or not value: continue
        for match in re.finditer(r"(?<!\w)"+re.escape(value)+r"(?!\w)",text,re.I):
            findings.append({"item_id":key,"value":value,"url":url,
                "offset":match.start(),"excerpt":text[max(0,match.start()-60):match.end()+60]})
    return findings

def consolidate(documents):
    sets={}
    occurrences=defaultdict(list)
    for document in documents:
        url=document["url"]
        if url in sets: raise ValueError("duplicate_resource")
        facts=set()
        for item in document.get("occurrences",[]):
            fact=(item["item_id"],normalize(item["value"]))
            facts.add(fact)
            occurrences[fact].append(item)
        sets[url]=facts
    union=set().union(*sets.values()) if sets else set()
    urls=sorted(sets)
    intersections=[{"sources":[a,b],"facts":[list(x) for x in sorted(sets[a]&sets[b])]}
        for i,a in enumerate(urls) for b in urls[i+1:] if sets[a]&sets[b]]
    return {"source_count":len(sets),"union":[list(x) for x in sorted(union)],
        "intersections":intersections,
        "frequencies":[{"fact":list(x),"documents":sum(x in z for z in sets.values()),
            "occurrences":len(occurrences[x])} for x in sorted(union)],
        "recording_identity_confirmed":False,"independence_verified":False,
        "scientific_verdicts":False}

def exploratory_pass(query,assertions,search,fetch_document,max_results=20):
    """One broad query, then scan every fetched document for EVERY emitted item."""
    hits=search(query,max_results=max_results)
    documents=[]
    seen=set()
    for hit in hits:
        url=hit.get("url")
        if not isinstance(url,str) or not url.startswith("https://") or url in seen:continue
        seen.add(url)
        document=fetch_document(url)
        if document.get("status")!="read":continue
        actual=document.get("url") or url
        if actual in {d["url"] for d in documents}:continue
        documents.append({"url":actual,"occurrences":extract(document.get("text") or "",assertions,actual)})
    return {"query":query,"hits":len(hits),"documents_read":len(documents),
        "sets":consolidate(documents)}
