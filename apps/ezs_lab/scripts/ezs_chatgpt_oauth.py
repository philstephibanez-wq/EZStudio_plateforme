"""Autorisation OAuth locale ChatGPT."""
import argparse, base64, hashlib, hmac, http.server, json, os, secrets, sys, time, urllib.parse, urllib.request, uuid, webbrowser
from pathlib import Path
AUTH='https://auth.openai.com'
API='https://api.openai.com/v1'
SCOPE='openid profile email offline_access resource.invoke chatgpt.tokens.use.direct'
EXPECTED={'probe_id':'EZS-OAUTH-R1','count':1,'items':[{'song_id':1,'item':'tempo','value':120,'verdict':'BON'},{'song_id':1,'item':'meter','value':'4/4','verdict':'BON'}]}
class Failure(Exception): pass
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args,**kwargs): return None
OPENER=urllib.request.build_opener(NoRedirect())
def request(url, data=None, token=None, form=False):
    headers={'Accept':'application/json, text/event-stream'}
    if token: headers['Authorization']='Bearer '+token
    if data is not None:
        headers['Content-Type']='application/x-www-form-urlencoded' if form else 'application/json'
        data=(urllib.parse.urlencode(data) if form else json.dumps(data)).encode('utf-8')
    return OPENER.open(urllib.request.Request(url,data=data,headers=headers),timeout=120)
def get_json(*args,**kwargs):
    with request(*args,**kwargs) as r: return json.load(r)
def challenge(verifier): return base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip('=')
def callback(query,state,client):
    q=urllib.parse.parse_qs(query,strict_parsing=True)
    if any(len(v)!=1 for v in q.values()): raise Failure('CALLBACK_DUPLICATE')
    if not hmac.compare_digest(q.get('state',[''])[0],state): raise Failure('STATE_INVALID')
    if 'error' in q: raise Failure('OAUTH_DENIED')
    issued=q.get('client_id',[client])[0]
    if not issued.startswith('oaiapp_') or (client!='dynamic_agent_client' and issued!=client): raise Failure('CLIENT_INVALID')
    if not q.get('code',[''])[0]: raise Failure('CODE_MISSING')
    return q['code'][0],issued

def authorize(reg, *, retain_tokens=False):
    state,nonce,verifier=(secrets.token_urlsafe(48) for _ in range(3))
    client=reg.get('client_id','dynamic_agent_client'); result=[]
    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self,*a): pass
        def do_GET(self):
            p=urllib.parse.urlsplit(self.path)
            if p.path!='/auth/callback': self.send_error(404); return
            try: result.append(callback(p.query,state,client)); message=b'Callback received. Return to terminal.'
            except Exception: result.append(None); message=b'Callback rejected. Return to terminal.'
            self.send_response(200); self.send_header('Content-Type','text/plain'); self.send_header('Cache-Control','no-store'); self.send_header('Referrer-Policy','no-referrer'); self.end_headers(); self.wfile.write(message)
    with http.server.HTTPServer(('127.0.0.1',0),Handler) as server:
        redirect=f'http://127.0.0.1:{server.server_port}/auth/callback'
        params=dict(client_id=client,ext_agent_host_id=reg['host'],response_type='code',redirect_uri=redirect,scope=SCOPE,resource=API,state=state,nonce=nonce,code_challenge_method='S256',code_challenge=challenge(verifier))
        if client=='dynamic_agent_client': params['agent_name_hint']='EZStudio OAuth POC'
        if not webbrowser.open(AUTH+'/api/accounts/authorize?'+urllib.parse.urlencode(params)): raise Failure('BROWSER_FAILED')
        server.timeout=1; deadline=time.monotonic()+300
        while not result and time.monotonic()<deadline: server.handle_request()
    if not result or result[0] is None: raise Failure('CALLBACK_FAILED_OR_TIMEOUT')
    code,issued=result[0]
    tokens=get_json(AUTH+'/api/accounts/oauth/token',dict(grant_type='authorization_code',client_id=issued,code=code,code_verifier=verifier,redirect_uri=redirect,resource=API),form=True)
    import jwt
    jwks=get_json(AUTH+'/.well-known/jwks.json')
    header=jwt.get_unverified_header(tokens['id_token']); alg=header.get('alg')
    if alg not in ('RS256','ES256'): raise Failure('JWT_ALGORITHM_UNSUPPORTED')
    keys=[k for k in jwks['keys'] if k.get('kid')==header.get('kid') and k.get('kid') and k.get('use','sig')=='sig' and k.get('alg',alg)==alg]
    if len(keys)!=1: raise Failure('JWKS_KEY_INVALID')
    claims=jwt.decode(tokens['id_token'],jwt.PyJWK.from_dict(keys[0],algorithm=alg).key,algorithms=[alg],audience=issued,issuer=AUTH,options={'require':['exp','iat','sub','nonce']},leeway=5)
    if claims['nonce']!=nonce or not claims['sub']: raise Failure('IDENTITY_INVALID')
    subject=hashlib.sha256(claims['sub'].encode()).hexdigest()
    if reg.get('subject_hash',subject)!=subject: raise Failure('ACCOUNT_MISMATCH')
    if not {'chatgpt.tokens.use.direct','resource.invoke'}<=set(tokens.get('scope','').split()): raise Failure('PLAN_SCOPE_MISSING')
    if tokens.get('token_type','').lower()!='bearer' or not tokens.get('access_token'): raise Failure('TOKEN_INVALID')
    return (tokens if retain_tokens else tokens['access_token']), dict(host=reg['host'],client_id=issued,subject_hash=subject)
def consume(lines):
    chunks=[]; complete=False; pending=[]
    for raw in lines:
        line=raw.decode('utf-8').rstrip('\r\n')
        if line.startswith('data:'): pending.append(line[5:].lstrip()); continue
        if line or not pending: continue
        payload='\n'.join(pending); pending=[]
        if payload=='[DONE]': continue
        e=json.loads(payload); kind=e.get('type')
        if kind in ('error','response.failed','response.incomplete'): raise Failure('RESPONSE_FAILED_OR_INCOMPLETE')
        if kind=='response.output_text.delta': chunks.append(e['delta'])
        if kind=='response.completed':
            if e.get('response',{}).get('status')!='completed': raise Failure('STATUS_INVALID')
            complete=True; break
    if not complete: raise Failure('STREAM_NOT_COMPLETED')
    return ''.join(chunks)
def strict_json(text):
    def unique(pairs):
        d={}
        for k,v in pairs:
            if k in d: raise Failure('JSON_DUPLICATE_KEY')
            d[k]=v
        return d
    def reject(x): raise Failure('JSON_CONSTANT_INVALID')
    return json.loads(text,object_pairs_hook=unique,parse_constant=reject)
def check_result(text):
    actual=strict_json(text)
    if json.dumps(actual,sort_keys=True)!=json.dumps(EXPECTED,sort_keys=True): raise Failure('JSON_CONTRACT_MISMATCH')
def save(path,reg):
    tmp=path.with_suffix('.tmp'); tmp.write_text(json.dumps(reg),encoding='utf-8'); os.replace(tmp,path)
