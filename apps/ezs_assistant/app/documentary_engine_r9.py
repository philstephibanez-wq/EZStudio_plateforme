"""R9: isolated, evidence-first documentary acquisition. No scientific observations.

All claims remain provisional until an independent recording/origin review.
JSON provider fields are documentary evidence, not fabricated textual quotations.
"""
from collections import defaultdict
from datetime import datetime, timezone
import hashlib
import ipaddress
import http.client
import ssl
import json
import math
import os
from pathlib import Path
import re
import socket
import time
import urllib.parse as up
from html.parser import HTMLParser
from documentary_profile import RULES, canonical, norm

QUERIES = {
    'tempo': 'song BPM tempo', 'duration': 'track duration running time',
    'meter': 'time signature sheet music mesure', 'key': 'song key tonalité',
    'genre': 'musical genre style chanson', 'instrument': 'recording personnel musicians instruments crédits',
    'lead_vocal': 'lead singer lead vocal recording credits',
    'backing_vocals': 'backing vocals choristes recording credits',
    'vocal_harmonies': 'vocal harmonies recording credits', 'choir': 'choir chœur recording credits',
    'year': 'original recording first release sortie disque',
}
LABELS = {'tempo':'Tempo','duration':'Durée','meter':'Mesure','key':'Tonalité','genre':'Genre','instrument':'Instrumentation','lead_vocal':'Voix principale','backing_vocals':'Voix d’accompagnement','vocal_harmonies':'Harmonies vocales','choir':'Chœur','year':'Année'}
MAX_DOCS = 66
MAX_NEW_PER_ITEM = 6
MAX_RETAINED_TEXT = 3500
MAX_HITS = 8
MAX_SECONDS = 240
TECH_HOSTS = {'songbpm.com','tunebat.com','getsongbpm.com','musicstax.com'}
STREAM_HOSTS = {'spotify.com','apple.com','deezer.com'}
NOISE = ('recommended based on this song','popular tracks by','similar songs','other songs by','you may also like','more by','sign in to see lyrics','titres similaires','recommandé d’après','other tracks','popular songs')
BAD_VERSION = re.compile(r'\b(karaoke|tribute|cover version|instrumental version|remix|live at|sped up|slowed down)\b',re.I)
INSTR = r'guitar|guitare|piano|bass guitar|bass|basse|drums|batterie|violin|violon|saxophone|trumpet|trompette|harmonica|accordion|accordéon|organ|orgue|flute|flûte|clarinet|clarinette|percussion|synthesizer|synthétiseur|mandolin|mandoline|cello|violoncelle'
VOCALS = {'lead_vocal':r'lead vocals?|voix principale|chant principal','backing_vocals':r'backing vocals?|background vocals?|voix d.accompagnement|choristes','vocal_harmonies':r'vocal harmonies|harmonies vocales','choir':r'choir|chœurs|choeurs|chorale'}
PATTERNS = {
    'tempo':r'\b(?:(?:tempo|bpm)\s*[:=]?\s*(\d{2,3}(?:[.,]\d+)?)|(\d{2,3}(?:[.,]\d+)?)\s*(?:BPM|beats per minute|battements par minute))\b',
    'duration':r'\b(?:duration|length|running time|track length|durée)\s*[:=]?\s*(\d{1,2}:\d{2})\b',
    'meter':r'\b(?:time signature|meter|metre|mesure)\s*[:=]?\s*([1-9]\d*/(?:2|4|8|16|32))\b',
    'key':r'\b(?:musical key|key|tonalité)\s*[:=]?\s*([A-G][#♯b♭]?\s*(?:major|minor|maj|min|majeur|mineur))\b',
    'genre':r'\b(?:genre|style)\s*[:=]\s*([a-zA-ZÀ-ÿ]+(?:[ -][a-zA-ZÀ-ÿ]+){0,2})(?=[.;,|]|$)',
    'instrument':r'\b(?:instruments?|played|plays|joue(?: de la| du)?|guitariste|musicien)\s*[:=]?\s*('+INSTR+r')\b',
    'year':r'\b(?:first released|originally released|released|release year|sortie|année de sortie|paru en|was released)\s*[:=]?\s*(?:in\s+|en\s+)?(?:janvier|février|mars|avril|mai|juin|juillet|août|septembre|octobre|novembre|décembre|January|February|March|April|May|June|July|August|September|October|November|December)?\s*((?:19|20)\d{2})\b',
}

def identity_only(value):
    return {k:str(value.get(k) or '').strip()[:250] for k in ('title','artist','album','version')}

def identity_norm(value):
    # Apostrophes within names are orthographic, not word separators.
    return ' '.join(re.sub(r'[^\w]+', ' ', re.sub(r"['’‘ʼ`´]", '', norm(value))).split())

def contains(text,value):
    v=identity_norm(value)
    return bool(v) and bool(re.search(r'(?<!\w)'+re.escape(v)+r'(?!\w)',identity_norm(text)))

