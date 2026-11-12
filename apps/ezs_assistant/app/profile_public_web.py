"""Native DDGS discovery and conservative public document retrieval on Windows."""
from __future__ import annotations
import html
import ipaddress
import json
import os
import re
import urllib.parse
import urllib.request
from html.parser import HTMLParser

class _VisibleText(HTMLParser):
    def __init__(self):
        super().__init__()
        self.parts=[]
        self.hidden=0
        self.metadata=[]
    def handle_starttag(self,tag,attrs):
        if tag in ("script","style","noscript"):
            self.hidden+=1
    def handle_endtag(self,tag):
        if tag in ("script","style","noscript") and self.hidden:
            self.hidden-=1
    def handle_data(self,data):
        if not self.hidden and data.strip():
            self.parts.append(data.strip())

def _url(value):
    try:
        p=urllib.parse.urlsplit(str(value))
        if p.scheme!="https" or not p.hostname or p.username or p.password:
            return None
        if p.hostname.lower() in ("localhost",) or p.hostname.lower().endswith(".local"):
            return None
        try:
            if not ipaddress.ip_address(p.hostname).is_global:
                return None
        except ValueError:
            pass
        return p.geturl()
    except (TypeError,ValueError):
        return None

def search(query,max_results=10):
    """Native Python DDGS metasearch on Windows. No SearXNG daemon or API key.

    Fail closed with a technical error when all upstream engines are unavailable.
    """
    try:
        from ddgs import DDGS
    except ImportError as exc:
        raise RuntimeError("DDGS_NOT_INSTALLED: python -m pip install ddgs") from exc
    try:
        with DDGS(timeout=18) as engine:
            hits=list(engine.text(query,max_results=max_results+8))
    except Exception as exc:
        if "no results found" in str(exc).casefold():
            return []
        raise RuntimeError("DDGS_SEARCH_FAILED:"+type(exc).__name__+":"+str(exc)[:150]) from exc
    results=[]
    seen=set()
    for item in hits:
        if not isinstance(item,dict):
            continue
        link=_url(item.get("href") or item.get("url"))
        if not link or link in seen:
            continue
        seen.add(link)
        results.append({
            "url":link,
            "title":html.unescape(str(item.get("title") or ""))[:250],
            "snippet":html.unescape(re.sub(r"<[^>]+>"," ",
                 str(item.get("body") or item.get("content") or "")))[:600],
            "search_provider":"ddgs_native"})
        if len(results)>=max_results:
            break
    return results

def fetch_document(url):
    safe=_url(url)
    if not safe:
        return {"status":"unsafe_url"}
    request=urllib.request.Request(safe,headers={"Accept":"text/html,text/plain",
        "User-Agent":"EZStudioLabAssistant/1.7"})
    try:
        with urllib.request.urlopen(request,timeout=14) as response:
            final=_url(response.geturl())
            kind=str(response.headers.get("Content-Type") or "").lower()
            if not final:
                return {"status":"unsafe_redirect"}
            if not ("text/html" in kind or "text/plain" in kind):
                return {"status":"unsupported_content_type"}
            # Read a bounded prefix of large public pages; do not discard all useful text.
            raw=response.read(600_000)
        parser=_VisibleText()
        parser.feed(raw.decode("utf-8","replace"))
        body=re.sub(r"\s+"," "," ".join(parser.metadata+parser.parts)).strip()
        return {"status":"read","url":final,"text":body[:18000],
                "char_count":len(body)}
    except Exception as exc:
        return {"status":"read_error","reason":type(exc).__name__}
