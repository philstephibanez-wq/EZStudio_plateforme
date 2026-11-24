#!/usr/bin/env python3
"""R1I: audited, identity-fenced reconciliation of historical Assistant PROFILE receipts.
No global writes, no re-dispatch, no retry, no modifications to scientific data.
"""
import argparse
from contextlib import closing
import hashlib
import json
import os
import sqlite3
import sys
from datetime import datetime, timezone
from pathlib import Path

KIND = 'assistant.profile.review'
JOB = 'profile.review'


def ro(path):
    return sqlite3.connect('file:' + path.resolve().as_posix() + '?mode=ro', uri=True, timeout=5)


def require_schema(db, table, fields):
    cols = {r[1] for r in db.execute('PRAGMA table_info('+table+')')}
    if not set(fields).issubset(cols):
        raise ValueError(f'SCHEMA_MISMATCH_{table}')


def decode(raw, label):
    try:
        obj = json.loads(raw)
    except (ValueError, TypeError) as e:
        raise ValueError(f'INVALID_JSON_{label}') from e
    if not isinstance(obj, dict):
        raise ValueError(f'INVALID_OBJECT_{label}')
    return obj


def identity(root):
    a = root/'data/databases/EZS_Lab.sqlite'
    b = root/'data/databases/EZS_Orchestrator.sqlite'
    if not a.is_file() or not b.is_file():
        raise ValueError('DATABASE_MISSING')
    return a, b


def snapshot(root):
    a,b = identity(root)
    with closing(ro(a)) as lab, closing(ro(b)) as glob:
        require_schema(lab, 'analysis_jobs', ['id','scientific_run_id','kind','status','dispatch_owner','request_json'])
        require_schema(glob, 'job_queue', ['id','source','status','has_error','payload'])
        active_count = glob.execute("SELECT count(*) FROM job_queue WHERE status IN ('progressing','cancelling')").fetchone()[0]
        globals_by_local = {}
        all_candidates = []
        for gid,source,status,error,payload in glob.execute('SELECT id,source,status,has_error,payload FROM job_queue ORDER BY id'):
            if source != 'assistant': continue
            try: obj = decode(payload, 'GLOBAL_'+str(gid))
            except ValueError: continue # unrelated non-JSON legacy entries, never modify
            if obj.get('job') != JOB: continue
            lid = obj.get('job_id'); rid = obj.get('run_id')
            if type(lid) is not int or lid <= 0 or type(rid) is not int or rid <= 0:
                all_candidates.append(dict(gid=gid,lid=0,rid=0,status=status,classification='INVALID_GLOBAL_IDENTITY'))
                continue
            globals_by_local.setdefault(lid,[]).append(gid)
            all_candidates.append(dict(gid=gid,lid=lid,rid=rid,status=status,has_error=int(error),classification='UNVERIFIED'))
        local_rows={}
        local_receipts={}
        for lid,rid,kind,state,owner,raw in lab.execute("SELECT id,scientific_run_id,kind,status,dispatch_owner,request_json FROM analysis_jobs WHERE kind=?",(KIND,)):
            local_rows[lid]=(rid,kind,state,owner,raw)
            try:
                req=decode(raw, 'LAB_'+str(lid))
                rec=req.get('orchestrator_queue_id')
                if type(rec) is int and rec > 0:
                    local_receipts.setdefault(rec,[]).append(lid)
            except ValueError: pass
        for item in all_candidates:
            if item['classification']!='UNVERIFIED': continue
            gid,lid,rid=item['gid'],item['lid'],item['rid']
            if len(globals_by_local[lid])!=1:
                item['classification']='DUPLICATE_GLOBAL_IDENTITY';continue
            row=local_rows.get(lid)
            if not row:
                item['classification']='LAB_JOB_MISSING';continue
            r,kind,state,owner,raw=row
            if r!=rid or owner!='lab' or kind!=KIND:
                item['classification']='LAB_IDENTITY_CONFLICT';continue
            try: req=decode(raw,'LAB_'+str(lid))
            except ValueError:
                item['classification']='LAB_REQUEST_INVALID';continue
            if (req.get('schema')!='ezs.assistant.profile.job.v1' or req.get('scientific_run_id')!=rid):
                item['classification']='LAB_REQUEST_IDENTITY_CONFLICT';continue
            rec=req.get('orchestrator_queue_id')
            if rec is not None and (type(rec) is not int or rec!=gid):
                item['classification']='RECEIPT_CONFLICT';continue
            if rec==gid:
                if len(local_receipts.get(gid,[]))!=1:
                    item['classification']='DUPLICATE_LOCAL_RECEIPT'
                else:
                    item['classification']='LINK_OK'
                continue
            if local_receipts.get(gid):
                item['classification']='RECEIPT_OWNED_BY_OTHER_LOCAL';continue
            if state=='queued' and (item['status']=='waiting' or (item['status']=='ended' and item['has_error']==1) or item['status']=='progressing'):
                item['classification']='REPAIRABLE_MISSING_RECEIPT'
            else:
                item['classification']='UNSAFE_STATUS_'+state+'_'+str(item['status'])
        return all_candidates,active_count