def error_reason(exc):
    # Only controlled error codes; never exception messages containing URLs/keys.
    msg=str(exc)
    if re.fullmatch(r'http_[1-5][0-9]{2}|unsafe_url|unsafe_address|too_many_redirects|authenticated_redirect_rejected|redirect_without_location|invalid_schema|response_too_large|recording_id_mismatch|identity_mismatch|DDGS_NOT_INSTALLED',msg): return msg
    if msg.startswith('DDGS_NOT_INSTALLED:'): return 'DDGS_NOT_INSTALLED'
    if msg.startswith('DDGS_SEARCH_UNAVAILABLE:'): return 'DDGS_SEARCH_UNAVAILABLE'
    return type(exc).__name__

def alternate_edition(value):
    # A live venue/date in a release title is not the studio recording.
    return bool(BAD_VERSION.search(value) or re.search(r'\b(live|concert|closing night|opening night|soundboard|rehearsal|alternate take|outtake|session take)\b',value,re.I))


def host_of(url):
    try:
        u=up.urlsplit(url)
        if u.scheme!='https' or not u.hostname or u.username or u.password or u.port not in (None,443): return ''
        return u.hostname.lower().removeprefix('www.')
    except (TypeError,ValueError): return ''

def family(url):
    host=host_of(url)
    # Collapse known publisher/platform subdomains. Not a proof of independence.
    for suffix in ('spotify.com','apple.com','musicbrainz.org','youtube.com','deezer.com','wikipedia.org','discogs.com','songbpm.com','tunebat.com','getsongbpm.com','musicstax.com'):
        if host==suffix or host.endswith('.'+suffix): return suffix
    return host

def clean_text(text):
    return re.sub(r'\s+',' ',str(text or '')).strip()

def resolved_public_ips(url):
    if not host_of(url): raise ValueError('unsafe_url')
    hostname=up.urlsplit(url).hostname
    if hostname=='localhost' or hostname.endswith(('.local','.internal','.localhost')): raise ValueError('unsafe_url')
    ips=list(dict.fromkeys(a[4][0] for a in socket.getaddrinfo(hostname,443,type=socket.SOCK_STREAM)))
    if not ips or not all(ipaddress.ip_address(ip).is_global for ip in ips): raise ValueError('unsafe_address')
    return hostname,ips

class PublicHTTPS(http.client.HTTPSConnection):
    def __init__(self,host,ip):
        super().__init__(host,443,timeout=10,context=ssl.create_default_context())
        self.ip=ip
    def connect(self):
        # Connect to the validated literal address, with original-host TLS SNI and verification.
        raw=socket.create_connection((self.ip,443),self.timeout)
        try: self.sock=self._context.wrap_socket(raw,server_hostname=self.host)
        except BaseException: raw.close(); raise

class Text(HTMLParser):
    def __init__(self): super().__init__(convert_charrefs=True); self.parts=[]; self.hidden=0; self.metadata=[]; self.in_title=False
    def handle_starttag(self,tag,attrs):
        if tag in ('script','style','noscript'): self.hidden+=1
        if tag=='title': self.in_title=True
        if tag=='meta':
            a=dict(attrs)
            if (a.get('property') or a.get('name')) in ('og:title','og:description','description','twitter:title') and a.get('content'):
                self.metadata.append(a['content'][:1000])
    def handle_endtag(self,tag):
        if tag in ('script','style','noscript') and self.hidden: self.hidden-=1
        if tag=='title': self.in_title=False
    def handle_data(self,data):
        if not self.hidden and data.strip():
            (self.metadata if self.in_title else self.parts).append(data.strip())

def request(url,accept,limit,headers=None):
    # No credentials or proxy. Validate DNS and pin the connected address for every redirect.
    for _ in range(3):
        hostname,ips=resolved_public_ips(url)
        u=up.urlsplit(url); target=up.urlunsplit(('', '', u.path or '/', u.query, ''))
        # Prefer IPv4 where IPv6 routing on MAIN is unavailable; verify all DNS answers above.
        ip=sorted(ips,key=lambda v:':' in v)[0]
        conn=PublicHTTPS(hostname,ip)
        try:
            conn.request('GET',target,headers={'Accept':accept,'User-Agent':'EZStudioLabAssistant/1.8 (documentary research; https://github.com/philstephibanez-wq/EZStudio_plateforme)',**(headers or {})})
            with conn.getresponse() as res:
                if res.status in (301,302,303,307,308):
                    if headers: raise ValueError('authenticated_redirect_rejected')
                    dest=res.getheader('Location')
                    if not dest: raise ValueError('redirect_without_location')
                    url=up.urljoin(url,dest); continue
                if not 200<=res.status<300: raise ValueError('http_'+str(res.status))
                return url,str(res.getheader('Content-Type','')).lower(),res.read(limit+1)
        finally: conn.close()
    raise ValueError('too_many_redirects')

def fetch_public(url):
    try:
        final,kind,raw=request(url,'text/html,text/plain',600000)
        if not ('text/html' in kind or 'text/plain' in kind): return {'status':'unsupported_content_type'}
        parser=Text(); parser.feed(raw[:600000].decode('utf-8','replace'))
        return {'status':'read','url':final,'text':clean_text(' '.join(parser.metadata+parser.parts))[:18000],'truncated':len(raw)>600000}
    except Exception as e: return {'status':'read_error','reason':error_reason(e)}

