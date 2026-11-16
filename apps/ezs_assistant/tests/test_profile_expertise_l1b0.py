import json, sqlite3, sys, tempfile, unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "app"))
import declarative_expertise_runner as r, profile_live_evidence as le

class TestProfileExpertiseL1b0(unittest.TestCase):
    def test_preset_immutability_and_promotion(self):
        td = Path(tempfile.mkdtemp()); db = td / "a.sqlite"; pd = td / "p"; pd.mkdir()
        (pd / "profile-baseline-1.json").write_text(json.dumps({"id":"profile-baseline-1","phase":"profile","schema":"v1","version":"1"}), encoding="utf-8")
        (pd / "profile-cand-2.json").write_text(json.dumps({"id":"profile-cand-2","phase":"profile","schema":"v1","version":"2"}), encoding="utf-8")
        (pd / "profile-v2.json").write_text(json.dumps({"id":"profile-v2","phase":"profile","schema":"v2"}), encoding="utf-8")
        s1 = r.sync_presets(db, pd); self.assertEqual(len(s1), 2); self.assertEqual(s1, r.sync_presets(db, pd))
        (pd / "profile-baseline-1.json").write_text(json.dumps({"id":"profile-baseline-1","phase":"profile","schema":"v1","version":"1","mut":1}), encoding="utf-8")
        with self.assertRaises(ValueError): r.sync_presets(db, pd)
        r.set_explicit_baseline("profile", "profile-cand-2", db)
        r.record_run_preset(291, "profile-baseline-1", s1[0]["sha256"], db); r.record_run_preset(291, "profile-cand-2", s1[1]["sha256"], db)
        con = sqlite3.connect(db)
        self.assertEqual(con.execute("SELECT preset_id FROM assistant_preset_baselines WHERE phase='profile'").fetchone()[0], "profile-cand-2")
        self.assertEqual(con.execute("SELECT count(*) FROM assistant_run_preset_attempts WHERE run_id=291 AND content_json IS NOT NULL").fetchone()[0], 2)

    def test_evidence_bpm_genre_and_pedagogical_exclusion(self):
        studio = [{"status":"read","url":"https://songbpm.com","title":"Dirty Old Town - The Pogues","text":"Dirty Old Town The Pogues BPM 123 Genre Folk"}]
        self.assertEqual(le.evaluate_live_documents({"key":"tempo","value":"123,047"},{"title":"Dirty Old Town","artist":"The Pogues"},studio)["verdict"], "bon")
        self.assertEqual(le.evaluate_live_documents({"key":"genre","value":"ballad"},{"title":"Dirty Old Town","artist":"The Pogues"},studio)["verdict"], "bon")
        tab = [{"status":"read","url":"https://ultimate-guitar.com","title":"Dirty Old Town Chords - The Pogues","text":"Dirty Old Town The Pogues harmonica tab guitar chords"}]
        self.assertEqual(le.evaluate_live_documents({"key":"instrument:0","value":"harmonica"},{"title":"Dirty Old Town","artist":"The Pogues"},tab)["verdict"], "non_trouve")

if __name__ == "__main__":
    unittest.main()