def blocking_id(root):
    marker=root/'var/orchestrator_queue_paused_on_error.json'
    if not marker.is_file():return 0
    obj=decode(marker.read_text(encoding='utf-8-sig'),'PAUSE')
    if obj.get('schema')!='ezs.queue.pause_on_ko.v1':raise ValueError('PAUSE_SCHEMA_INVALID')
    return int(obj.get('global_job_id') or 0)

def scoped(items,root):
    blocker=blocking_id(root)
    return [i for i in items if i['status']=='waiting' or (i['gid']==blocker and i['status']=='ended' and i.get('has_error')==1)]

def print_report(items,active):
    counts={}
    for i in items: counts[i['classification']]=counts.get(i['classification'],0)+1
    print('GLOBAL_ASSISTANT_PROFILE_JOBS',len(items),'GLOBAL_ACTIVE',active)
    for key in sorted(counts):print('CLASS',key,counts[key])
    for i in items:
        if i['classification']!='LINK_OK':
            print('JOB',i['gid'],'LAB',i['lid'],'RUN',i['rid'],'STATE',i['status'],'RESULT',i['classification'])
    return counts


def repair(root, selected, backup_dir, ensure=False):
    if not selected:
        print('NO_REPAIRS_REQUIRED');return
    lab_path,glob_path=identity(root)
    if backup_dir is None:raise ValueError('BACKUP_DIR_REQUIRED')
    backup_dir.mkdir(parents=True,exist_ok=True)
    stamp=datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup=backup_dir/f'EZS_Lab_R1I_before_{stamp}.sqlite'
    if backup.exists():raise ValueError('BACKUP_COLLISION')
    with closing(ro(lab_path)) as src, closing(sqlite3.connect(backup)) as dst:
        src.backup(dst)
    print('BACKUP_OK',backup)
    db=sqlite3.connect(str(lab_path),isolation_level=None,timeout=15)
    try:
        db.execute('PRAGMA busy_timeout=15000')
        db.execute('BEGIN IMMEDIATE')
        current,active=snapshot(root)
        idx={i['gid']:i for i in current}
        if not ensure and active:
            raise ValueError('GLOBAL_ACTIVE_DURING_BULK_APPLY')
        for old in selected:
            now=idx.get(old['gid'])
            if now!=old or now['classification']!='REPAIRABLE_MISSING_RECEIPT':
                raise ValueError('IDENTITY_CHANGED_OR_NO_LONGER_REPAIRABLE_'+str(old['gid']))
            lid=old['lid'];rid=old['rid'];gid=old['gid']
            row=db.execute('SELECT request_json FROM analysis_jobs WHERE id=? AND scientific_run_id=? AND kind=? AND status=? AND dispatch_owner=?',(lid,rid,KIND,'queued','lab')).fetchone()
            if not row:raise ValueError('LAB_RECEIPT_STATE_CHANGED_'+str(lid))
            request=decode(row[0],'LAB_'+str(lid))
            if request.get('orchestrator_queue_id') is not None:raise ValueError('RECEIPT_PRESENT_'+str(lid))
            request['orchestrator_queue_id']=gid
            new=json.dumps(request,ensure_ascii=False,separators=(',',':'))
            q=db.execute("UPDATE analysis_jobs SET request_json=?,updated_at=? WHERE id=? AND scientific_run_id=? AND kind=? AND status='queued' AND dispatch_owner='lab' AND request_json=?",(new,datetime.now(timezone.utc).isoformat(timespec='seconds'),lid,rid,KIND,row[0]))
            if q.rowcount!=1:raise ValueError('RECEIPT_UPDATE_LOST_'+str(lid))
        # Revalidate global identity and status while LAB write lock remains held.
        _,gpath=identity(root)
        with closing(ro(gpath)) as glob:
            for i in selected:
                r=glob.execute('SELECT source,status,has_error,payload FROM job_queue WHERE id=?',(i['gid'],)).fetchone()
                if not r:raise ValueError('GLOBAL_REMOVED_DURING_REPAIR')
                payload=decode(r[3], 'GLOBAL_FINAL')
                if r[0]!='assistant' or payload.get('job')!=JOB or payload.get('job_id')!=i['lid'] or payload.get('run_id')!=i['rid'] or r[1]!=i['status']:
                    raise ValueError('GLOBAL_CHANGED_DURING_REPAIR')
        db.execute('COMMIT')
    except BaseException:
        if db.in_transaction:db.execute('ROLLBACK')
        raise
    finally:db.close()
    print('REPAIRED',len(selected),'LAB_ONLY_NO_GLOBAL_WRITE_NO_JOB_CREATION')


