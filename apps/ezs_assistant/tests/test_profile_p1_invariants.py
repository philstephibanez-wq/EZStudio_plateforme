from declarative_expertise_runner import canonical_json, sha256_bytes
import json, sqlite3, sys, tempfile, unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / "app"))
import profile_final_verifier as v
from declarative_expertise_runner import sync_presets, set_explicit_baseline, record_run_preset


class TestProfileP1Invariants(unittest.TestCase):
    def _merge(self, assertions, inv_items, rev_items=None):
        verif = {"assertions": assertions}
        inv = {"identity": {"recording_identity_verified": True}, "items": inv_items}
        rev = {"items": rev_items or []}
        return {a["key"]: a for a in v.attach_documentary_findings(verif, inv, rev)["assertions"]}

    def test_run247_short_excerpt_is_never_marked_false(self):
        by_k = self._merge(
            [{"key": "duration", "value": "27.69 s"}, {"key": "choir", "value": "présent"}],
            [
                {"key": "duration", "evidence_review": {"reference_value": "6:00", "proposed_verdict": "faux", "references": [{"url": "https://musicbrainz.org"}]}},
                {"key": "choir", "evidence_review": {"reference_value": "présent", "proposed_verdict": "faux", "references": [{"url": "https://musicbrainz.org"}]}},
            ],
        )
        self.assertEqual(by_k["duration"]["verdict"], "non_trouve")
        self.assertEqual(by_k["choir"]["verdict"], "non_trouve")

    def test_run286_metric_octave_and_ternary_are_related_metric_not_bon(self):
        by_k = self._merge(
            [{"key": "tempo", "value": "151.999"}, {"key": "meter", "value": "6/8"}],
            [
                {"key": "tempo", "evidence_review": {"reference_value": "75 BPM", "proposed_verdict": "faux", "references": [{"url": "https://songbpm.com"}]}},
                {"key": "meter", "evidence_review": {"reference_value": "3/4", "proposed_verdict": "faux", "references": [{"url": "https://songbpm.com"}]}},
            ],
        )
        self.assertEqual(by_k["tempo"]["verdict"], "non_trouve")
        self.assertEqual(by_k["meter"]["verdict"], "non_trouve")

    def test_run291_discordant_and_indeterminate_bijection_and_url_guard(self):
        by_k = self._merge(
            [
                {"key": "genre", "value": "ballad"},
                {"key": "backing_vocals", "value": "possible"},
                {"key": "lead_vocal", "value": "présent"},
            ],
            [
                {"key": "genre", "evidence_review": {"reference_value": "folk", "proposed_verdict": "bon", "references": [{"url": "https://wikipedia.org"}]}},
                {"key": "backing_vocals", "evidence_review": {"reference_value": "possible", "proposed_verdict": "bon", "references": [{"url": "https://wikipedia.org"}]}},
                {"key": "lead_vocal", "evidence_review": {"reference_value": "présent", "proposed_verdict": "bon", "references": []}},
            ],
        )
        self.assertEqual(by_k["genre"]["verdict"], "faux")
        self.assertEqual(by_k["backing_vocals"]["verdict"], "non_trouve")
        self.assertEqual(by_k["lead_vocal"]["verdict"], "non_trouve")



    def test_sync_presets_and_candidate_do_not_overwrite_baseline_or_run_history(self):
        with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as td:
            root = Path(td)
            pdir = root / 'presets' / 'assistant'
            pdir.mkdir(parents=True)
            (pdir / 'profile-baseline-1.json').write_text(Path('apps/ezs_lab/presets/assistant/profile-baseline-1.json').read_text(encoding='utf-8'), encoding='utf-8')
            (pdir / 'profile-cand-2.json').write_text(json.dumps({**json.loads(Path('apps/ezs_lab/presets/assistant/profile-baseline-1.json').read_text(encoding='utf-8')), 'id': 'profile-cand-2'}), encoding='utf-8')
            db = root / 'lab.sqlite'
            sync_presets(db, pdir)
            set_explicit_baseline('profile', 'profile-cand-2', db, promoted_by='tester')
            c1=canonical_json({"id":"profile-cand-2","v":1}); record_run_preset(101, 'profile-cand-2', sha256_bytes(c1.encode()), content_json=c1, db_path=db)
            sync_presets(db, pdir)
            con = sqlite3.connect(str(db))
            try:
                base = con.execute('SELECT preset_id FROM assistant_preset_baselines WHERE phase=?', ('profile',)).fetchone()[0]
                self.assertEqual(base, 'profile-cand-2')
                c2=canonical_json({"id":"profile-qwen-baseline-1","v":2}); record_run_preset(101, 'profile-qwen-baseline-1', sha256_bytes(c2.encode()), content_json=c2, db_path=db)
                base_after = con.execute('SELECT preset_id FROM assistant_preset_baselines WHERE phase=?', ('profile',)).fetchone()[0]
                run_prov = con.execute('SELECT preset_id FROM assistant_run_preset WHERE run_id=?', (101,)).fetchone()[0]
                self.assertEqual(base_after, 'profile-cand-2')
                self.assertEqual(run_prov, 'profile-cand-2')
            finally:
                con.close()


    def test_utf8_normalization_and_fail_closed_preset(self):
        from profile_live_evidence import _norm, evaluate_live_documents
        import profile_web_investigator as wi
        self.assertEqual(_norm('Pr\u00e9sent / Ind\u00e9termin\u00e9'), 'present / indetermine')
        res = evaluate_live_documents({'key': 'voix_principale', 'value': 'pr\u00e9sent'}, {'title': 'Dirty Old Town', 'artist': 'The Pogues'}, [{'status': 'read', 'url': 'https://example.com', 'title': 'Dirty Old Town - The Pogues', 'text': 'Dirty Old Town by The Pogues features lead vocals by Shane MacGowan.'}])
        self.assertEqual(res['verdict'], 'bon')
        orig = wi.load_review_preset
        try:
            wi.load_review_preset = lambda *a, **kw: {'id': 'broken'}
            with self.assertRaises(RuntimeError):
                wi._load_doc_research_cfg()
        finally:
            wi.load_review_preset = orig


    def test_url_alone_never_confirms_recording_identity(self):
        ks=("tempo","lead_vocal","backing_vocals","vocal_harmonies","choir")
        verif={"assertions":[{"key":k,"value":"present","verdict":"non_trouve"} for k in ks]}
        inv={"identity":{"recording_identity_verified":False},"items":[{"key":k,"evidence_review":{"reference_value":"present","proposed_verdict":"bon","references":[{"url":"https://musicbrainz.org"}]}} for k in ks]}
        out=v.attach_documentary_findings(verif,inv,{"items":[]})
        for a in out["assertions"]: self.assertEqual(a["verdict"],"non_trouve")

    def test_unreconstructible_preset_attempt_rejected(self):
        import tempfile
        import gc
        with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as td:
            db = Path(td) / "unrec.sqlite"
            with self.assertRaises(ValueError):
                record_run_preset(999, "missing-preset", "000000", db_path=db)
            gc.collect()

    def test_worker_loads_lab_token_from_env_local_when_unset(self):
        import importlib.util, os, tempfile
        from unittest.mock import patch
        spec = importlib.util.spec_from_file_location(
            "profile_ai_requests_worker",
            Path(__file__).resolve().parents[1] / "runtime" / "profile_ai_requests_worker.py",
        )
        mod = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(mod)
        with tempfile.TemporaryDirectory() as td:
            env_file = Path(td) / ".env.local"
            env_file.write_text('EZSTUDIO_ANALYSIS_WORKER_TOKEN="secret_lab_automate_tok"\n', encoding="utf-8")
            with patch.dict(os.environ, {"EZSTUDIO_LAB_ROOT": td, "EZSTUDIOLAB_ASSISTANT_TOKEN": "", "EZSTUDIO_ANALYSIS_WORKER_TOKEN": ""}, clear=False):
                self.assertEqual(mod._load_lab_token(), "secret_lab_automate_tok")


    def test_p1_vocal_strict_and_p2_sha256_guard(self):
        out=v.attach_documentary_findings({"assertions":[{"key":"lead_vocal","value":"not sent"},{"key":"choir","value":"present"}]},{"identity":{"recording_identity_verified":True},"items":[{"key":"lead_vocal","evidence_review":{"reference_value":"not sent","proposed_verdict":"bon","references":[{"url":"https://musicbrainz.org"}]}},{"key":"choir","evidence_review":{"reference_value":"absent","proposed_verdict":"faux","references":[{"url":"https://musicbrainz.org"}]}}]},{"items":[]})
        by_k={a["key"]:a["verdict"] for a in out["assertions"]}
        self.assertEqual(by_k["lead_vocal"],"non_trouve")
        self.assertEqual(by_k["choir"],"faux")
        with tempfile.TemporaryDirectory(ignore_cleanup_errors=True) as td:
            with self.assertRaises(ValueError): record_run_preset(202,"p","badsha",content_json="{'a':1}",db_path=Path(td)/"s.db")

if __name__ == "__main__":
    unittest.main()
