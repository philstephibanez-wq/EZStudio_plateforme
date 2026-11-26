"""R1 standalone documentary consensus; no LAB inputs at discovery boundary."""
import json, hashlib, re, unicodedata, math
from urllib.parse import urlsplit
from collections import defaultdict
from pathlib import Path
RULES=json.loads(Path(__file__).with_name('rules.json').read_text(encoding='utf-8'))

def norm(v):
    return ' '.join(''.join(c for c in unicodedata.normalize('NFKD',str(v).casefold()) if not unicodedata.combining(c)).split())

def canonical(item,v):
    if item in RULES['numeric']:
        if isinstance(v,bool): raise ValueError('boolean')
        x=float(str(v).lower().replace('bpm','').replace(',','.').strip())
        if not math.isfinite(x) or x<=0: raise ValueError('numeric')
        return x
    if item=='meter':
        s=str(v).replace(' ','')
        if not re.fullmatch(r'[1-9]\d*/(?:2|4|8|16|32)',s): raise ValueError('meter')
        return s
    if item=='key':
        m=re.fullmatch(r'([A-Ga-g])([#♯b♭]?)(?:\s*(major|minor|maj|min|m|majeur|mineur))?',str(v).strip())
        if not m: raise ValueError('key')
        n={'C':0,'D':2,'E':4,'F':5,'G':7,'A':9,'B':11}[m[1].upper()]+({'#':1,'♯':1,'b':-1,'♭':-1,'':0}[m[2]])
        if not m[3]: raise ValueError('mode unspecified')
        return ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'][n%12]+(' minor' if m[3] in ('minor','min','m','mineur') else ' major')
    s=norm(v)
    if not s: raise ValueError('empty')
    return RULES['aliases'].get(item,{}).get(s,s)

def build_profile(identity, documents):
    """Documents contain explicit source-level facts and exact quotations.
    Caller must independently qualify identity and provenance; no inference of absence.
    """
    ident={k:str(identity.get(k,'')) for k in ('title','artist','version','album')}
    groups=defaultdict(lambda:defaultdict(set)); evidence=defaultdict(list); consulted=set()
    for doc in documents:
        u=urlsplit(doc['url']); host=(u.hostname or '').removeprefix('www.')
        if u.scheme!='https' or not host or u.username or u.password: continue
        consulted.add(host)
        if doc.get('recording_identity_verified') is not True: continue
        # Domain grouping is a conservative proxy, not proof of independence.
        for fact in doc.get('facts',[]):
            item=fact.get('item'); quote=fact.get('quote'); value=fact.get('value')
            if item not in RULES['items'] or not isinstance(quote,str) or not quote.strip() or quote not in doc.get('text',''): continue
            try: val=canonical(item,value)
            except (ValueError,TypeError): continue
            groups[item][host].add(val)
            evidence[item].append({'domain':host,'url':doc['url'],'value':val,'quote':quote})
    items={}
    for item in RULES['items']:
        g=groups[item]; values=sorted({v for vs in g.values() for v in vs}); candidates=[]
        for v in values:
            tol=RULES['numeric'].get(item)
            observed=[x for x in values if v<=x<=v+tol] if tol is not None else [v]
            support=sorted(h for h,vs in g.items() if vs.intersection(observed))
            candidates.append({'value':(min(observed)+max(observed))/2 if tol is not None else v,'observed':observed,'domains':support,'support':len(support),'agreement_pct':round(100*len(support)/len(g),2)})
        # Deduplicate identical windows, preserve competing candidates.
        candidates=sorted(candidates,key=lambda x:(-x['support'],str(x['value'])))
        leaders=[c for c in candidates if c['support']==candidates[0]['support']] if candidates else []
        items[item]={'eligible_domains':len(g),'consulted_domains':len(consulted),'candidates':candidates,'leaders':leaders,'evidence':sorted(evidence[item],key=lambda x:(x['url'],str(x['value']),x['quote']))}
    return {'schema':'ezs.r6e.documentary-profile.prototype.v1','identity':ident,'items':items,'independence_certified':False}

def persist(profile,path):
    p=Path(path); data=json.dumps(profile,ensure_ascii=False,sort_keys=True,allow_nan=False).encode('utf-8');
    with p.open('xb') as f: f.write(data)
    return hashlib.sha256(data).hexdigest()

def compare(profile,lab):
    result={}
    for item,value in lab.items():
        rec=profile['items'].get(item,{}); leaders=rec.get('leaders',[])
        status='non_trouve'; compatibility=None
        if len(leaders)==1:
            c=leaders[0]
            try:
                x=canonical(item,value); y=c['value']; tol=RULES['numeric'].get(item)
                compatibility=abs(x-y)<=tol if tol is not None else x==y
            except (TypeError,ValueError): pass
        # No certified origin independence in this tranche: never issue official BON/FAUX.
        result[item]={'verdict':status,'compatibility_advisory':compatibility}
    return result
