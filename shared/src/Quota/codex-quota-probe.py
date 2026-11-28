#!/usr/bin/env python3
"""Read-only Codex quota probe; no inference, no project writes."""
import argparse, json, os, queue, shutil, subprocess, threading, time, sys

def summarize(result):
    out={}
    for key in ('rateLimits','rateLimitsByLimitId'):
        obj=result.get(key)
        if isinstance(obj,dict):
            entries={'default':obj} if key=='rateLimits' else obj
            for name,v in entries.items():
                if not isinstance(v,dict): continue
                item={k:v[k] for k in ('limitId','limitName','planType') if isinstance(v.get(k),str)}
                for slot in ('primary','secondary'):
                    w=v.get(slot)
                    if isinstance(w,dict):
                        p=w.get('usedPercent'); d=w.get('windowDurationMins'); r=w.get('resetsAt')
                        if type(p) in (int,float) and 0<=p<=100:
                            item[slot]={'used_percent':p,'remaining_percent':100-p,'window_minutes':d if type(d) is int else None,'reset_epoch':r if type(r) is int else None}
                out[name]=item
    return out

def probe(cmd):
    proc=subprocess.Popen(cmd,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,text=True,encoding='utf-8',errors='replace',cwd=os.path.expanduser('~'))
    q=queue.Queue()
    def reader():
        for line in proc.stdout:
            try: q.put(json.loads(line))
            except ValueError: pass
        q.put(None)
    threading.Thread(target=reader,daemon=True).start()
    def send(data):
        proc.stdin.write(json.dumps(data)+'\n');proc.stdin.flush()
    def call(i,method,params=None):
        msg={'id':i,'method':method}
        if params is not None:msg['params']=params
        send(msg); end=time.monotonic()+6
        while time.monotonic()<end:
            try: v=q.get(timeout=max(0.01,end-time.monotonic()))
            except queue.Empty: break
            if v is None: raise RuntimeError('APP_SERVER_EXITED')
            if isinstance(v,dict) and v.get('id')==i:
                if 'error' in v: raise RuntimeError(method+' RPC_ERROR '+str(v['error'].get('code','unknown')))
                return v.get('result',{})
        raise RuntimeError(method+' TIMEOUT')
    try:
        call(1,'initialize',{'clientInfo':{'name':'ezstudio_quota_probe','title':'EZStudio Quota Probe','version':'1.0.0'}})
        send({'method':'initialized'})
        a=call(2,'account/read',{'refreshToken':False})
        account=a.get('account') or {}
        
        if account.get('type')!='chatgpt': raise RuntimeError('CHATGPT_LOGIN_REQUIRED')
        data=summarize(call(3,'account/rateLimits/read'))
        print(json.dumps({'account_type':account.get('type'),'quota_windows':data},ensure_ascii=False))
        if not any('primary' in x or 'secondary' in x for x in data.values()):raise RuntimeError('NO_QUOTA_WINDOWS')

    finally:
        proc.terminate()
        try:proc.wait(timeout=5)
        except subprocess.TimeoutExpired:proc.kill();proc.wait()
        proc.stdin.close();proc.stdout.close()

def main():
    p=argparse.ArgumentParser();p.add_argument('--self-test',action='store_true');p.add_argument('--codex');a=p.parse_args()
    if a.self_test:
        x=summarize({'rateLimits':{'primary':{'usedPercent':39,'windowDurationMins':300,'resetsAt':123},'secondary':{'usedPercent':44,'windowDurationMins':10080}}})
        assert x['default']['primary']['remaining_percent']==61
        assert x['default']['secondary']['remaining_percent']==56
        assert summarize({'rateLimits':{'primary':{'usedPercent':101}}})=={'default':{}}
        # Exercise protocol against a local fake app server, never an inference endpoint.
        fake='''import sys,json\nfor line in sys.stdin:\n m=json.loads(line); k=m.get("method"); i=m.get("id")\n if i is None: continue\n r={"initialize":{},"account/read":{"account":{"type":"chatgpt","planType":"plus"}},"account/rateLimits/read":{"rateLimits":{"primary":{"usedPercent":39,"windowDurationMins":300}}}}[k]\n print(json.dumps({"id":i,"result":r}),flush=True)'''
        probe([sys.executable,'-u','-c',fake]);print('SELF_TEST_OK (normalisation et protocole simulé)');return
    exe=a.codex or shutil.which('codex.cmd') or shutil.which('codex.exe') or shutil.which('codex')
    if not exe:raise RuntimeError('CODEX_NOT_FOUND')
    if os.name=='nt' and exe.lower().endswith('.cmd'):
        cmd=[os.environ.get('COMSPEC','cmd.exe'),'/d','/c',exe,'app-server','--listen','stdio://']
    else:cmd=[exe,'app-server','--listen','stdio://']
    probe(cmd)
if __name__=='__main__':
    try:main()
    except Exception as e:print('PROBE_FAILED:',str(e),file=sys.stderr);sys.exit(1)
