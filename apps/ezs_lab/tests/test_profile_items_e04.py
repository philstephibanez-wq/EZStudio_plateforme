"""Validate projected items against installed E02 schema, with synthetic evidence."""
import copy, importlib.util, json, pathlib, sys, unittest
from jsonschema import Draft202012Validator
ROOT=pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'python/ezstudio/pipeline/profile'))
from items_projection import load_policy, project_scientific_items
POLICY=load_policy(ROOT/'contracts')
SCHEMA=json.loads((ROOT/'schemas/profile-result.v1.schema.json').read_text(encoding='utf8'))
EXAMPLE=json.loads((ROOT/'contracts/profile-result.v1.example.json').read_text(encoding='utf8'))

class ProjectionTests(unittest.TestCase):
    def project(self,c=None,v=None,consensus=None):
        c,v,consensus=c or {},v or {},consensus or {}
        old=copy.deepcopy((c,v,consensus))
        output=project_scientific_items(c,v,consensus,POLICY)
        self.assertEqual(old,(c,v,consensus)); self.assertFalse(output['usable_for_downstream'])
        full=copy.deepcopy(EXAMPLE); full['items'].update(output['items'])
        Draft202012Validator(SCHEMA).validate(full)
        return output
    def test_policy_provenance(self):
        x=self.project()["policy_provenance"]; self.assertRegex(x["items_sha256"],r"^[0-9a-f]{64}$"); self.assertRegex(x["policy_sha256"],r"^[0-9a-f]{64}$")
    def test_empty(self):
        self.assertTrue(all(x['state']=='not_analyzed' for x in self.project()['items'].values()))
    def test_duration(self): self.assertEqual(self.project({'duration_seconds':243.63})['items']['2']['value'],243.63)
    def test_nonfinite(self): self.assertIsNone(self.project({'duration_seconds':float('inf')})['items']['2']['value'])
    def test_meter_suggested(self): self.assertIsNone(self.project({'time_signature':{'label':None,'suggested_label':'6/8','confidence':0.2}})['items']['3']['value'])
    def test_meter_accepted(self):
        x=self.project({'time_signature':{'label':'6/8','confidence':0.68}})['items']['3'];self.assertEqual(x['value'],{'numerator':6,'denominator':8});self.assertEqual(x['confidence'],{'kind':'uncalibrated','value':None})
    def test_tempo(self):
        metric={'decision':'accepted','primary':{'bpm':78.3,'beat_unit':'dotted_quarter'},'calibrated':False}
        x=self.project({'tempo':{'bpm':117.45,'confidence':None,'metric_level':metric}},{'tempo_metric':metric})['items']['4']; self.assertEqual(x['value'],{'tracker_bpm':117.45,'primary_bpm':78.3,'beat_unit':'dotted_quarter'});self.assertIsNone(x['confidence']['value'])
    def test_tempo_missing_unit(self):
        x=self.project({'tempo':{'bpm':117.45,'metric_level':{'decision':'accepted','primary':{'bpm':78.3}}}})['items']['4']['value']; self.assertIsNone(x['primary_bpm']);self.assertEqual(x['tracker_bpm'],117.45)
    def test_tempo_conflict(self):
        x=self.project({'tempo':{'bpm':100,'metric_level':{'decision':'accepted','primary':{'bpm':50,'beat_unit':'half'}}}},{'tempo_metric':{'decision':'ambiguous'}})['items']['4']['value'];self.assertIsNone(x['primary_bpm'])
    def test_tonal(self):
        x=self.project({'key':{'tonic':'C','mode':'minor','confidence':None,'calibrated':False}})['items']['5'];self.assertEqual(x['value'],{'tonic':'C','mode':'minor'});self.assertIsNone(x['confidence']['value'])
    def test_genre(self):
        row={'genre_family':'folk','genre':'chanson','subgenre':None}; x=self.project(v={'genre_hierarchy':{'candidates':[row,row]}})['items']['6'];self.assertEqual(len(x['value']),1)
    def test_lead_generic(self):
        x=self.project(v={'vocal_profile':{'dimensions':{'lead_vocal':{'decision':'present','evidence':[{'label':'singing','role':'direct'}]}}}})['items']['7']; self.assertIsNone(x['value'])
    def test_lead_explicit(self):
        x=self.project(v={'vocal_profile':{'dimensions':{'lead_vocal':{'decision':'present','evidence':[{'label':'male lead vocal','role':'direct'},{'label':'female lead vocal','role':'direct'}]}}}})['items']['7'];self.assertEqual(len(x['value']['entries']),1); self.assertEqual(x['value']['entries'][0]['presence'],'possible')
    def test_other_voice(self):
        x=self.project(v={'vocal_profile':{'dimensions':{'choir':{'decision':'present'},'vocal_harmonies':{'decision':'possible'}}}})['items']['8']; self.assertEqual({r['presence'] for r in x['value']['entries']},{'possible'});self.assertEqual(x['value']['coverage'],'partial')
    def test_speech_not_narration(self):
        x=self.project(consensus={'semantic':{'voice':{'per_engine':{'semantic.clap-open-vocabulary':[{'label':'spoken voice'}]}},'audioset':{'per_engine':{'semantic.panns-cnn14':[{'label':'Speech'}]}}}});self.assertEqual(len(x['speech_evidence']),2);self.assertIsNone(x['items']['8']['value']);self.assertEqual(x['speech_role'],'undetermined')
    def test_instruments(self):
        x=self.project(v={'instrumentation':{'candidates':[{'label':'piano'},{'label':'keyboard'},{'label':'piano'}],'weak':[{'label':'electric piano'},{'label':'percussion'},{'label':'guitar'},{'label':'electric guitar'}]}})['items']['9']['value']['entries'];self.assertEqual({r['id'] for r in x},{'piano','electric piano','percussion','electric guitar'});self.assertTrue(all(r['presence']=='possible' for r in x))
    def test_empty_coverage(self): self.assertEqual(self.project(v={'instrumentation':{'candidates':[]}})['items']['9']['state'],'inconclusive')
    def test_runtime_hook_installed(self):
        # On MAIN, inspect the actual runner integration, not a separate demo.
        runner=ROOT/'python/ezstudio/pipeline/profile/runner.py'
        if runner.exists():
            text=runner.read_text(encoding='utf8'); self.assertEqual(text.count('profile_view["scientific_items"] = project_scientific_items('),1); compile(text,str(runner),'exec')
        else: self.skipTest('runner absent in isolated payload fixture')
if __name__=='__main__':unittest.main()