def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--root',required=True)
    ap.add_argument('--backup-dir')
    m=ap.add_mutually_exclusive_group(required=True)
    m.add_argument('--check',action='store_true')
    m.add_argument('--apply',action='store_true')
    m.add_argument('--ensure',type=int,metavar='GLOBAL_JOB_ID')
    args=ap.parse_args()
    root=Path(args.root)
    items,active=snapshot(root)
    counts=print_report(items,active)
    if args.check:
        critical=scoped(items,root)
        bad=[i for i in critical if i['classification'] not in ('LINK_OK','REPAIRABLE_MISSING_RECEIPT')]
        if bad:raise ValueError('UNSAFE_PENDING_GLOBAL_JOBS_'+','.join(str(i['gid']) for i in bad))
        print('CHECK_OK_NO_CHANGES REPAIRABLE='+str(counts.get('REPAIRABLE_MISSING_RECEIPT',0)))
        return
    if args.apply:
        if active:raise ValueError('GLOBAL_ACTIVE_BULK_APPLY_FORBIDDEN')
        crit=scoped(items,root)
        bad=[i for i in crit if i['classification'] not in ('LINK_OK','REPAIRABLE_MISSING_RECEIPT')]
        if bad:raise ValueError('AMBIGUOUS_QUEUE_FAIL_CLOSED_'+','.join(str(i['gid']) for i in bad))
        selected=[i for i in crit if i['classification']=='REPAIRABLE_MISSING_RECEIPT']
        repair(root,selected,Path(args.backup_dir) if args.backup_dir else None)
        refreshed,_=snapshot(root)
        critical=scoped(refreshed,root)
        if any(i['classification']!='LINK_OK' for i in critical):raise ValueError('POSTCHECK_FAILED')
        print('APPLY_OK_NO_GLOBAL_WRITE_NO_COMMIT_NO_PUSH_NO_PROCESS_CHANGE')
        return
    match=[i for i in items if i['gid']==args.ensure]
    if len(match)!=1:raise ValueError('GLOBAL_TARGET_NOT_FOUND')
    target=match[0]
    if target['status']!='progressing' or target.get('has_error',0)!=0:
        raise ValueError('GLOBAL_GO_NOT_ACTIVE')
    if target['classification']=='LINK_OK':
        print('RECEIPT_OK',args.ensure);return
    if target['classification']!='REPAIRABLE_MISSING_RECEIPT':
        raise ValueError('RECEIPT_UNSAFE_'+target['classification'])
    if active!=1:raise ValueError('GLOBAL_SINGLE_ACTIVE_SLOT_REQUIRED')
    repair(root,[target],Path(args.backup_dir) if args.backup_dir else None,ensure=True)
    print('RECEIPT_OK_RECONCILED',args.ensure)

if __name__=='__main__':
    try:main()
    except (ValueError,sqlite3.Error,OSError) as exc:
        print('FAIL_CLOSED',str(exc),file=sys.stderr)
        sys.exit(1)
