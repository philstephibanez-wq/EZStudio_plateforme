"""Controlled one-pass canonical queued-job runner.
Requires --execute to claim jobs; no claim in preview mode.
Failures never become completed musical reviews.
"""
import argparse
import json
from runtime.canonical_job_worker import LabClient, load_token, PATH, consume, record_discovery

def run_queue(client, *, execute=False, research=False, limit=80, worker=consume, researcher=record_discovery):
    history=client.request(PATH+'/recent?limit='+str(limit))
    if history.get('schema')!='ezstudio.profile-ai-history.v1' or not isinstance(history.get('jobs'),list):
        raise RuntimeError('canonical_history_invalid')
    pending={}
    for item in history['jobs']:
        if isinstance(item,dict) and item.get('status')=='queued':
            job_id=item.get('job_id')
            if isinstance(job_id,int) and job_id>0:
                pending[job_id]=item
    results=[]
    for job_id in sorted(pending):
        if research:
            try:
                results.append(researcher(job_id,client))
            except Exception as exc:
                results.append({'job_id':job_id,'status':'research_failed',
                                'reason':type(exc).__name__,'claimed':False,'finished':False})
            continue
        if not execute:
            results.append({'job_id':job_id,'status':'queued_preview','claimed':False})
            continue
        try:
            result=worker(job_id,client,execute=True)
            results.append(result)
        except Exception as exc:
            results.append({'job_id':job_id,'status':'worker_failed',
                            'reason':type(exc).__name__,'finish_confirmed':False})
    return {'schema':'ezstudio.assistant-queue-pass.v1','mode':'research' if research else ('execute' if execute else 'preview'),
            'queued_found':len(pending),'results':results,
            'documentary_success_count':0}

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--lab-root',default=str(Path(__file__).resolve().parents[2] / 'ezs_lab'))
    parser.add_argument('--research-queued',action='store_true',help='Research every queued job and persist bounded checkpoints; no claim/finish')
    parser.add_argument('--execute',action='store_true',
                        help='Explicitly claims queued jobs and may finish them ERROR')
    args=parser.parse_args()
    if args.execute and args.research_queued:
        parser.error('--execute and --research-queued cannot be combined')
    result=run_queue(LabClient(load_token(args.lab_root)),execute=args.execute,research=args.research_queued)
    print(json.dumps(result,ensure_ascii=False))
    return 0 if not args.execute else 2

if __name__=='__main__':
    raise SystemExit(main())