def json_transport(url,headers=None):
    final,kind,raw=request(url,'application/json',1000000,headers)
    if len(raw)>1000000: raise ValueError('response_too_large')
    return json.loads(raw)

def _duration(value):
    if isinstance(value,str) and ':' in value:
        m,s=map(int,value.split(':'))
        if s>=60: raise ValueError('invalid_seconds')
        return m*60+s
    return value

def claim(item,value,quote,scope,method='text',field=None):
    try:
        if item=='duration': value=_duration(value)
        if item=='key':
            v=norm(value).replace('♯','#').replace('♭','b')
            m=re.fullmatch(r'(do|re|mi|fa|sol|la|si|[a-g])\s*(#|b|diese|bemol)?\s*(major|minor|maj|min|m|majeur|mineur)',v)
            if not m: return None
            note={'do':'C','re':'D','mi':'E','fa':'F','sol':'G','la':'A','si':'B'}.get(m[1],m[1].upper())
            acc={'diese':'#','bemol':'b',None:''}.get(m[2],m[2])
            mode='minor' if m[3] in ('minor','min','m','mineur') else 'major'
            value=note+acc+' '+mode
        value=canonical(item,value)
        if item=='tempo' and not 20<=value<=300: return None
        if item=='duration' and not 1<=value<=7200: return None
        if item=='year' and not (1850<=value<=datetime.now(timezone.utc).year and value.is_integer()): return None
        if item in VOCALS and value not in ('present','absent'): return None
    except (TypeError,ValueError,OverflowError): return None
    if not isinstance(quote,str) or not quote.strip(): return None
    f={'item':item,'value':value,'quote':quote,'scope':scope,'method':method}
    if field: f['field']=field
    return f

def add(out,*args,**kwargs):
    f=claim(*args,**kwargs)
    if f and f not in out: out.append(f)

def relevant(text,identity):
    identity=identity_only(identity)
    # Both artist and title in the leading track context. Exact recording still unknown.
    s=text[:3500]
    if any(v in norm(s[:1000]) for v in ('not supported by your browser','pas optimise pour votre navigateur','enable javascript','access denied','verify you are human')): return 'access_interstitial'
    if not contains(s,identity['title']): return 'title_not_found'
    if not contains(s,identity['artist']): return 'artist_not_found'
    n=identity_norm(s); t=identity_norm(identity['title']); a=identity_norm(identity['artist'])
    if not any(abs(x.start()-y.start())<=350 for x in re.finditer(r'(?<!\w)'+re.escape(t)+r'(?!\w)',n) for y in re.finditer(r'(?<!\w)'+re.escape(a)+r'(?!\w)',n)):
        return 'identity_context_too_distant'
    if BAD_VERSION.search(s[:500]) and not (identity['version'] and contains(s[:500],identity['version'])): return 'alternate_version'
    if identity['album'] and not contains(s,identity['album']): return 'album_not_confirmed'
    if identity['version'] and not contains(s,identity['version']): return 'version_not_confirmed'
    if any(v in norm(s) for v in ('not supported by your browser','pas optimise pour votre navigateur','enable javascript','access denied','verify you are human')): return 'access_interstitial'
    return None

