"""E06-P3: read-only JSONL export, reusing canonical E04 assembly."""
import argparse
from contextlib import closing
import hashlib
import json
import re
import sqlite3
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IDS = ('1','1a','1b','1c','2','3','4','5','6','7','8','9')
# Exclude operational data; fail closed on suspicious content rather than export it.
SECRET = re.compile(r'(?i)(?:api[_-]?key|password|passwd|secret|authorization|credential|(?:^|_)token(?:$|_)|private[_-]?key)')
VALUE = re.compile(r'(?i)(?:\b(?:sk-[a-z0-9_-]{12,}|gh[pousr]_[a-z0-9_]{12,}|github_pat_[a-z0-9_]{12,}|bearer\s+\S+)|(?:api[_-]?key|password|secret|token)\s*[:=]\s*\S+)')
PATH = re.compile(r'(?i)(?:^[a-z]:[\\/]|^\\\\|^/(?:home|mnt|tmp|var|users)/)')

def clean(x):
    if isinstance(x, dict):
        return {k: clean(v) for k,v in x.items() if not SECRET.search(k) and not re.search(r'(?i)(?:^|_)(?:path|filename|directory|url|endpoint|host)(?:$|_)', k)}
    if isinstance(x, list): return [clean(v) for v in x]
    if isinstance(x, str) and (VALUE.search(x) or PATH.search(x)):
        raise ValueError('sensitive_content_detected')
    return x

def encode(x):
    return json.dumps(x, ensure_ascii=False, allow_nan=False, separators=(',',':'))

def records(db, song_id, assemble, root=ROOT):
    db = Path(db).resolve(strict=True)
    with closing(sqlite3.connect(db.as_uri()+'?mode=ro', uri=True)) as c:
        c.row_factory = sqlite3.Row
        c.execute('PRAGMA query_only=ON')
        c.execute('BEGIN')
        prompt = c.execute('SELECT r.id,r.content,r.sha256,r.created_at FROM profile_expertise_prompt_revision r JOIN profile_expertise_prompt_active a ON a.revision_id=r.id WHERE a.id=1').fetchone()
        if prompt is None: raise ValueError('active_prompt_missing')
        if hashlib.sha256(prompt['content'].encode('utf-8')).hexdigest()!=prompt['sha256']: raise ValueError('prompt_hash_mismatch')
        if VALUE.search(prompt['content']): raise ValueError('sensitive_prompt_content')
        items = json.loads((root/'contracts/PROFILE_ITEMS.json').read_text(encoding='utf-8'))
        # FEEDBACK envelope is provisional, not an E08 import contract.
        yield {'schema':'ezstudio.profile.expertise-export.v1','type':'header','prompt':dict(prompt),'items_contract':items,'feedback_contract':{'status':'candidate_pending_E08','item_ids':list(IDS),'verdicts':['BON','FAUX','NON TROUVÉ'],'required_per_item':['item_id','verdict','justification','sources'],'identity_fields':['audio_sha256','run_id','prompt_revision_id'],'automatic_application':False}}
        if song_id is None:
            songs = c.execute('SELECT s.id,s.title,s.artist,s.audio_sha256 FROM songs s JOIN (SELECT MAX(id) id FROM songs GROUP BY audio_sha256) g ON g.id=s.id ORDER BY s.title COLLATE NOCASE,s.artist COLLATE NOCASE,s.id').fetchall()
        else:
            songs = c.execute('SELECT id,title,artist,audio_sha256 FROM songs WHERE id=?',(song_id,)).fetchall()
            if not songs: raise ValueError('song_not_found')
        counts = {'ready':0,'missing':0,'error':0}
        for song in songs:
            identity = dict(song)
            if not re.fullmatch(r'[0-9a-fA-F]{64}',song['audio_sha256']): raise ValueError('invalid_audio_identity')
            base={'type':'profile','identity':clean(identity),'prompt_revision_id':prompt['id']}
            run=c.execute("SELECT sr.*,sr.id AS run_id,s.title,s.artist,s.audio_sha256,s.profile_documentary_json FROM scientific_runs sr JOIN songs s ON s.id=sr.song_id WHERE s.audio_sha256=? AND sr.item='profile' AND sr.state='done' ORDER BY sr.id DESC LIMIT 1",(song['audio_sha256'],)).fetchone()
            if run is None:
                counts['missing']+=1; yield dict(base,status='missing',reason='no_completed_profile'); continue
            try:
                result=assemble(run,root/'schemas')
                if result['run_id']!=run['id'] or result['song_id']!=run['song_id'] or result['audio_sha256']!=song['audio_sha256']: raise ValueError('identity_mismatch')
                if clean(result) != result or VALUE.search(encode(result)): raise ValueError('sensitive_result')
                p=c.execute('SELECT p.id,p.revision_ref,p.revision_number,p.fingerprint,p.pipeline_revision,p.content_sha256,l.captured_at FROM scientific_run_preset l JOIN preset_phase_revisions p ON p.id=l.phase_revision_id WHERE l.run_id=? AND p.phase=\'profile\'',(run['id'],)).fetchone()
                preset=None
                if p is not None:
                    preset=dict(p); preset['modules']=[]
                    for m in c.execute('SELECT l.position,m.id,m.module_key,m.revision_ref,m.revision_number,m.fingerprint,m.content_sha256,m.composition_json FROM preset_phase_revision_modules l JOIN preset_module_revisions m ON m.id=l.module_revision_id WHERE l.phase_revision_id=? ORDER BY l.position',(p['id'],)):
                        module=dict(m); module['composition']=json.loads(module.pop('composition_json')); preset['modules'].append(clean(module))
                    if not preset['modules']: raise ValueError('empty_preset')
                engine=clean({k:run[k] for k in ('engine_name','engine_version','model_name')})
                rec=dict(base,status='ready',run_id=run['id'],run_song_id=run['song_id'],finished_at=run['finished_at'],engine=engine,result=result,preset=clean(preset),preset_status='captured' if preset else 'missing')
                # Validate serialization before emitting a record.
                encode(rec)
                counts['ready']+=1; yield rec
            except (ValueError,TypeError,KeyError,sqlite3.Error) as e:
                counts['error']+=1; yield dict(base,status='error',run_id=run['id'],reason='canonical_result_or_preset_unavailable',error_type=type(e).__name__)
        yield {'type':'summary','counts':counts}
        c.rollback()

def main():
    parser=argparse.ArgumentParser(); parser.add_argument('--database',required=True,type=Path); parser.add_argument('--song',type=int); a=parser.parse_args()
    if a.song is not None and a.song<1: parser.error('invalid song')
    sys.path.insert(0,str(ROOT/'python/ezstudio/pipeline/profile'))
    from result_projection import assemble
    # Complete before sending output: no partial download on fatal errors.
    output='\n'.join(encode(r) for r in records(a.database,a.song,assemble))+'\n'
    sys.stdout.buffer.write(output.encode('utf-8'))
if __name__=='__main__': main()
