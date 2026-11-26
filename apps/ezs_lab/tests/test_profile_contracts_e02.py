import copy, importlib.util, pathlib, unittest
from jsonschema import ValidationError
P=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('validator',P/'scripts/validate-profile-contracts.py');v=importlib.util.module_from_spec(spec);spec.loader.exec_module(v)
class ContractTests(unittest.TestCase):
 def setUp(self):
  self.schema=v.load(P/'schemas/profile-result.v1.schema.json');self.example=v.load(P/'contracts/profile-result.v1.example.json')
 def test_reference(self): v.validate(v.load(P/'schemas/profile-items.v1.schema.json'),v.load(P/'contracts/PROFILE_ITEMS.json'))
 def test_example(self): v.validate(self.schema,self.example)
 def test_missing_item(self):
  del self.example['items']['9']
  with self.assertRaises(ValidationError):v.validate(self.schema,self.example)
 def test_unknown_zero(self):
  self.example['items']['4']['value']=0
  with self.assertRaises(ValidationError):v.validate(self.schema,self.example)
 def test_uncalibrated_score(self):
  self.example['items']['2']['confidence']={'kind':'uncalibrated','value':0}
  with self.assertRaises(ValidationError):v.validate(self.schema,self.example)
 def test_calibrated_zero(self):
  self.example['items']['2']['confidence']={'kind':'calibrated','value':0};v.validate(self.schema,self.example)
 def test_tempo_unit(self):
  self.example['items']['4']={'state':'known','value':{'tracker_bpm':117,'primary_bpm':117,'beat_unit':None},'confidence':{'kind':'not_calculated','value':None},'provenance':[{'kind':'scientific_module','ref':'synthetic'}]}
  with self.assertRaises(ValidationError):v.validate(self.schema,self.example)
 def test_duplicate_part_id(self):
  row={'id':'a','family':'strings','instrument':None,'presence':'possible','intervals':None};other=dict(row,instrument='violin')
  self.example['items']['9']={'state':'known','value':{'coverage':'partial','entries':[row,other]},'confidence':{'kind':'uncalibrated','value':None},'provenance':[{'kind':'scientific_module','ref':'synthetic'}]}
  with self.assertRaises(ValueError):v.validate(self.schema,self.example)
 def test_interval(self):
  row={'id':'a','family':'strings','instrument':None,'presence':'possible','intervals':[{'start_seconds':2,'end_seconds':1}]}
  self.example['items']['9']={'state':'known','value':{'coverage':'partial','entries':[row]},'confidence':{'kind':'uncalibrated','value':None},'provenance':[{'kind':'scientific_module','ref':'synthetic'}]}
  with self.assertRaises(ValueError):v.validate(self.schema,self.example)
 def test_tempo_empty_known(self):
  self.example['items']['4']={'state':'known','value':{'tracker_bpm':None,'primary_bpm':None,'beat_unit':None},'confidence':{'kind':'not_calculated','value':None},'provenance':[{'kind':'scientific_projection','ref':'synthetic'}]}
  with self.assertRaises(ValidationError):v.validate(self.schema,self.example)
 def test_invalid_reference_importance(self):
  ref=v.load(P/'contracts/PROFILE_ITEMS.json');ref['items'][4]['importance']='non_determining'
  with self.assertRaises(ValidationError):v.validate(v.load(P/'schemas/profile-items.v1.schema.json'),ref)
 def test_no_provenance_known(self):
  self.example['items']['2']['provenance']=[]
  with self.assertRaises(ValidationError):v.validate(self.schema,self.example)
 def test_documentary_example(self):
  v.validate(v.load(P/'schemas/profile-documentary.v1.schema.json'),v.load(P/'contracts/profile-documentary.v1.example.json'))
 def test_documentary_known_without_source(self):
  d=v.load(P/'contracts/profile-documentary.v1.example.json');d['lyricists']={'state':'known','value':['Example'], 'provenance':[]}
  with self.assertRaises(ValidationError):v.validate(v.load(P/'schemas/profile-documentary.v1.schema.json'),d)
 def test_other_voice_present_without_articulation(self):
  row={'id':'a','role':'narration','delivery':'spoken','articulation':'unknown','languages':None,'presence':'present','intervals':None}
  self.example['items']['8']={'state':'known','value':{'coverage':'partial','entries':[row]},'confidence':{'kind':'uncalibrated','value':None},'provenance':[{'kind':'scientific_module','ref':'synthetic'}]}
  with self.assertRaises(ValidationError):v.validate(self.schema,self.example)
  row['presence']='possible';v.validate(self.schema,self.example)
 def test_scientific_documentary_provenance_rejected(self):
  self.example['items']['2']['provenance']=[{'kind':'documentary','ref':'synthetic'}]
  with self.assertRaises(ValidationError):v.validate(self.schema,self.example)
 def test_candidate_tolerance_is_declarative(self):
  r=v.load(P/'contracts/PROFILE_ITEMS.json');r['items'][4]['comparison']={'mode':'absolute_seconds','tolerance':{'seconds':2}}
  v.validate(v.load(P/'schemas/profile-items.v1.schema.json'),r)
  r['items'][4]['comparison']['tolerance']['seconds']=-1
  with self.assertRaises(ValidationError):v.validate(v.load(P/'schemas/profile-items.v1.schema.json'),r)
if __name__=='__main__':unittest.main()