def text_facts(text,url,identity):
    # Bound extraction to the track header or pre-recommendation region.
    # Never infer a missing vocal/instrument from lack of credits.
    prefix=text[:2200]
    low=prefix.casefold(); cuts=[low.find(x) for x in NOISE if low.find(x)>=0]
    if cuts: prefix=prefix[:min(cuts)]
    out=[]; fam=family(url)
    # Streaming catalog display has a specific contextual shape: track, artist,
    # edition, year, MM:SS. The edition year is not the recording's original year.
    if fam=='spotify.com' and '/track/' in up.urlsplit(url).path:
        for m in re.finditer(r'\b(?:19|20)\d{2}\s+(\d{1,2}:\d{2})\b',prefix,re.I):
            context=prefix[max(0,m.start()-260):m.end()]
            if all(contains(context,identity[k]) for k in ('title','artist')):
                add(out,'duration',m[1],context,'track_edition','contextual_track_header')
                break
        # Never treat a Spotify album date, recommendation, or genre as original recording evidence.
        return out
    for item,pattern in PATTERNS.items():
        if item=='year' and fam in STREAM_HOSTS: continue
        for m in re.finditer(pattern,prefix,re.I):
            # Keep exact quote; separate first-release claim from a generic date.
            scope='release_date_claim_not_original_certified' if item=='year' else ('track_credits_claim' if item=='instrument' else 'track_claim')
            add(out,item,next(g for g in m.groups() if g is not None),m[0],scope)
    for m in re.finditer(r'\b(?:duration|length|running time|durée)\s*[:=]?\s*(\d{1,2})\s*(?:minutes?|min|m)\s*(\d{1,2})\s*(?:seconds?|secondes?|sec|s)\b',prefix,re.I):
        if int(m[2])<60: add(out,'duration',int(m[1])*60+int(m[2]),m[0],'track_claim')
    for m in re.finditer(r'\b(?:musical key|key|tonalité)\s*[:=]?\s*((?:do|ré|re|mi|fa|sol|la|si|[A-G])\s*(?:[#♯b♭]|dièse|bémol)?\s*(?:major|minor|maj|min|m|majeur|mineur))\b',prefix,re.I):
        add(out,'key',m[1],m[0],'track_claim')
    # Explicit named credits only; generic mentions of an instrument or vocal role are not proof.
    person=r'[A-ZÀ-ÖØ-Þ][a-zà-öø-ÿ]+(?:[ -][A-ZÀ-ÖØ-Þ][a-zà-öø-ÿ]+){1,3}'
    for m in re.finditer(r'(?i:\b('+INSTR+r')\s*[:–—]\s*)('+person+r')',prefix):
        add(out,'instrument',m[1],m[0],'track_credits_claim')
    # Technical sites often display "Key ...", "Time Signature ...", "120 BPM".
    # No naked number mining, and don't interpret generic prose as proof.
    if fam in TECH_HOSTS:
        for m in re.finditer(r'\b(?:key|tonality)\s*[:=]?\s*([A-G][#♯b♭]?\s*(?:major|minor|maj|min))\b',prefix,re.I):
            add(out,'key',m[1],m[0],'track_claim')
        for m in re.finditer(r'\b(?:time signature|meter)\s*[:=]?\s*([1-9]\d*/(?:2|4|8|16|32))\b',prefix,re.I):
            add(out,'meter',m[1],m[0],'track_claim')
    for item,role in VOCALS.items():
        for m in re.finditer(r'\b(?:'+role+r')\s*[:=]\s*(present|présent|absent)\b',prefix,re.I):
            add(out,item,'present' if norm(m[1])=='present' else 'absent',m[0],'track_credits_claim')
        for m in re.finditer(r'(?i:\b(?:'+role+r')\s*[:–—]\s*)('+person+r')',prefix):
            add(out,item,'present',m[0],'track_credits_claim')
    return out

def _structured_doc(url,provider,record,claims,api_url):
    # Store only relevant metadata, never preview URLs/audio or arbitrary full API responses.
    text=json.dumps(record,ensure_ascii=False,sort_keys=True,allow_nan=False)
    return {'url':url,'title':str(record.get('title') or record.get('trackName') or ''),'text':text,'facts':claims,'provider':provider,'acquisition_url':api_url,'candidate_relevant':True,'recording_identity_verified':False,'content_sha256':hashlib.sha256(text.encode()).hexdigest()}

def _jclaim(out,item,value,record,field,scope):
    quote=json.dumps(record[field],ensure_ascii=False,sort_keys=True,allow_nan=False)
    add(out,item,value,quote,scope,'structured_json',field)

def _exact_title_artist(title,artist,ident): return identity_norm(title)==identity_norm(ident['title']) and identity_norm(artist)==identity_norm(ident['artist'])

