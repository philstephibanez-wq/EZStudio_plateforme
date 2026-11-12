"""Experimental, bounded open-web discovery of resource sets; not a verdict engine."""
from __future__ import annotations
from difflib import SequenceMatcher
import re
import unicodedata
from profile_resource_sets import extract, consolidate

STOP=frozenset("the a an of and or in on at for to de du des le la les un une et en das der die ein eine el los las y o da do dos das um uma".split())
HINT=frozenset("live studio remaster remastered acoustic single album version cover instrumental original concert release edition recording".split())

def tokens(text):
    value="".join(c for c in unicodedata.normalize("NFKD",str(text or "").casefold()) if not unicodedata.combining(c))
    return [w for w in re.findall(r"[a-z0-9]+",value) if w not in STOP]

def compact_query(title,artist):
    return " ".join(dict.fromkeys(tokens(title)[:5]+tokens(artist)[:3]))

def _match(word,found):
    return any(word==w or (len(word)>=5 and len(w)>=5 and SequenceMatcher(None,word,w).ratio()>=.78) for w in found)

def relevance(hit,title,artist):
    hay=tokens(str(hit.get("title") or "")+" "+str(hit.get("snippet") or ""))
    name=tokens(title)
    performer=tokens(artist)
    if not name or not hay:return 0.0
    title_ratio=sum(_match(w,hay) for w in name)/len(name)
    if title_ratio<.5:return 0.0
    artist_ratio=sum(_match(w,hay) for w in performer)/len(performer) if performer else 1.0
    return round(.7*title_ratio+.3*artist_ratio,3)

def _next_queries(title,artist,selected,issued):
    base=compact_query(title,artist)
    observed=[]
    for hit in selected:
        for word in tokens(str(hit.get("title") or "")+" "+str(hit.get("snippet") or "")[:180]):
            if (word in HINT or re.fullmatch(r"(?:19|20)\d{2}",word)) and word not in observed:
                observed.append(word)
    queries=[base+" "+word for word in observed[:4]]
    if not queries:
        queries=[" ".join(tokens(title)[:5])]
        if artist:queries.append(base+" recording")
    return [q for q in queries if q and q not in issued]

def discover_source_sets(identity,assertions,search,fetch_document,*,rounds=3,
                         max_results=15,max_documents=35,min_relevance=.65):
    """Discover sources first; scan EVERY final musical assertion per page."""
    if not 1<=rounds<=5 or not 1<=max_results<=50 or not 1<=max_documents<=150:
        raise ValueError("invalid_discovery_budget")
    title=str(identity.get("title") or "").strip()
    artist=str(identity.get("artist") or "").strip()
    first=compact_query(title,artist)
    if not title or not first:
        return {"status":"identity_unavailable","rounds":[],"documents_read":0,"sets":consolidate([])}
    queue=[first]
    issued=set()
    visited=set()
    documents=[]
    audit=[]
    for step in range(rounds):
        if not queue or len(documents)>=max_documents:break
        query=queue.pop(0)
        if query in issued:continue
        issued.add(query)
        hits=search(query,max_results=max_results)
        ranked=sorted(((relevance(hit,title,artist),hit) for hit in hits if isinstance(hit,dict)),
                      key=lambda pair:pair[0],reverse=True)
        selected=[hit for score,hit in ranked if score>=min_relevance]
        report={"round":step+1,"query":query,"hits":len(hits),"selected":len(selected),"read":0,
                "rejected":[{"url":hit.get("url"),"reason":"metadata_low_relevance"}
                            for score,hit in ranked if score<min_relevance]}
        for hit in selected:
            url=hit.get("url")
            if not isinstance(url,str) or not url.startswith("https://") or url in visited:continue
            if len(documents)>=max_documents:break
            visited.add(url)
            doc=fetch_document(url)
            if doc.get("status")!="read":
                report.setdefault("unread",[]).append({"url":url,"status":doc.get("status")})
                continue
            actual=str(doc.get("url") or url)
            visited.add(actual)
            if any(x["url"]==actual for x in documents):continue
            documents.append({"url":actual,"occurrences":extract(str(doc.get("text") or ""),assertions,actual)})
            report["read"]+=1
        audit.append(report)
        for new_query in _next_queries(title,artist,selected,issued):
            if new_query not in queue:queue.append(new_query)
    return {"status":"completed","initial_query":first,"rounds":audit,
            "documents_read":len(documents),"sets":consolidate(documents),
            "identity_verified":False,"source_independence_verified":False,
            "automatic_verdicts":False,
            "limitations":["literal_extraction_only","html_text_only","no_ocr",
                           "no_follow_links","metadata_relevance_only"]}
