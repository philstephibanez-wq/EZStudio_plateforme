"""One-shot LAB canonical AI worker. By default: safe read-only preflight.
--execute claims job; records explicit error unless verified external review is persisted.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
from urllib.request import Request, urlopen

from runtime.musical_contract import normalize
from runtime.open_web_sets import analyze

PATH='/internal/analysis/desktop/profile/ai-requests'

def load_token(lab_root):
    token=os.environ.get('EZSTUDIO_ANALYSIS_WORKER_TOKEN','').strip()
    if token:return token
    for line in (Path(lab_root)/'.env.local').read_text(encoding='utf-8-sig').splitlines():
        if line.startswith('EZSTUDIO_ANALYSIS_WORKER_TOKEN='):
            token=line.split('=',1)[1].strip().strip('"').strip("'")
            if token:return token
    raise RuntimeError('LAB_token_unavailable')

class LabClient:
    def __init__(self,token,opener=urlopen):
        self.token,self.opener=token,opener
    def request(self,path,data=None):
        body=json.dumps(data).encode('utf-8') if data is not None else None
        request=Request('http://127.0.0.1:8701'+path,data=body,
            headers={'X-EZScore-Analysis-Token':self.token,'Content-Type':'application/json','Accept':'application/json'},
            method='POST' if data is not None else 'GET')
        with self.opener(request,timeout=12) as response:
            return json.loads(response.read(1000000))

def finish_or_ack_cancel(client, job_id, run_id, status, message):
    """Fence terminal write by exact attempt and prefer safe cancel acknowledgement."""
    state=client.request(PATH+'/'+str(run_id)+'/state?job_id='+str(job_id))['request']
    if state.get('job_id')!=job_id or state.get('run_id')!=run_id:
        raise RuntimeError('canonical_job_identity_mismatch')
    if state.get('status') in ('cancelling','cancelled'):
        ack=client.request(PATH+'/'+str(run_id)+'/cancelled',{'job_id':job_id})
        if ack.get('ok') is not True or (ack.get('request') or {}).get('status')!='cancelled':
            raise RuntimeError('canonical_cancel_not_confirmed')
        return 'cancelled',ack
    if state.get('status')!='running':
        raise RuntimeError('canonical_job_not_running')
    response=client.request(PATH+'/'+str(run_id)+'/finish',
                            {'job_id':job_id,'status':status,'message':message})
    if response.get('ok') is not True or (response.get('request') or {}).get('status')!=status:
        raise RuntimeError('canonical_finish_not_confirmed')
    return status,response


def consume(job_id,client,execute=False,research=analyze):
    if not isinstance(job_id,int) or job_id<1:raise ValueError('invalid_job_id')
    context=client.request(PATH+'/'+str(job_id)+'/context')
    if (context.get('schema')!='ezstudio.assistant-final-job.v1'
        or context.get('job_id')!=job_id or context.get('status')!='queued'
        or not isinstance(context.get('run_id'),int) or context['run_id']<1
        or not isinstance(context.get('song_id'),int) or context['song_id']<1):
        raise RuntimeError('canonical_context_invalid')
    normalized=normalize({'recording':context['recording'],'assertions':context['assertions']})
    run_id=context['run_id']
    result={'job_id':job_id,'run_id':run_id,'song_id':context['song_id'],
            'assertion_count':len(normalized['assertions'])}
    if not execute:
        return {**result,'status':'preflight_only','claimed':False,'finished':False}
    if client.request(PATH+'/'+str(run_id)+'/claim',{}).get('claimed') is not True:
        raise RuntimeError('canonical_claim_refused')
    reason='assistant_documentary_verification_unavailable'
    try:
        discovery=research(context)
        if discovery.get('job_id')!=job_id or discovery.get('run_id')!=run_id:
            raise RuntimeError('research_identity_mismatch')
        if discovery.get('status')=='discovery_failed':
            reason='assistant_discovery_failed'
        terminal,finished=finish_or_ack_cancel(client,job_id,run_id,'error',reason)
        return {**result,'status':terminal,'claimed':True,'finished':True,
                'reason':reason,'read_count':discovery.get('read_count',0),
                'discovered_count':discovery.get('discovered_count',0),
                'review_persisted':False,'verdicts':[]}
    except Exception:
        try:
            terminal,finished=finish_or_ack_cancel(client,job_id,run_id,'error','assistant_worker_exception')
        except Exception:
            raise RuntimeError('error_finish_unconfirmed_job_may_be_running') from None
        if terminal=='cancelled':
            return {**result,'status':'cancelled','claimed':True,'finished':True,'review_persisted':False}
        raise RuntimeError('assistant_worker_exception_job_marked_error') from None

def record_intake(job_id, client):
    """Persist and reread an intake checkpoint without claiming or finishing a job."""
    context=client.request(PATH+'/'+str(job_id)+'/context')
    if (context.get('schema')!='ezstudio.assistant-final-job.v1'
        or context.get('job_id')!=job_id or context.get('status')!='queued'
        or not isinstance(context.get('run_id'),int) or context['run_id']<1
        or not isinstance(context.get('song_id'),int) or context['song_id']<1):
        raise RuntimeError('canonical_context_invalid')
    normalized=normalize({'recording':context['recording'],'assertions':context['assertions']})
    endpoint=PATH+'/'+str(job_id)+'/checkpoints'
    event={'schema':'ezstudio.assistant-checkpoint.v1','event_key':'intake-v1',
           'phase':'intake','outcome':'ok',
           'detail':{'assertion_count':len(normalized['assertions'])}}
    saved=client.request(endpoint,event)
    if saved.get('ok') is not True:
        raise RuntimeError('checkpoint_write_unconfirmed')
    event_record=saved.get('event') or {}
    history=client.request(endpoint)
    if history.get('schema')!='ezstudio.assistant-checkpoint-history.v1' or history.get('job_id')!=job_id:
        raise RuntimeError('checkpoint_history_invalid')
    matches=[item for item in history.get('events',[]) if item.get('event_key')=='intake-v1']
    if len(matches)!=1 or matches[0].get('run_id')!=context['run_id'] or matches[0].get('detail')!=event['detail'] or event_record.get('id')!=matches[0].get('id'):
        raise RuntimeError('checkpoint_readback_mismatch')
    return {'job_id':job_id,'run_id':context['run_id'],'song_id':context['song_id'],
            'assertion_count':len(normalized['assertions']),
            'status':'intake_checkpoint_persisted','event_id':matches[0]['id'],
            'claimed':False,'finished':False,'review_persisted':False}

def scan_queued(client, *, limit=80, intake=record_intake):
    """Process each queued canonical job independently; never claim or finish."""
    history=client.request(PATH+'/recent?limit='+str(limit))
    if history.get('schema')!='ezstudio.profile-ai-history.v1' or not isinstance(history.get('jobs'),list):
        raise RuntimeError('canonical_history_invalid')
    results=[]
    seen=set()
    for job in reversed(history['jobs']):
        if not isinstance(job,dict) or job.get('status')!='queued':
            continue
        job_id=job.get('job_id')
        if not isinstance(job_id,int) or job_id<1 or job_id in seen:
            continue
        seen.add(job_id)
        try:
            results.append(intake(job_id,client))
        except Exception as exc:
            # Do not log tokens, HTTP bodies or provenance.
            results.append({'job_id':job_id,'status':'intake_failed',
                            'reason':type(exc).__name__,'claimed':False,'finished':False})
    return {'schema':'ezstudio.assistant-queued-scan.v1',
            'queued_seen':len(seen),'results':results,
            'claimed':False,'finished':False}

def record_discovery(job_id, client, *, research=analyze):
    """Read-only research followed by an auditable LAB checkpoint. No claim or finish."""
    context=client.request(PATH+'/'+str(job_id)+'/context')
    if (context.get('schema')!='ezstudio.assistant-final-job.v1'
        or context.get('job_id')!=job_id or context.get('status')!='queued'):
        raise RuntimeError('canonical_context_invalid')
    normalize({'recording':context['recording'],'assertions':context['assertions']})
    try:
        discovery=research(context)
        if discovery.get('job_id')!=job_id or discovery.get('run_id')!=context['run_id']:
            raise RuntimeError('research_identity_mismatch')
        n=int(discovery.get('discovered_count',0))
        m=int(discovery.get('read_count',0))
        if not (0<=n<=100000 and 0<=m<=100000):
            raise ValueError('invalid_research_counts')
        failed=discovery.get('status')=='discovery_failed'
        reason='web_discovery_failed' if failed else 'documentary_verification_unavailable'
    except Exception:
        n=m=0
        failed=True
        reason='web_discovery_exception'
    endpoint=PATH+'/'+str(job_id)+'/checkpoints'
    detail={'discovered_count':n,'read_count':m,'reason_code':reason}
    outcome='failed' if failed else 'blocked'
    # Different outcomes are independent immutable events; identical outcomes are idempotent.
    signature=json.dumps({'outcome':outcome,'detail':detail},sort_keys=True,separators=(',',':'))
    event_key='discovery-v2-'+hashlib.sha256(signature.encode('utf-8')).hexdigest()[:16]
    payload={'schema':'ezstudio.assistant-checkpoint.v1',
             'event_key':event_key,'phase':'discovery',
             'outcome':outcome,'detail':detail}
    saved=client.request(endpoint,payload)
    events=client.request(endpoint)
    matching=[e for e in events.get('events',[]) if e.get('event_key')==event_key]
    if (saved.get('ok') is not True or len(matching)!=1
        or matching[0].get('run_id')!=context['run_id']
        or matching[0].get('detail')!=payload['detail']
        or (saved.get('event') or {}).get('id')!=matching[0].get('id')):
        raise RuntimeError('discovery_checkpoint_unconfirmed')
    return {'job_id':job_id,'run_id':context['run_id'],'status':'discovery_failed' if failed else 'verification_blocked',
            'discovered_count':n,'read_count':m,'checkpoint_id':matching[0]['id'],
            'reason':reason,'claimed':False,'finished':False,'review_persisted':False}

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--job-id',type=int)
    parser.add_argument('--record-discovery',action='store_true',help='Research and checkpoint without claim/finish')
    parser.add_argument('--scan-queued',action='store_true',help='Record intake checkpoints for queued jobs without claim/finish')
    parser.add_argument('--lab-root',default=str(Path(__file__).resolve().parents[2] / 'ezs_lab'))
    parser.add_argument('--execute',action='store_true',help='Moves canonical queued job to error on no verified review')
    parser.add_argument('--record-intake',action='store_true',help='Persist and reread intake only; no claim/finish')
    args=parser.parse_args()
    if args.scan_queued and (args.execute or args.record_intake or args.record_discovery or args.job_id is not None):
        parser.error('--scan-queued cannot be combined with other job options')
    if not args.scan_queued and args.job_id is None:
        parser.error('--job-id is required unless --scan-queued is selected')
    if sum((args.record_intake,args.record_discovery,args.execute))>1:
        parser.error('--record-intake and --execute are mutually exclusive')
    client=LabClient(load_token(args.lab_root))
    result=scan_queued(client) if args.scan_queued else (record_discovery(args.job_id,client) if args.record_discovery else (record_intake(args.job_id,client) if args.record_intake else consume(args.job_id,client,execute=args.execute)))
    print(json.dumps(result,ensure_ascii=False))
    return (0 if all(r['status']=='intake_checkpoint_persisted' for r in result['results']) else 2) if args.scan_queued else (0 if result['status'] in ('preflight_only','intake_checkpoint_persisted','discovery_failed','verification_blocked') else 2)

if __name__=='__main__':
    raise SystemExit(main())