def structured(identity,transport,cancel,technical_transport=None):
    docs=[]; diagnostics=[]
    def call(provider,url,fn,request_json=transport):
        cancel(); start=time.monotonic()
        entry={'provider':provider,'url':url,'status':'ok','accepted':0,'rejected':[]}
        try:
            data=request_json(url)
            before=len(docs); fn(data,entry,url); entry['accepted']=max(entry['accepted'],len(docs)-before)
            if not entry['accepted']: entry['status']='no_exact_candidate'
        except Exception as exc:
            entry['status']='error'; entry['reason']=error_reason(exc)
        entry['duration_ms']=round((time.monotonic()-start)*1000,1); diagnostics.append(entry)
    def musicbrainz(data,entry,url):
        if not isinstance(data,dict) or not isinstance(data.get('recordings'),list): raise ValueError('invalid_schema')
        for row in data['recordings'][:20]:
            if not isinstance(row,dict): continue
            mid=row.get('id'); credits=row.get('artist-credit') or []
            names=''.join(str(c.get('name') or (c.get('artist') or {}).get('name') or '')+str(c.get('joinphrase') or '') for c in credits if isinstance(c,dict))
            releases=[r for r in row.get('releases',[]) if isinstance(r,dict)]
            if not isinstance(mid,str) or not re.fullmatch(r'[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}',mid) or not _exact_title_artist(row.get('title',''),names,identity):
                entry['rejected'].append({'id':str(mid)[:80],'reason':'identity_mismatch'}); continue
            if identity['album'] and not any(norm(r.get('title'))==norm(identity['album']) for r in releases):
                entry['rejected'].append({'id':mid,'reason':'album_not_confirmed'}); continue
            if identity['version'] and not contains(str(row.get('disambiguation') or ''),identity['version']):
                entry['rejected'].append({'id':mid,'reason':'version_not_confirmed'}); continue
            if not identity['version'] and (alternate_edition(str(row.get('disambiguation') or '')) or (releases and all(alternate_edition(str(r.get('title') or '')) for r in releases))):
                entry['rejected'].append({'id':mid,'reason':'alternate_version'}); continue
            record={'id':mid,'title':row['title'],'artist_credit':names,'disambiguation':row.get('disambiguation',''),'releases':[{'title':r.get('title'),'date':r.get('date')} for r in releases[:12]]}
            claims=[]
            if type(row.get('length')) is int:
                record['length']=row['length']; _jclaim(claims,'duration',row['length']/1000,record,'length','recording_candidate')
            date=row.get('first-release-date')
            if isinstance(date,str) and re.match(r'^\d{4}(?:-|$)',date):
                record['first-release-date']=date  # Indexed edition date only; never original-year evidence.
            # Recording-level genres only; don't treat arbitrary tags as genres.
            for g in row.get('genres',[]) or []:
                if isinstance(g,dict) and isinstance(g.get('name'),str):
                    record['genres']=[x.get('name') for x in row['genres'] if isinstance(x,dict) and isinstance(x.get('name'),str)]
                    _jclaim(claims,'genre',g['name'],record,'genres','recording_genre')
            docs.append(_structured_doc('https://musicbrainz.org/recording/'+mid,'musicbrainz',record,claims,url))
    def itunes(data,entry,url):
        if not isinstance(data,dict) or not isinstance(data.get('results'),list): raise ValueError('invalid_schema')
        for row in data['results'][:30]:
            if not isinstance(row,dict): continue
            mid=row.get('trackId'); title=row.get('trackName',''); artist=row.get('artistName','')
            if type(mid) is not int or row.get('kind')!='song' or not _exact_title_artist(title,artist,identity):
                entry['rejected'].append({'id':str(mid)[:80],'reason':'identity_mismatch'}); continue
            if not identity['version'] and alternate_edition(str(row.get('collectionName') or '')):
                entry['rejected'].append({'id':mid,'reason':'alternate_version'}); continue
            if identity['album'] and norm(row.get('collectionName'))!=norm(identity['album']):
                entry['rejected'].append({'id':mid,'reason':'album_not_confirmed'}); continue
            if identity['version'] and not contains(title,identity['version']):
                entry['rejected'].append({'id':mid,'reason':'version_not_confirmed'}); continue
            ref=str(row.get('trackViewUrl') or '')
            if family(ref)!='apple.com': ref='https://itunes.apple.com/lookup?id='+str(mid)
            record={k:row[k] for k in ('trackId','trackName','artistName','collectionName','releaseDate','trackTimeMillis','primaryGenreName') if k in row}
            claims=[]
            if type(row.get('trackTimeMillis')) is int: _jclaim(claims,'duration',row['trackTimeMillis']/1000,record,'trackTimeMillis','track_edition')
            if isinstance(row.get('primaryGenreName'),str) and row['primaryGenreName'].strip(): _jclaim(claims,'genre',row['primaryGenreName'],record,'primaryGenreName','catalog_genre')
            # releaseDate belongs to the commercial edition, not the recording's original year.
            docs.append(_structured_doc(ref,'itunes',record,claims,url))
    def term(s): return '"'+re.sub(r'["\\]',' ',s)+'"'
    q='recording:'+term(identity['title'])+' AND artist:'+term(identity['artist'])
    call('musicbrainz','https://musicbrainz.org/ws/2/recording/?'+up.urlencode({'query':q,'fmt':'json','limit':20}),musicbrainz)
    # Recording-level details only. Do not copy release or artist-level personnel.
    mb_candidates=list(docs)
    for base in mb_candidates[:3]:
        cancel(); mid=base['url'].rsplit('/',1)[1]
        time.sleep(1.1)
        detail_url='https://musicbrainz.org/ws/2/recording/'+mid+'?'+up.urlencode({'inc':'artist-credits+artist-rels+genres','fmt':'json'})
        def detail(data,entry,url,base=base,mid=mid):
            if not isinstance(data,dict) or data.get('id')!=mid: raise ValueError('recording_id_mismatch')
            credits=data.get('artist-credit') or []
            names=''.join(str(c.get('name') or (c.get('artist') or {}).get('name') or '')+str(c.get('joinphrase') or '') for c in credits if isinstance(c,dict))
            if not _exact_title_artist(data.get('title',''),names,identity): raise ValueError('identity_mismatch')
            record=json.loads(base['text']); claims=list(base['facts']); relations=[]
            for rel in data.get('relations',[]) or []:
                if not isinstance(rel,dict) or rel.get('target-type')!='artist': continue
                person=(rel.get('artist') or {}).get('name'); role=str(rel.get('type') or '')
                attrs=[a for a in rel.get('attributes',[]) if isinstance(a,str)]
                if not person or role not in ('vocal','instrument'): continue
                compact={'artist':person,'type':role,'attributes':attrs}
                relations.append(compact)
                q=json.dumps(compact,ensure_ascii=False,sort_keys=True,allow_nan=False)
                if role=='instrument':
                    for attr in attrs:
                        if norm(attr) not in ('guest','additional','solo','minor','unknown'):
                            add(claims,'instrument',attr,q,'recording_credits','structured_json','relations')
                else:
                    roles={'lead vocals':'lead_vocal','background vocals':'backing_vocals','backing vocals':'backing_vocals','choir vocals':'choir'}
                    for attr in attrs:
                        if norm(attr) in roles: add(claims,roles[norm(attr)],'present',q,'recording_credits','structured_json','relations')
            record['relations']=relations
            genre_names=[g['name'] for g in data.get('genres',[]) or [] if isinstance(g,dict) and isinstance(g.get('name'),str)]
            if genre_names:
                record['genres']=genre_names
                for name in genre_names: _jclaim(claims,'genre',name,record,'genres','recording_genre')
            updated=_structured_doc(base['url'],'musicbrainz',record,claims,url)
            updated['acquisition_urls']=[base['acquisition_url'],url]
            # Replace in place and preserve earlier search metadata and claims.
            docs[docs.index(base)]=updated
            entry['accepted']=1
        call('musicbrainz_recording_detail',detail_url,detail)
    call('itunes','https://itunes.apple.com/search?'+up.urlencode({'term':identity['title']+' '+identity['artist'],'country':'FR','media':'music','entity':'song','limit':30}),itunes)
    # Optional existing API key. Its absence is an explicit availability state, never a secret in the report.
    if technical_transport is None:
        diagnostics.append({'provider':'getsongbpm','status':'not_configured','accepted':0,'rejected':[],'duration_ms':0})
    else:
        def getsong(data,entry,url):
            if not isinstance(data,dict) or not isinstance(data.get('search'),list): raise ValueError('invalid_schema')
            for row in data['search'][:12]:
                if not isinstance(row,dict): continue
                artist=(row.get('artist') or {}).get('name','') if isinstance(row.get('artist'),dict) else ''
                title=row.get('title',''); mid=row.get('id'); ref=row.get('uri','')
                if not isinstance(mid,str) or not _exact_title_artist(title,artist,identity) or family(ref)!='getsongbpm.com':
                    entry['rejected'].append({'id':str(mid)[:80],'reason':'identity_mismatch'}); continue
                album=row.get('album') if isinstance(row.get('album'),dict) else {}
                if identity['album'] and norm(album.get('title'))!=norm(identity['album']):
                    entry['rejected'].append({'id':mid,'reason':'album_not_confirmed'}); continue
                if identity['version'] and not contains(title,identity['version']):
                    entry['rejected'].append({'id':mid,'reason':'version_not_confirmed'}); continue
                record={'id':mid,'title':title,'artist':artist,'album':album.get('title')}
                claims=[]
                for field,item in [('tempo','tempo'),('time_sig','meter'),('key_of','key')]:
                    if isinstance(row.get(field),(str,int,float)) and not isinstance(row.get(field),bool):
                        record[field]=row[field]
                        _jclaim(claims,item,row[field],record,field,'track_candidate_beta' if item=='meter' else 'track_candidate')
                docs.append(_structured_doc(ref,'getsongbpm',record,claims,url))
        call('getsongbpm','https://api.getsong.co/search/?'+up.urlencode({'type':'both','lookup':'song:'+identity['title']+' artist:'+identity['artist'],'limit':12}),getsong,technical_transport)
    return docs,diagnostics

