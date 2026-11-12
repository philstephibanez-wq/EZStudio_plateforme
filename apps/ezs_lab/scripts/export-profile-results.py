from __future__ import annotations

import argparse
import csv
import json
import sqlite3
import sys
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def rows(conn: sqlite3.Connection, sql: str, params: tuple[Any, ...] = ()) -> list[dict[str, Any]]:
    cur = conn.execute(sql, params)
    cols = [d[0] for d in cur.description]
    return [dict(zip(cols, row)) for row in cur.fetchall()]


def table_exists(conn: sqlite3.Connection, name: str) -> bool:
    return (
        conn.execute(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?",
            (name,),
        ).fetchone()[0]
        > 0
    )


def decode_json(value: Any) -> Any:
    if not isinstance(value, str) or value == "":
        return {}
    try:
        return json.loads(value)
    except json.JSONDecodeError:
        return {}


def safe(value: str) -> str:
    import re

    out = re.sub(r"[^A-Za-z0-9._-]+", "-", value.strip()).strip("-._")
    return out or "item"


def get_path(obj: Any, *keys: str) -> Any:
    cur = obj
    for key in keys:
        if not isinstance(cur, dict):
            return None
        cur = cur.get(key)
    return cur


def profile_summary(
    profile: dict[str, Any],
    *,
    run: dict[str, Any],
    job: dict[str, Any] | None,
    artifact_path: str,
    latest_for_audio: bool,
) -> dict[str, Any]:
    characteristics = profile.get("characteristics") or {}
    profile_view = profile.get("profile_view") or {}
    tempo = characteristics.get("tempo") or {}
    metric = profile_view.get("tempo_metric") or {}
    meter = characteristics.get("time_signature") or {}
    key = characteristics.get("key") or {}
    tuning = profile_view.get("tuning") or {}
    methods = tuning.get("methods") or {}
    librosa = methods.get("librosa_family") or {}
    cqt = methods.get("cqt_detune_scan") or {}
    spectral = methods.get("spectral_peak_offset") or {}

    return {
        "run_id": int(run["id"]),
        "public_id": str(run.get("public_id") or ""),
        "song_id": int(run["song_id"]),
        "title": str(run.get("title") or ""),
        "artist": str(run.get("artist") or ""),
        "source_filename": str(run.get("source_filename") or ""),
        "audio_sha256": str(run.get("audio_sha256") or ""),
        "state": str(run.get("state") or ""),
        "created_at": str(run.get("created_at") or ""),
        "finished_at": str(run.get("finished_at") or ""),
        "latest_for_audio": latest_for_audio,
        "job_id": None if job is None else int(job["id"]),
        "job_status": None if job is None else str(job.get("status") or ""),
        "job_progress": None if job is None else int(job.get("progress") or 0),
        "artifact_path": artifact_path,

        "tempo_bpm": tempo.get("bpm"),
        "tempo_metric_decision": metric.get("decision"),
        "tempo_metric_tracker_bpm": metric.get("tracker_bpm"),
        "tempo_metric_primary_bpm": get_path(metric, "primary", "bpm"),
        "tempo_metric_primary_beat_unit": get_path(metric, "primary", "beat_unit"),
        "tempo_metric_relationship": metric.get("relationship"),

        "time_signature": meter.get("signature"),
        "time_signature_confidence": meter.get("confidence"),

        "key_label": key.get("label"),
        "key_decision": key.get("decision"),
        "key_confidence": key.get("confidence"),
        "key_calibrated": key.get("calibrated"),

        "tuning_source": tuning.get("source"),
        "tuning_decision": tuning.get("decision"),
        "tuning_reference_hz": tuning.get("reference_hz"),
        "tuning_cents_from_a440": tuning.get("cents_from_a440"),
        "tuning_cross_method_disagreement_cents": tuning.get(
            "cross_method_disagreement_cents"
        ),

        "tuning_librosa_cents": librosa.get("cents"),
        "tuning_librosa_reference_hz": librosa.get("reference_hz"),
        "tuning_librosa_stable": librosa.get("stable"),

        "tuning_cqt_cents": cqt.get("cents_from_a440"),
        "tuning_cqt_reference_hz": cqt.get("reference_hz"),
        "tuning_cqt_stable": cqt.get("stable"),
        "tuning_cqt_global_cents": cqt.get("global_cents"),
        "tuning_cqt_global_reference_hz": cqt.get("global_reference_hz"),

        "tuning_spectral_cents": spectral.get("cents_from_a440"),
        "tuning_spectral_reference_hz": spectral.get("reference_hz"),
        "tuning_spectral_stable": spectral.get("stable"),

        "genre_hierarchy": profile_view.get("genre_hierarchy") or {},
        "instrumentation": profile_view.get("instrumentation") or {},
        "vocal_profile": profile_view.get("vocal_profile") or {},
        "choirs": profile_view.get("choirs") or {},
        "audioset": profile_view.get("audioset") or {},

        "preset": profile.get("preset") or {},
        "environment": profile.get("environment") or {},
        "warnings": profile.get("warnings") or [],
    }


