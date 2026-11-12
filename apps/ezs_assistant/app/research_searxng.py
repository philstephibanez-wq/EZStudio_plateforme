"""Experimental no-key SearXNG JSON search transport for autonomous research.

Only loopback endpoints; no commercial API or model calls. No worker hooks.
"""
from __future__ import annotations

import json
from urllib.parse import urlencode, urlsplit
from urllib.request import Request, urlopen

class ResearchSearchError(RuntimeError):
    pass

def validate_endpoint(endpoint):
    u=urlsplit(endpoint)
    if (u.scheme!="http" or u.hostname not in ("127.0.0.1","localhost")
            or not u.port or u.username or u.password or u.query or u.fragment
            or u.path not in ("","/")):
        raise ValueError("SEARXNG_LOOPBACK_ENDPOINT_REQUIRED")
    return endpoint.rstrip("/")

def search(query,endpoint="http://127.0.0.1:8888",limit=8,transport=None):
    validate_endpoint(endpoint)
    if not isinstance(query,str) or not query.strip() or len(query)>240:
        raise ValueError("INVALID_RESEARCH_QUERY")
    if not isinstance(limit,int) or not 1<=limit<=20:
        raise ValueError("INVALID_RESULT_LIMIT")
    url=endpoint.rstrip("/")+"/search?"+urlencode({"q":query.strip(),"format":"json"})
    if transport is None:
        def transport(target):
            req=Request(target,headers={"Accept":"application/json"})
            with urlopen(req,timeout=20) as response:
                if response.status!=200:raise ResearchSearchError("SEARXNG_HTTP_"+str(response.status))
                return response.read(2_000_000)
    try:
        raw=transport(url)
    except Exception as error:
        raise ResearchSearchError("SEARXNG_UNAVAILABLE_OR_JSON_DISABLED") from error
    try:
        data=json.loads(raw)
    except (ValueError,TypeError) as error:
        raise ResearchSearchError("SEARXNG_INVALID_JSON") from error
    if not isinstance(data,dict) or not isinstance(data.get("results"),list):
        raise ResearchSearchError("SEARXNG_UNEXPECTED_SCHEMA")
    result=[]
    seen=set()
    for entry in data["results"]:
        if not isinstance(entry,dict):continue
        source=str(entry.get("url") or "").strip()
        parsed=urlsplit(source)
        if parsed.scheme not in ("http","https") or not parsed.hostname or parsed.username or parsed.password:
            continue
        if source in seen:continue
        seen.add(source)
        result.append({"url":source,"title":str(entry.get("title") or "")[:500],
                       "snippet":str(entry.get("content") or "")[:2000]})
        if len(result)>=limit:break
    return {"query":query.strip(),"results":result,"count":len(result),
            "status":"discovery_only_no_documentary_verdict"}

def main():
    import argparse
    p=argparse.ArgumentParser()
    p.add_argument("--endpoint",default="http://127.0.0.1:8888")
    p.add_argument("--query",default="hotel california eagles")
    p.add_argument("--limit",type=int,default=5)
    args=p.parse_args()
    print(json.dumps(search(args.query,args.endpoint,args.limit),ensure_ascii=False,indent=2))
if __name__=="__main__":
    main()