def configured_technical_transport(root):
    key=next((os.environ.get(name,'').strip() for name in ('GETSONGBPM_API_KEY','GETSONG_API_KEY') if os.environ.get(name,'').strip()),'')
    if not key:
        for path in (Path(root)/'.env.local', Path(root).parents[1]/'.env.local'):
            if not path.is_file(): continue
            for line in path.read_text(encoding='utf-8-sig',errors='strict').splitlines():
                name,sep,value=line.partition('=')
                if sep and name.strip() in ('GETSONGBPM_API_KEY','GETSONG_API_KEY'):
                    key=value.strip().strip('"').strip("'")
                    if key: break
            if key: break
    if not key: return None
    def fetch(url):
        if up.urlsplit(url).hostname!='api.getsong.co': raise ValueError('credential_destination_rejected')
        return json_transport(url,{'X-API-KEY':key})
    return fetch

def summarize(item,docs):
    groups=defaultdict(set); evidence=[]; dedupe=set()
    seen_content=set()
    for d in docs:
        if not d.get('candidate_relevant'): continue
        fingerprint=d.get('content_sha256')
        if fingerprint and fingerprint in seen_content: continue
        if fingerprint: seen_content.add(fingerprint)
        fam=family(d['url'])
        for f in d.get('facts',[]):
            if f['item']!=item or not fam or f['quote'] not in d.get('text',''): continue
            key=(d['url'],f['value'],f['quote'],f['scope'])
            if key in dedupe: continue
            dedupe.add(key); groups[fam].add(f['value'])
            evidence.append({**f,'url':d['url'],'family':fam,'domain':host_of(d['url']),'provider':d.get('provider','web'),'recording_identity_verified':False})
    values=sorted({v for vs in groups.values() for v in vs}); candidates=[]; signatures=set()
    tol=RULES['numeric'].get(item)
    for v in values:
        observed=[x for x in values if v<=x<=v+tol] if tol is not None else [v]
        # A conflicting family is not a vote for multiple candidates.
        support=sorted(h for h,vs in groups.items() if (vs.intersection(observed) if item in ('instrument','genre') else vs and vs.issubset(set(observed))))
        signature=tuple(observed)
        if signature in signatures: continue
        signatures.add(signature)
        # No averaged value masquerading as a document. Report observed values exactly.
        candidates.append({'value':v,'observed':observed,'families':support,'domains':support,'support':len(support),'agreement_pct':round(100*len(support)/len(groups),1) if groups else 0})
    candidates.sort(key=lambda c:(-c['support'],-len(c['observed']),str(c['value'])))
    leaders=[]
    if candidates:
        maximum=candidates[0]['support']
        leaders=[c for c in candidates if maximum>0 and c['support']==maximum]
        # Remove subset windows: same supporting families and no added observation.
        leaders=[c for c in leaders if not any(set(c['observed'])<set(o['observed']) and c['families']==o['families'] for o in leaders)]
    if not evidence: status='unknown'
    elif item in ('instrument','genre'): status='target_reached_uncertified' if all(c['support']>=5 for c in candidates) else 'provisional'
    elif len(leaders)!=1 or len(leaders[0]['observed'])!=len(values): status='divergent'
    elif leaders[0]['support']>=5: status='target_reached_uncertified'
    else: status='provisional'
    return {'status':status,'target_sources':5,'eligible_domains':len(groups),'consulted_domains':len({family(d['url']) for d in docs if family(d['url'])}),'candidates':candidates,'leaders':leaders,'evidence':evidence,'independent_sources_certified':0,'confidence':None,'confidence_reason':'recording_and_origin_not_certified'}