def write_csv(path: Path, summaries: list[dict[str, Any]]) -> None:
    scalar_keys = [
        "run_id",
        "public_id",
        "song_id",
        "title",
        "artist",
        "source_filename",
        "audio_sha256",
        "state",
        "created_at",
        "finished_at",
        "latest_for_audio",
        "job_id",
        "job_status",
        "job_progress",
        "artifact_path",
        "tempo_bpm",
        "tempo_metric_decision",
        "tempo_metric_tracker_bpm",
        "tempo_metric_primary_bpm",
        "tempo_metric_primary_beat_unit",
        "tempo_metric_relationship",
        "time_signature",
        "time_signature_confidence",
        "key_label",
        "key_decision",
        "key_confidence",
        "key_calibrated",
        "tuning_source",
        "tuning_decision",
        "tuning_reference_hz",
        "tuning_cents_from_a440",
        "tuning_cross_method_disagreement_cents",
        "tuning_librosa_cents",
        "tuning_librosa_reference_hz",
        "tuning_librosa_stable",
        "tuning_cqt_cents",
        "tuning_cqt_reference_hz",
        "tuning_cqt_stable",
        "tuning_cqt_global_cents",
        "tuning_cqt_global_reference_hz",
        "tuning_spectral_cents",
        "tuning_spectral_reference_hz",
        "tuning_spectral_stable",
    ]
    json_keys = [
        "genre_hierarchy",
        "instrumentation",
        "vocal_profile",
        "choirs",
        "audioset",
        "preset",
        "environment",
        "warnings",
    ]

    with path.open("w", encoding="utf-8-sig", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=scalar_keys + json_keys)
        writer.writeheader()
        for summary in summaries:
            row = {key: summary.get(key) for key in scalar_keys}
            for key in json_keys:
                row[key] = json.dumps(
                    summary.get(key),
                    ensure_ascii=False,
                    separators=(",", ":"),
                )
            writer.writerow(row)


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Export all EZStudio PROFILE results into one ZIP."
    )
    parser.add_argument(
        "--repo",
        default=str(Path(__file__).resolve().parents[1]),
        help="EZStudio_lab repository root.",
    )
    parser.add_argument(
        "--db",
        default=None,
        help="SQLite database path. Defaults to <repo>/data/benchmark.sqlite.",
    )
    parser.add_argument(
        "--output",
        default=None,
        help="Output ZIP. Defaults to H:\\temp\\EZStudio_PROFILE_ALL_<timestamp>.zip.",
    )
    args = parser.parse_args()

    repo = Path(args.repo).resolve()
    db = Path(args.db).resolve() if args.db else repo / "data" / "benchmark.sqlite"
    if not db.is_file():
        raise RuntimeError(f"database_not_found:{db}")

    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    output = (
        Path(args.output).resolve()
        if args.output
        else Path(r"H:\temp") / f"EZStudio_PROFILE_ALL_{timestamp}.zip"
    )
    output.parent.mkdir(parents=True, exist_ok=True)

    conn = sqlite3.connect(db)
    conn.row_factory = sqlite3.Row

    required = {"songs", "scientific_runs", "scientific_artifacts"}
    missing = sorted(name for name in required if not table_exists(conn, name))
    if missing:
        raise RuntimeError("missing_tables:" + ",".join(missing))

    profile_runs = rows(
        conn,
        """
        SELECT
            sr.*,
            s.title,
            s.artist,
            s.source_filename,
            s.audio_sha256
        FROM scientific_runs sr
        JOIN songs s ON s.id=sr.song_id
        WHERE sr.item='profile'
          AND sr.state!='abandoned'
        ORDER BY sr.id
        """,
    )

    jobs_by_run: dict[int, dict[str, Any]] = {}
    if table_exists(conn, "analysis_jobs"):
        for job in rows(
            conn,
            """
            SELECT *
            FROM analysis_jobs
            WHERE kind='profile'
              AND scientific_run_id IS NOT NULL
            ORDER BY id
            """,
        ):
            jobs_by_run[int(job["scientific_run_id"])] = job

    artifacts_by_run: dict[int, list[dict[str, Any]]] = {}
    for artifact in rows(
        conn,
        """
        SELECT *
        FROM scientific_artifacts
        WHERE role='profile_json'
        ORDER BY run_id,id
        """,
    ):
        artifacts_by_run.setdefault(int(artifact["run_id"]), []).append(artifact)

    latest_run_for_audio: dict[str, int] = {}
    for run in profile_runs:
        audio_hash = str(run.get("audio_sha256") or f"song:{run['song_id']}")
        if str(run.get("state") or "") == "done":
            latest_run_for_audio[audio_hash] = int(run["id"])

    summaries: list[dict[str, Any]] = []
    raw_files: list[tuple[Path, str]] = []
    missing_artifacts: list[dict[str, Any]] = []

    for run in profile_runs:
        run_id = int(run["id"])
        artifact_rows = artifacts_by_run.get(run_id, [])
        artifact = artifact_rows[-1] if artifact_rows else None
        artifact_path = (
            str(artifact.get("path") or "")
            if artifact is not None
            else ""
        )

        if artifact_path == "":
            missing_artifacts.append(
                {
                    "run_id": run_id,
                    "public_id": run.get("public_id"),
                    "state": run.get("state"),
                    "reason": "profile_json_artifact_not_registered",
                }
            )
            continue

        path = Path(artifact_path)
        if not path.is_file():
            missing_artifacts.append(
                {
                    "run_id": run_id,
                    "public_id": run.get("public_id"),
                    "state": run.get("state"),
                    "artifact_path": artifact_path,
                    "reason": "profile_json_file_missing",
                }
            )
            continue

        try:
            profile = json.loads(path.read_text(encoding="utf-8"))
        except Exception as exc:
            missing_artifacts.append(
                {
                    "run_id": run_id,
                    "public_id": run.get("public_id"),
                    "state": run.get("state"),
                    "artifact_path": artifact_path,
                    "reason": f"profile_json_invalid:{type(exc).__name__}:{exc}",
                }
            )
            continue

        audio_hash = str(run.get("audio_sha256") or f"song:{run['song_id']}")
        summary = profile_summary(
            profile,
            run=run,
            job=jobs_by_run.get(run_id),
            artifact_path=artifact_path,
            latest_for_audio=latest_run_for_audio.get(audio_hash) == run_id,
        )
        summaries.append(summary)

        arcname = (
            f"profiles/"
            f"{run_id:06d}__{safe(str(run.get('title') or 'untitled'))}"
            f"__{safe(str(run.get('artist') or 'unknown'))}"
            f"/profile.json"
        )
        raw_files.append((path, arcname))

    latest_only = [row for row in summaries if row["latest_for_audio"]]

    temp_root = output.parent / f".ezstudio_profile_export_{timestamp}"
    temp_root.mkdir(parents=True, exist_ok=True)
    try:
        all_json = temp_root / "profile-summary-all-runs.json"
        latest_json = temp_root / "profile-summary-latest.json"
        all_csv = temp_root / "profile-summary-all-runs.csv"
        latest_csv = temp_root / "profile-summary-latest.csv"
        manifest_path = temp_root / "manifest.json"

        all_json.write_text(
            json.dumps(summaries, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
        latest_json.write_text(
            json.dumps(latest_only, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
        write_csv(all_csv, summaries)
        write_csv(latest_csv, latest_only)

        manifest = {
            "schema": "ezstudio.profile-export-all.v1",
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "repository": str(repo),
            "database": str(db),
            "profile_runs_found": len(profile_runs),
            "profile_results_exported": len(summaries),
            "latest_audio_results": len(latest_only),
            "missing_or_invalid_artifacts": missing_artifacts,
            "notes": [
                "SQLite scientific_runs/scientific_artifacts are the source of truth.",
                "No audio binary is included.",
                "All registered readable profile_json artifacts are included.",
                "profile-summary-latest.* contains the latest done PROFILE result per audio hash.",
                "profile-summary-all-runs.* preserves historical PROFILE runs.",
            ],
        }
        manifest_path.write_text(
            json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )

        with zipfile.ZipFile(
            output,
            "w",
            zipfile.ZIP_DEFLATED,
            compresslevel=9,
        ) as archive:
            archive.write(manifest_path, "manifest.json")
            archive.write(all_json, "profile-summary-all-runs.json")
            archive.write(latest_json, "profile-summary-latest.json")
            archive.write(all_csv, "profile-summary-all-runs.csv")
            archive.write(latest_csv, "profile-summary-latest.csv")
            for path, arcname in raw_files:
                archive.write(path, arcname)

        print("EZSTUDIO_PROFILE_EXPORT_ALL_OK")
        print(f"profile_runs_found={len(profile_runs)}")
        print(f"profile_results_exported={len(summaries)}")
        print(f"latest_audio_results={len(latest_only)}")
        print(f"missing_or_invalid_artifacts={len(missing_artifacts)}")
        print(f"zip={output}")
        return 0
    finally:
        for child in temp_root.glob("*"):
            try:
                child.unlink()
            except OSError:
                pass
        try:
            temp_root.rmdir()
        except OSError:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
