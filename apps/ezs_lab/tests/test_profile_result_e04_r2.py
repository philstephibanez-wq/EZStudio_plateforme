import copy, json, pathlib, sqlite3, sys, tempfile, unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'python/ezstudio/pipeline/profile'))
from items_projection import load_policy, project_scientific_items
from result_projection import assemble, read_result, strict_json
POLICY=load_policy(ROOT/'contracts')
SCHEMAS=ROOT/'schemas'

class ResultTests(unittest.TestCase):
    def row(self):
        return dict(run_id=291,song_id=1,item='profile',state='done',title='La Bohème',artist='Charles Aznavour',audio_sha256='a'*64,profile_documentary_json=None,diagnostics_json='{}')
    def projection(self, evidence):
        return project_scientific_items({'duration_seconds':243.0},{'vocal_profile':{'dimensions':{'backing_vocals':{'decision':'present','evidence':evidence}}}}, {}, POLICY)
    def test_background_only(self):
        self.assertIsNone(self.projection([{'role':'direct','label':'background music'}])['items']['8']['value'])
    def test_no_evidence(self):
        self.assertIsNone(self.projection([])['items']['8']['value'])
    def test_explicit_backing(self):
        p=self.projection([{'role':'direct','label':'background music'},{'role':'direct','label':'backing vocals'}]); self.assertEqual(p['items']['8']['value']['entries'][0]['presence'],'possible')
    def test_legacy(self):
        r=assemble(self.row(),SCHEMAS);self.assertEqual(len(r['items']),12);self.assertEqual(r['items']['1a']['state'],'unknown');self.assertEqual(r['items']['2']['state'],'not_analyzed')
    def test_scientific(self):
        row=self.row();p=self.projection([]);row['diagnostics_json']=json.dumps({'profile_view':{'scientific_items':p}});r=assemble(row,SCHEMAS);self.assertEqual(r['items']['2']['value'],243);self.assertEqual(r['items']['2'],p['items']['2'])
    def test_documentary(self):
        row=self.row();d=json.loads((ROOT/'contracts/profile-documentary.v1.example.json').read_text(encoding='utf8'));d['lyricists']={'state':'known','value':['Auteur'], 'provenance':[{'kind':'human','ref':'review:1'}]};d['recording_version']={'state':'known','value':'studio','provenance':[{'kind':'documentary','ref':'source:1'}]};row['profile_documentary_json']=json.dumps(d);r=assemble(row,SCHEMAS);self.assertEqual(r['items']['1a']['value'],['Auteur']);self.assertEqual(r['items']['1']['value']['recording_version'],'studio');self.assertEqual(len(r['items']['1']['provenance']),2)
    def test_no_artist_split(self):
        row=self.row();row['artist']='A & B';self.assertEqual(assemble(row,SCHEMAS)['items']['1']['value']['artists'],['A & B'])
    def test_invalid_projection(self):
        row=self.row();row['diagnostics_json']=json.dumps({'profile_view':{'scientific_items':{'schema':'ezstudio.profile.scientific-items.v1','items':{}}}})
        with self.assertRaises(ValueError):assemble(row,SCHEMAS)
    def test_invalid_document(self):
        row=self.row();row['profile_documentary_json']='{}'
        with self.assertRaises(Exception):assemble(row,SCHEMAS)
    def test_incomplete(self):
        row=self.row();row['state']='running'
        with self.assertRaises(ValueError):assemble(row,SCHEMAS)
    def test_strict_json(self):
        for raw in ('{"a":1,"a":2}','{"a":NaN}','{"a":1e999}'):
            with self.assertRaises(ValueError):strict_json(raw)
    def test_db_read_only(self):
        with tempfile.TemporaryDirectory() as d:
            path=pathlib.Path(d)/'lab.sqlite';c=sqlite3.connect(path)
            try:
                c.executescript('CREATE TABLE songs(id INTEGER PRIMARY KEY,title TEXT,artist TEXT,audio_sha256 TEXT,profile_documentary_json TEXT); CREATE TABLE scientific_runs(id INTEGER PRIMARY KEY,song_id INTEGER,item TEXT,state TEXT,diagnostics_json TEXT);')
                row=self.row();c.execute('INSERT INTO songs VALUES(?,?,?,?,?)',(1,row['title'],row['artist'],row['audio_sha256'],None));c.execute('INSERT INTO scientific_runs VALUES(?,?,?,?,?)',(291,1,'profile','done','{}'));c.commit()
            finally:c.close()
            before=path.read_bytes();self.assertEqual(read_result(path,291,SCHEMAS)['run_id'],291);self.assertEqual(path.read_bytes(),before)
            with self.assertRaises(ValueError):read_result(path,292,SCHEMAS)
            self.assertEqual(path.read_bytes(),before)
    def test_duplicate_parts(self):
        row=self.row();p=self.projection([{'role':'direct','label':'backing vocals'}]);v=p['items']['8']['value']['entries'];other=copy.deepcopy(v[0]);other['presence']='inconclusive';v.append(other);row['diagnostics_json']=json.dumps({'profile_view':{'scientific_items':p}})
        with self.assertRaisesRegex(ValueError,'duplicate_part_id'):assemble(row,SCHEMAS)
if __name__=='__main__':unittest.main()
