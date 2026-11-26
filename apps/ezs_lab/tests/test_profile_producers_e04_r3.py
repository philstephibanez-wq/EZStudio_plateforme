"""Exercise the actual installed producers without GPU/model dependencies."""
from __future__ import annotations
import ast
import copy
import importlib.util
import json
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
PROFILE = ROOT / 'python/ezstudio/pipeline/profile'
PRESET = ROOT / 'presets/profile'
sys.path.insert(0, str(PROFILE))
from producer_policy import from_phase_preset, policy_sha256, validate_policy
from items_projection import load_policy, project_scientific_items

CLAP = 'semantic.clap-open-vocabulary'
PANNS = 'semantic.panns-cnn14'
PASST = 'semantic.passt-audioset'


def load_file(name, filename):
    spec = importlib.util.spec_from_file_location(name, PROFILE / filename)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def load_producers():
    # Only model execution dependencies are stubbed. Consensus and policy code are real.
    module = types.ModuleType('module')
    module.ModuleContext = type('ModuleContext', (), {})
    module.ModuleConfig = type('ModuleConfig', (), {})
    module.run_module = None
    guard = types.ModuleType('optimization_guard')
    guard.run_with_probe = None
    canonical = load_file('r3_test_canonical', 'canonical_profile.py')
    with patch.dict(sys.modules, {'module': module, 'optimization_guard': guard, 'canonical_profile': canonical}):
        registry = load_file('r3_test_registry', 'module_registry.py')
    return registry, canonical


def tags(*labels):
    return [{'label': x, 'score': 0.5} for x in labels]


def rec(module, **families):
    return {'id': module, 'family': 'semantic', 'status': 'ok', 'normalized': families, 'raw': {}}


def semantic(voice=(), agreements=(), accepted=()):
    return {'voice': {'per_engine': {CLAP: tags(*voice)}}, 'audioset': {'agreements': list(agreements), 'accepted': list(accepted)}}


def agreement(label, modules=(PANNS, PASST)):
    return {'label': label, 'support': len(modules), 'sources': [{'module': m} for m in modules]}


class ProducerTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.old = json.loads((PRESET / 'profile-r3b35c-compat.v2b.json').read_text(encoding='utf8'))
        cls.new = json.loads((PRESET / 'profile-r3b35c-compat.v2c.json').read_text(encoding='utf8'))
        cls.registry, cls.canonical = load_producers()
        cls.projection_policy = load_policy(ROOT / 'contracts')

    def setUp(self):
        self.policy = validate_policy(self.new['settings']['producer_policy'])

    def vocals(self, sem):
        old = copy.deepcopy(sem)
        result = self.canonical.build_declared_vocal_profile(sem, self.policy)
        self.assertEqual(sem, old)
        self.assertFalse(result['calibrated'])
        self.assertFalse(result['usable_for_stems'])
        self.assertEqual(result['producer_policy_sha256'], policy_sha256(self.policy))
        return result

    def instruments(self, records):
        old = copy.deepcopy(records)
        result = self.registry._build_instrumentation_consensus(records, self.policy)
        self.assertEqual(records, old)
        self.assertFalse(result['calibrated'])
        self.assertFalse(result['usable_for_stems'])
        return result

    def test_preset_modules_unchanged(self):
        expected = copy.deepcopy(self.old)
        expected['revision'] = 'v2c'
        expected['settings']['producer_policy'] = self.policy
        self.assertEqual(self.new, expected)
        self.assertEqual(self.policy['instrumentation']['aliases'], self.registry._INSTRUMENT_CANONICAL)

    def test_policy_validation(self):
        for key, bad in [('clap_top_k', True), ('audioset_top_k', 0), ('minimum_engine_support', 4), ('weak_limit', None)]:
            with self.subTest(key=key):
                p = copy.deepcopy(self.policy)
                p['instrumentation'][key] = bad
                with self.assertRaises(ValueError): validate_policy(p)
        p = copy.deepcopy(self.policy); p['vocals']['roles']['backing_vocals']['audioset_direct'] = ['background music']
        with self.assertRaises(ValueError): validate_policy(p)
        p = copy.deepcopy(self.policy); p['instrumentation']['aliases']['new'] = 'not a canonical target'
        with self.assertRaises(ValueError): validate_policy(p)

    def test_required_new_policy(self):
        def loaded(revision, settings):
            return types.SimpleNamespace(value=types.SimpleNamespace(id='profile.r3b35c-compat', revision=revision, settings=settings))
        self.assertIsNone(from_phase_preset(loaded('v2b', {})))
        with self.assertRaises(ValueError): from_phase_preset(loaded('v2c', {}))
        self.assertEqual(from_phase_preset(loaded('v2c', {'producer_policy': self.policy})), self.policy)

    def test_manifest_identity_and_policy(self):
        reg = self.registry.ModuleRegistry(producer_policy=self.policy)
        manifest = reg.preset_manifest([])
        self.assertEqual(manifest['producer_policy'], self.policy)
        self.assertEqual(manifest['pipeline_revision'], 'profile-e04r3-' + policy_sha256(self.policy))
        # Run identity changes when scientific policy changes, independent of formatting.
        other = copy.deepcopy(self.policy); other['instrumentation']['clap_top_k'] += 1
        self.assertNotEqual(self.registry.ModuleRegistry(producer_policy=other).preset_manifest([])['pipeline_revision'], manifest['pipeline_revision'])
        other = copy.deepcopy(self.policy); other['instrumentation']['clap_top_k'] = 0
        with self.assertRaises(ValueError): self.registry.ModuleRegistry(producer_policy=other)

    def test_generic_singing_and_background_not_direct(self):
        generic = [agreement('Singing'), agreement('Background music'), agreement('Speech')]
        dims = self.vocals(semantic(agreements=generic, accepted=generic))['dimensions']
        self.assertEqual(dims['lead_vocal']['decision'], 'inconclusive')
        self.assertEqual(dims['backing_vocals']['decision'], 'inconclusive')
        self.assertEqual(dims['choir']['decision'], 'inconclusive')
        self.assertFalse(any(x['role'] == 'direct' for x in dims['lead_vocal']['evidence']))

    def test_explicit_lead_possible(self):
        result = self.vocals(semantic(voice=('male lead vocal',)))
        self.assertEqual(result['dimensions']['lead_vocal']['decision'], 'possible')
        out = project_scientific_items({}, {'vocal_profile': result}, {}, self.projection_policy)
        self.assertEqual(out['items']['7']['value']['entries'][0]['presence'], 'possible')
        self.assertFalse(out['usable_for_downstream'])

    def test_rank_parameter_controls_hypothesis(self):
        sem = semantic(voice=('spoken voice', 'male lead vocal'))
        self.policy['vocals']['high_rank_clap_max'] = 1
        self.assertEqual(self.vocals(sem)['dimensions']['lead_vocal']['decision'], 'inconclusive')
        self.policy['vocals']['high_rank_clap_max'] = 2
        self.assertEqual(self.vocals(sem)['dimensions']['lead_vocal']['decision'], 'possible')

    def test_backing_separate_from_choir(self):
        result = self.vocals(semantic(voice=('backing vocals',)))
        self.assertEqual(result['dimensions']['backing_vocals']['decision'], 'possible')
        self.assertEqual(result['dimensions']['choir']['decision'], 'inconclusive')
        out = project_scientific_items({}, {'vocal_profile': result}, {}, self.projection_policy)
        part = out['items']['8']['value']['entries'][0]
        self.assertEqual((part['role'], part['presence'], part['articulation']), ('backing', 'possible', 'unknown'))

    def test_choir_support_no_override(self):
        direct = agreement('Choir')
        sem = semantic(agreements=[direct], accepted=[direct])
        self.assertEqual(self.vocals(sem)['dimensions']['choir']['decision'], 'present')
        self.policy['vocals']['minimum_direct_engines'] = 3
        self.assertEqual(self.vocals(sem)['dimensions']['choir']['decision'], 'inconclusive')
        sem['voice']['per_engine'][CLAP] = tags('choir')
        self.assertEqual(self.vocals(sem)['dimensions']['choir']['decision'], 'present')

    def test_speech_no_role(self):
        sem = semantic(voice=('spoken voice',))
        sem['audioset']['per_engine'] = {PANNS: tags('Speech')}
        result = self.vocals(sem)
        out = project_scientific_items({}, {'vocal_profile': result}, {'semantic': sem}, self.projection_policy)
        self.assertIsNone(out['items']['8']['value'])
        self.assertEqual(len(out['speech_evidence']), 2)
        self.assertEqual(out['speech_role'], 'undetermined')

    def test_instrument_parameters_preserve_baseline(self):
        records = [rec(CLAP, instrumentation=tags('piano','keyboard','drum kit')), rec(PANNS, audioset=tags('Piano','Drums'))]
        self.assertEqual(self.instruments(records), self.registry._build_instrumentation_consensus(records))
        self.assertEqual({x['label'] for x in self.instruments(records)['candidates']}, {'piano','drum kit'})
        self.policy['instrumentation']['minimum_engine_support'] = 3
        self.assertEqual(self.instruments(records)['candidates'], [])

    def test_rank_and_aliases_effective(self):
        records = [rec(CLAP, instrumentation=tags('piano','drum kit')), rec(PANNS, audioset=tags('piano','drums'))]
        self.policy['instrumentation']['clap_top_k'] = 1
        self.assertEqual([r['label'] for r in self.instruments(records)['candidates']], ['piano'])
        self.policy['instrumentation']['clap_top_k'] = 2
        self.policy['instrumentation']['aliases']['drums'] = 'percussion'
        self.assertEqual([r['label'] for r in self.instruments(records)['candidates']], ['piano'])

    def test_duplicate_engine_cannot_inflate_support(self):
        result = self.instruments([rec(PANNS, audioset=tags('drum kit','drums')), rec(PANNS, audioset=tags('drum kit'))])
        self.assertEqual(result['candidates'], [])
        self.assertEqual(result['weak'][0]['support'], 1)

    def test_limits_effective(self):
        records = [rec(CLAP, instrumentation=tags('piano','drum kit','guitar','electric guitar')), rec(PANNS, audioset=tags('piano','drums'))]
        self.policy['instrumentation']['candidate_limit'] = 1
        self.policy['instrumentation']['weak_limit'] = 1
        result = self.instruments(records)
        self.assertEqual(len(result['candidates']), 1)
        self.assertEqual(len(result['weak']), 1)

    def test_consensus_wiring(self):
        records = [rec(CLAP, voice=tags('male lead vocal'), instrumentation=tags('piano')), rec(PANNS, audioset=tags('piano'))]
        out = self.registry.build_consensus(records, self.policy)
        self.assertEqual(out['vocal_profile']['dimensions']['lead_vocal']['decision'], 'possible')
        self.assertEqual(out['instrumentation']['candidates'][0]['label'], 'piano')
        self.assertEqual(out['policy']['producer_policy'], self.policy)
        self.assertEqual(out['policy']['producer_policy_sha256'], policy_sha256(self.policy))

    def test_runtime_hooks(self):
        runner = (PROFILE/'runner.py').read_text(encoding='utf8')
        factory = (PROFILE/'preset_registry_factory.py').read_text(encoding='utf8')
        registry = (PROFILE/'module_registry.py').read_text(encoding='utf8')
        self.assertEqual(runner.count('"profile/profile-r3b35c-compat.v2c.json"'), 1)
        self.assertNotIn('"profile/profile-r3b35c-compat.v2b.json"', runner)
        self.assertIn('ModuleRegistry(producer_policy=from_phase_preset(loaded))', factory)
        self.assertIn('build_consensus(records, self._producer_policy)', registry)
        for text in (runner, factory, registry): ast.parse(text)


if __name__ == '__main__': unittest.main(verbosity=2)
