import sys, unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "app"))
import profile_universal_investigator as u

class TestProfileUniversal6Steps(unittest.TestCase):
    def test_6_steps_french_song_set_theoretic(self):
        ident = {"artist": "Christophe", "title": "Aline"}
        qs = u.step1_build_pivot_queries(ident)
        self.assertEqual(qs[0], '"Christophe" "Aline"')
        docs = [
            {"status": "read", "url": "https://wikipedia.org", "title": "Aline (chanson) — Wikipedia", "text": "Aline est une chanson ecrite et interpretee par Christophe sortie en 1965. Genre chanson francaise, slow, ballade. Guitare, piano, orchestre."},
            {"status": "read", "url": "https://ultimate-guitar.com", "title": "Aline Chords", "text": "Christophe Aline guitar tab harmonica tab"},
            {"status": "read", "url": "https://songbpm.com", "title": "Aline - Other Artist", "text": "BPM 179"},
        ]
        sets = u.step3_select_source_sets(docs, ident)
        self.assertEqual(len(sets["E_enc"]), 1)
        self.assertEqual(len(sets["E_excl"]), 2)
        fb = {"status": "ok", "facts": {"duration_sec": 168.0, "tempo_bpm": 92.0, "genres": ["chanson francaise", "ballade"], "instruments": ["guitare", "piano"], "lead_vocal": "present", "choir": "present", "evidence": {"instruments": {"url": "https://wikipedia.org", "quote": "Guitare, piano, orchestre"}}}}
        self.assertEqual(u.step5_and_6_confront_and_judge({"key": "instrument:0", "value": "guitar"}, fb, sets)["proposed_verdict"], "bon")
        self.assertEqual(u.step5_and_6_confront_and_judge({"key": "genre", "value": "singer-songwriter"}, fb, sets)["proposed_verdict"], "bon")
        self.assertIsNone(u.step5_and_6_confront_and_judge({"key": "choir", "value": "inconclusive"}, fb, sets))
        fb2 = {"status": "ok", "facts": {"tempo_bpm": 179.0, "meter": "3/4"}}
        self.assertEqual(u.step5_and_6_confront_and_judge({"key": "tempo", "value": "92.29"}, fb2, sets)["proposed_verdict"], "bon")
        self.assertEqual(u.step5_and_6_confront_and_judge({"key": "meter", "value": "6/8"}, fb2, sets)["proposed_verdict"], "bon")

if __name__ == "__main__":
    unittest.main()