# -*- coding: utf-8 -*-
"""Permanent PROFILE meter R3B contract tests (no audio, GPU, or external deps)."""
import ast
import importlib.util
import json
import math
import sys
import types
import unittest
from pathlib import Path
from unittest.mock import patch

HERE = Path(__file__).resolve()
ROOT = next((p for p in HERE.parents if (p / 'apps/ezs_lab/presets/profile/profile-r3b35c-compat.v2b.json').is_file()), None)
if ROOT is None:
    raise RuntimeError('EZS_PROFILE_R3B_ROOT_NOT_FOUND')
MODULE = ROOT / 'apps/ezs_lab/python/ezstudio/pipeline/profile/modules/meter_chords_shared.py'
PRESET = ROOT / 'apps/ezs_lab/presets/profile/profile-r3b35c-compat.v2b.json'
MODULE_IMPL = ROOT / 'apps/ezs_lab/python/ezstudio/pipeline/profile/module.py'
FACTORY = ROOT / 'apps/ezs_lab/python/ezstudio/pipeline/profile/preset_registry_factory.py'
METER = ROOT / 'apps/ezs_lab/python/ezstudio/pipeline/profile/meter_shared.py'


def load_with_stubs():
    observed = []
    np = types.ModuleType('numpy')
    np.float32 = 'float32'
    np.asarray = lambda x, dtype=None: x
    shared = types.ModuleType('meter_shared')
    def fake_meter(audio, sr, **kwargs):
        observed.append(kwargs['confidence_threshold'])
        return {'confidence': 0.75, 'label': '6/8', 'beat_this_checkpoint': 'stub'}
    shared.estimate_meter_from_chords_engine = fake_meter
    module = types.ModuleType('module')
    module.ModuleContext = type('ModuleContext', (), {})
    module.ModuleConfig = type('ModuleConfig', (), {})
    context = types.SimpleNamespace(y=[0.0], sr=22050)
    with patch.dict(sys.modules, {'numpy': np, 'meter_shared': shared, 'module': module}):
        ns = {'__name__': 'r3b_meter_test'}
        exec(compile(MODULE.read_bytes(), str(MODULE), 'exec'), ns)
    return ns['run'], context, observed


class ProfileMeterThresholdR3B(unittest.TestCase):
    def setUp(self):
        preset = json.loads(PRESET.read_text(encoding='utf-8-sig'))
        matches = [m for m in preset['modules'] if m['id'] == 'meter.chords-shared-r9']
        self.assertEqual(len(matches), 1)
        self.mod = matches[0]
        self.thresholds = self.mod['thresholds']

    def test_preset_existing_thresholds_preserved(self):
        self.assertEqual(self.mod['implementation']['callable'], 'modules.meter_chords_shared:run')
        for key in ('confidence', 'metric_level_score_margin', 'metric_level_min_score'):
            self.assertIn(key, self.thresholds)
        self.assertEqual(self.thresholds['confidence'], 0.5)
        self.assertEqual(self.thresholds['metric_level_score_margin'], 0.02)
        self.assertEqual(self.thresholds['metric_level_min_score'], 0.0)

    def test_config_passed_by_runtime_and_factory(self):
        tree = ast.parse(MODULE_IMPL.read_text(encoding='utf-8-sig'))
        routines = [n for n in tree.body if isinstance(n, ast.FunctionDef) and n.name == 'run_module']
        self.assertEqual(len(routines), 1)
        self.assertTrue(any(isinstance(x, ast.Call) and isinstance(x.func, ast.Name) and x.func.id == 'fn' and len(x.args) == 2 for x in ast.walk(routines[0])))
        factory = FACTORY.read_text(encoding='utf-8-sig')
        self.assertIn('thresholds=_resolve(m.thresholds,roots)', factory)
        meter = METER.read_text(encoding='utf-8-sig')
        self.assertIn('confidence >= float(confidence_threshold)', meter)

    def test_effective_threshold_forwarded(self):
        run, ctx, seen = load_with_stubs()
        with patch.dict('os.environ', {'EZSTUDIO_PROFILE_HPSS_REUSE': '0'}):
            for v in (0.0, 0.5, 0.73, 1.0):
                config = types.SimpleNamespace(thresholds=dict(self.thresholds, confidence=v))
                out = run(ctx, config)
                self.assertEqual(out['raw']['label'], '6/8')
                self.assertEqual(seen[-1], v)
        self.assertEqual(seen, [0.0, 0.5, 0.73, 1.0])

    def test_invalid_and_missing_threshold_rejected_without_meter_call(self):
        run, ctx, seen = load_with_stubs()
        with patch.dict('os.environ', {'EZSTUDIO_PROFILE_HPSS_REUSE': '0'}):
            for value in (-0.01, 1.01, '0.5', True, None, float('inf'), float('nan')):
                with self.subTest(value=repr(value)), self.assertRaises(ValueError):
                    run(ctx, types.SimpleNamespace(thresholds=dict(self.thresholds, confidence=value)))
            for malformed in ({}, None, []):
                with self.subTest(malformed=repr(malformed)), self.assertRaises(ValueError):
                    run(ctx, types.SimpleNamespace(thresholds=malformed))
        self.assertEqual(seen, [])

    def test_only_profile_module_changed_and_no_chords_import(self):
        source = MODULE.read_text(encoding='utf-8-sig')
        tree = ast.parse(source)
        fn = [n for n in tree.body if isinstance(n, ast.FunctionDef) and n.name == 'run']
        self.assertEqual(len(fn), 1)
        self.assertEqual([a.arg for a in fn[0].args.args], ['context', 'config'])
        self.assertNotIn('confidence_threshold=0.50,', source)


if __name__ == '__main__':
    unittest.main(verbosity=2)