def direct_execute(name,fn,*args): return fn(*args)

def discover(identity,search,fetch,transport=json_transport,cancel=lambda:None,observe=lambda *args:None,clock=time.monotonic,execute=direct_execute,technical_transport=None):
    identity=identity_only(identity); start=clock(); docs=[]; errors=[]; queries=[]; attempts=[]; cache={}; diagnostic=[]; item_stats={}; total_fetch=0
    complete=bool(identity['title'] and identity['artist'])
    if complete:
        cancel()
        def acquire():
            documents,providers=structured(identity,transport,cancel,technical_transport)
            return {'documents':documents,'providers':providers,'status':'error' if any(x['status']=='error' for x in providers) else 'completed'}
        acquired=execute('r8_structured_sources',acquire)
        docs,diagnostic=acquired['documents'],acquired['providers']
    else: errors.append({'stage':'identity','type':'identity_incomplete'})
    prefix=' '.join('"'+v.replace('"',' ')+'"' for v in identity.values() if v)
    def search_item(item,suffix):
        nonlocal total_fetch
        t=clock(); cancel(); new_count=0; stats={'queries':[],'consulted':[],'rejected':[],'errors':[]}
        if complete and clock()-start<MAX_SECONDS:
            query=prefix+' '+suffix; queries.append({'item':item,'query':query}); stats['queries'].append(query)
            try: hits=search(query,max_results=MAX_HITS)
            except Exception as e:
                event={'item':item,'stage':'search','type':type(e).__name__,'reason':error_reason(e)}; errors.append(event); stats['errors'].append(event); hits=[]
                cancel()
                if clock()-start<MAX_SECONDS:
                    retry=identity['title']+' '+identity['artist']+' '+suffix
                    queries.append({'item':item,'query':retry}); stats['queries'].append(retry)
                    try: hits=search(retry,max_results=MAX_HITS)
                    except Exception as retry_error:
                        event={'item':item,'stage':'search_retry','type':type(retry_error).__name__,'reason':error_reason(retry_error)}; errors.append(event); stats['errors'].append(event)
                        hits=[]
            if not isinstance(hits,list):
                stats['errors'].append({'stage':'search','type':'invalid_response'}); hits=[]
            for hit in hits[:MAX_HITS]:
                cancel()
                if clock()-start>=MAX_SECONDS:
                    stats['errors'].append({'stage':'budget','type':'time_budget_exhausted'}); break
                if not isinstance(hit,dict): continue
                url=str(hit.get('url') or '')
                if not host_of(url): stats['rejected'].append({'url':url[:500],'reason':'unsafe_url'}); continue
                if url not in cache:
                    if total_fetch>=MAX_DOCS:
                        stats['errors'].append({'stage':'budget','type':'document_budget_exhausted'}); break
                    if new_count>=MAX_NEW_PER_ITEM:
                        stats['errors'].append({'stage':'budget','type':'item_document_budget_exhausted'}); break
                    new_count+=1; total_fetch+=1
                    try: raw=fetch(url)
                    except Exception as e: raw={'status':'read_error','reason':error_reason(e)}
                    cache[url]=raw
                raw=cache[url]; status=raw.get('status') if isinstance(raw,dict) else 'invalid_response'
                attempts.append({'item':item,'url':url,'status':status})
                if status!='read':
                    event={'item':item,'stage':'fetch','url':url,'type':status,'reason':raw.get('reason') if isinstance(raw,dict) else None}; errors.append(event); stats['errors'].append(event); continue
                final=str(raw.get('url') or url); text=clean_text(raw.get('text'))[:MAX_RETAINED_TEXT]
                if not host_of(final): stats['rejected'].append({'url':url,'reason':'unsafe_final_url'}); continue
                stats['consulted'].append(final)
                reason=relevant(text,identity)
                if reason:
                    stats['rejected'].append({'url':final,'reason':reason,'title_found':contains(text,identity['title']),'artist_found':contains(text,identity['artist']),'text_length':len(text),'content_sha256':hashlib.sha256(text.encode()).hexdigest()}); continue
                if any(d['url']==final for d in docs): continue
                fs=text_facts(text,final,identity)
                docs.append({'url':final,'title':str(hit.get('title') or '')[:250],'text':text,'facts':fs,'provider':'public_web','recording_identity_verified':False,'candidate_relevant':True,'content_sha256':hashlib.sha256(text.encode()).hexdigest()})
        else:
            stats['errors'].append({'stage':'budget' if complete else 'identity','type':'not_searched'})
        stats['duration_ms']=round((clock()-t)*1000,1)
        item_stats[item]=stats
        # Progress is a completed search attempt, not a qualified result.
        current=summarize(item,docs); observe(item,current['status'],stats['duration_ms'])
        return {'status':'error' if stats['errors'] else 'completed'}
    for item,suffix in QUERIES.items():
        execute('r8_item_'+item,search_item,item,suffix)
    items={}
    for item in QUERIES:
        data=summarize(item,docs); data.update(item_stats[item]); data['label']=LABELS[item]
        data['structured_providers']=[{'provider':d['provider'],'status':d['status'],'accepted':d['accepted'],'duration_ms':d['duration_ms']} for d in diagnostic if ((d['provider']=='getsongbpm' and item in ('tempo','meter','key')) or (d['provider']!='getsongbpm' and item in ('duration','year','genre','instrument','lead_vocal','backing_vocals','choir')))]
        if data['status']=='unknown' and (data['errors'] or any(x['status']=='error' for x in data['structured_providers'])): data['status']='search_incomplete'
        items[item]=data
    counts={'items_with_evidence':sum(bool(x['evidence']) for x in items.values()),'items_certified':0,'items_target_reached':sum(x['status']=='target_reached_uncertified' for x in items.values()),'items_search_incomplete':sum(x['status']=='search_incomplete' for x in items.values())}
    status='partial' if counts['items_with_evidence'] else 'no_evidence'
    return {'schema':'ezs.r9.documentary-profile.v1','status':status,'execution_status':'completed','research_status':'incomplete' if counts['items_search_incomplete'] or counts['items_with_evidence']<11 else 'observations_collected','qualification_status':'not_certified','identity':identity,'created_at':datetime.now(timezone.utc).isoformat(),'queries':queries,'errors':errors,'provider_diagnostics':diagnostic,'documents':docs,'fetch_attempts':total_fetch,'acquisition_attempts':attempts,'provisional':{'schema':'ezs.r9.provisional-source-family-agreement.v1','items':items},'counts':counts,'recording_identity_certified':False,'independence_certified':False,'official_verdicts':False}

