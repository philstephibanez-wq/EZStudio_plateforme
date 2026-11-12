from __future__ import annotations

import argparse
import json
import os
import sqlite3
import sys
import traceback
from pathlib import Path

from engine import analyze, ENGINE_VERSION
from observability import build_observability_bundle


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--db", required=True)
    ap.add_argument("--run-id", type=int, required=True)
    ap.add_argument("--audio", required=True)
    ap.add_argument("--signature", required=True)
    ap.add_argument("--deps", required=True)
    ap.add_argument("--keep-upload", choices=["0", "1"], default="0")
    ap.add_argument("--progress-file", default="")
    ap.add_argument("--selection-request", default="")
    a = ap.parse_args()

    progress_file = Path(a.progress_file).resolve() if a.progress_file else None
    last_progress = 0

    def write_progress_file(percent: int, status: str = "running", error: str | None = None):
        if progress_file is None:
            return
        progress_file.parent.mkdir(parents=True, exist_ok=True)
        payload = {"percent": int(percent), "status": status}
        if error:
            payload["error"] = str(error)
        tmp = progress_file.with_suffix(progress_file.suffix + ".tmp")
        tmp.write_text(json.dumps(payload, ensure_ascii=False) + "\n", encoding="utf-8")
        os.replace(tmp, progress_file)

    db = sqlite3.connect(a.db, timeout=60)
    db.execute("PRAGMA journal_mode=WAL")
    db.execute("PRAGMA foreign_keys=ON")

    def log(level, msg):
        print(level, msg, flush=True)
        db.execute(
            "INSERT INTO run_logs(run_id,created_at,level,message) "
            "VALUES(?,datetime('now'),?,?)",
            (a.run_id, level, str(msg)),
        )
        db.commit()

    def progress(p):
        nonlocal last_progress
        last_progress = max(0, min(100, int(p)))
        db.execute(
            "UPDATE benchmark_runs "
            "SET progress=?,updated_at=datetime('now') WHERE id=?",
            (last_progress, a.run_id),
        )
        db.commit()
        write_progress_file(last_progress)

    dep_root = Path(a.deps)
    os.environ["TORCH_HOME"] = str(dep_root.parent / "torch_cache")

    audio = Path(a.audio)
    work = dep_root.parent / "work" / f"run-{a.run_id}"
    project_dir = Path(__file__).resolve().parents[1]

    experiment_inputs = None
    if a.selection_request:
        selection_path = Path(a.selection_request).resolve()
        payload = json.loads(selection_path.read_text(encoding="utf-8"))
        if not isinstance(payload, dict) or payload.get("schema") != "ezstudio.chords.selection.v1":
            raise RuntimeError("invalid_chords_selection_request")
        experiment_inputs = payload

    try:
        db.execute(
            "UPDATE benchmark_runs "
            "SET status='running',progress=1,updated_at=datetime('now'),engine_version=? "
            "WHERE id=?",
            (ENGINE_VERSION, a.run_id),
        )
        db.commit()
        log("INFO", "worker démarré")

        result = analyze(
            audio,
            a.signature,
            Path(a.deps),
            work,
            progress,
            log,
            experiment_inputs=experiment_inputs,
        )

        # V10 observability is intentionally outside engine.py:
        # the six validated metric/downbeat methods remain untouched.
        progress(96)
        observability = build_observability_bundle(
            run_id=a.run_id,
            audio_path=audio,
            result=result,
            work_dir=work,
            project_dir=project_dir,
            log=log,
        )
        result["observability"] = observability

        db.execute("DELETE FROM algorithm_results WHERE run_id=?", (a.run_id,))
        for item in result["algorithms"]:
            db.execute(
                "INSERT INTO algorithm_results("
                "run_id,algorithm,phase,score,margin,grid_json"
                ") VALUES(?,?,?,?,?,?)",
                (
                    a.run_id,
                    item["algorithm"],
                    int(item["phase"]),
                    float(item["score"]),
                    float(item["margin"]),
                    json.dumps(item["grid"], ensure_ascii=False),
                ),
            )

        db.execute(
            "UPDATE benchmark_runs "
            "SET status='done',progress=100,detected_signature=?,tempo=?,"
            "result_json=?,updated_at=datetime('now'),error=NULL "
            "WHERE id=?",
            (
                result["signature"],
                float(result["tempo"]),
                json.dumps(result, ensure_ascii=False),
                a.run_id,
            ),
        )
        db.commit()
        progress(100)
        write_progress_file(100, "completed")
        log("INFO", "analyse terminée")

        if a.keep_upload == "0":
            try:
                audio.unlink(missing_ok=True)
                log("INFO", "upload source supprimé après analyse")
            except Exception as e:
                log("WARN", f"impossible de supprimer upload: {e}")

        return 0

    except Exception as e:
        tb = traceback.format_exc()
        print(tb, file=sys.stderr)
        log("ERROR", f"{type(e).__name__}: {e}")
        db.execute(
            "UPDATE benchmark_runs "
            "SET status='error',updated_at=datetime('now'),error=? WHERE id=?",
            (f"{type(e).__name__}: {e}", a.run_id),
        )
        db.commit()
        write_progress_file(last_progress, "error", f"{type(e).__name__}: {e}")
        return 1
    finally:
        db.close()


if __name__ == "__main__":
    raise SystemExit(main())