def save_before_comparison(profile,root,run_id,execution_id):
    if type(run_id) is not int or run_id<1 or not re.fullmatch('[a-f0-9]{32}',execution_id): raise ValueError('invalid_execution')
    parent=Path(root)/'runtime'/'reports'/'documentary-r9'/str(run_id); parent.mkdir(parents=True,exist_ok=True)
    path=parent/(execution_id+'.json'); data=json.dumps(profile,ensure_ascii=False,sort_keys=True,allow_nan=False).encode('utf-8')
    with path.open('xb') as f: f.write(data); f.flush(); os.fsync(f.fileno())
    return {'path':str(path.relative_to(root)),'sha256':hashlib.sha256(data).hexdigest()}

def run(identity,root,run_id,execution_id,cancel,observe=lambda *args:None,execute=direct_execute):
    from profile_public_web import search
    try: technical=configured_technical_transport(root)
    except (OSError,UnicodeError):
        def technical(url): raise RuntimeError('technical_provider_configuration_unreadable')
    p=discover(identity,search,fetch_public,cancel=cancel,observe=observe,execute=execute,technical_transport=technical)
    cancel(); receipt=execute('r8_archive',save_before_comparison,p,root,run_id,execution_id)
    return {'profile':p,'receipt':receipt}

def draft(run_id,execution_id,result,steps):
    p=result['profile']; counts={'bon':0,'faux':0,'non_trouve':0}
    return {'run_id':run_id,'revision_key':f'profile-r9-{run_id}-{execution_id}',
            'reviewer':'assistant-documentary-r9','status':'draft','confidence':0.0,
            'identity':p['identity'],'sources':[{'url':d['url'],'title':d['title'],'provider':d['provider']} for d in p['documents']],
            'consensus':{'final_verification_summary':counts,'documentary_items':p['provisional']['items']},
            'comparison':{'documentary_profile':result,'engine_steps':list(steps),
              'final_verification':{'schema':'ezstudio.profile-final-verification.v1','status':'not_performed','mode':'documentary_only','assertions':[],'scientific_preset_mutation_allowed':False},
              'assistant_ai':{'status':'completed','provider':'documentary-r9','dimensions':{k:{'decision':'not_scored','confidence':None} for k in QUERIES}}}}
