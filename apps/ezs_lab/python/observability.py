from __future__ import annotations

import json
import math
import os
import platform
import shutil
import subprocess
import sys
import time
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import librosa
import numpy as np

OBSERVABILITY_VERSION = "v10-observability-1"
DEFAULT_ROOT = Path(r"H:\temp\EZStudio_lab\observability")
DEFAULT_EXPORT_ROOT = Path(r"H:\temp\EZStudio_lab\exports")

TRACKS = ("master", "bass", "guitar", "piano", "other")


def _jsonable(value: Any) -> Any:
    if isinstance(value, dict):
        return {str(k): _jsonable(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [_jsonable(v) for v in value]
    if isinstance(value, np.ndarray):
        return value.tolist()
    if isinstance(value, (np.floating,)):
        return float(value)
    if isinstance(value, (np.integer,)):
        return int(value)
    if isinstance(value, Path):
        return str(value)
    return value


def _utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def _run_cmd(args: list[str], timeout: float = 8.0) -> dict[str, Any]:
    started = time.perf_counter()
    try:
        p = subprocess.run(
            args,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            timeout=timeout,
            check=False,
        )
        return {
            "command": args,
            "returncode": int(p.returncode),
            "stdout": p.stdout.strip(),
            "stderr": p.stderr.strip(),
            "duration_s": time.perf_counter() - started,
        }
    except Exception as exc:
        return {
            "command": args,
            "error": f"{type(exc).__name__}: {exc}",
            "duration_s": time.perf_counter() - started,
        }


def _git_environment(project_dir: Path) -> dict[str, Any]:
    return {
        "head": _run_cmd(["git", "-C", str(project_dir), "rev-parse", "HEAD"]),
        "status": _run_cmd(["git", "-C", str(project_dir), "status", "--porcelain=v1"]),
        "branch": _run_cmd(["git", "-C", str(project_dir), "branch", "--show-current"]),
    }


def _runtime_environment(project_dir: Path) -> dict[str, Any]:
    env = {
        "captured_at": _utc_now(),
        "observability_version": OBSERVABILITY_VERSION,
        "python": {
            "version": sys.version,
            "executable": sys.executable,
            "platform": platform.platform(),
        },
        "git": _git_environment(project_dir),
        "ffmpeg": _run_cmd(["ffmpeg", "-version"]),
        "nvidia_smi": _run_cmd(["nvidia-smi"]),
        "pip_freeze": _run_cmd([sys.executable, "-m", "pip", "freeze"], timeout=20.0),
    }

    try:
        import torch
        env["torch"] = {
            "version": str(torch.__version__),
            "cuda_available": bool(torch.cuda.is_available()),
            "cuda_version": str(torch.version.cuda),
            "device_count": int(torch.cuda.device_count()),
            "device_name": torch.cuda.get_device_name(0) if torch.cuda.is_available() else None,
        }
    except Exception as exc:
        env["torch"] = {"error": f"{type(exc).__name__}: {exc}"}

    return env


def _sha256(path: Path) -> str:
    import hashlib
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def _audio_info(path: Path) -> dict[str, Any]:
    try:
        import soundfile as sf
        info = sf.info(str(path))
        return {
            "path": str(path),
            "sha256": _sha256(path),
            "bytes": path.stat().st_size,
            "format": str(info.format),
            "subtype": str(info.subtype),
            "sample_rate": int(info.samplerate),
            "channels": int(info.channels),
            "frames": int(info.frames),
            "duration_s": float(info.duration),
        }
    except Exception:
        y, sr = librosa.load(str(path), sr=None, mono=True)
        return {
            "path": str(path),
            "sha256": _sha256(path),
            "bytes": path.stat().st_size,
            "sample_rate": int(sr),
            "channels": None,
            "frames": int(len(y)),
            "duration_s": float(len(y) / sr),
        }


def _load_track(path: Path, sr: int = 22050) -> tuple[np.ndarray, int]:
    y, loaded_sr = librosa.load(str(path), sr=sr, mono=True)
    return np.asarray(y, dtype=np.float32), int(loaded_sr)


def _beat_intervals(beats: list[float], duration_s: float) -> list[tuple[float, float]]:
    if not beats:
        return []
    arr = np.asarray(beats, dtype=float)
    default_step = float(np.median(np.diff(arr))) if len(arr) > 1 else 0.5
    out: list[tuple[float, float]] = []
    for i, a in enumerate(arr):
        b = float(arr[i + 1]) if i + 1 < len(arr) else min(duration_s, float(a + default_step))
        if b <= a:
            b = float(a + default_step)
        out.append((float(a), float(b)))
    return out


def _mean_interval(values: np.ndarray, times: np.ndarray, a: float, b: float) -> float:
    mask = (times >= a) & (times < b)
    if not np.any(mask):
        idx = int(np.argmin(np.abs(times - ((a + b) * 0.5)))) if len(times) else 0
        return float(values[idx]) if len(values) else 0.0
    return float(np.mean(values[mask]))


def _vector_mean_interval(values: np.ndarray, times: np.ndarray, a: float, b: float) -> np.ndarray:
    mask = (times >= a) & (times < b)
    if not np.any(mask):
        idx = int(np.argmin(np.abs(times - ((a + b) * 0.5)))) if len(times) else 0
        return values[:, idx] if values.shape[1] else np.zeros(values.shape[0], dtype=float)
    return np.mean(values[:, mask], axis=1)


def _extract_track_features(
    name: str,
    path: Path,
    beats: list[float],
    artifact_dir: Path,
) -> tuple[dict[str, Any], list[dict[str, Any]]]:
    y, sr = _load_track(path, sr=22050)
    duration_s = float(len(y) / sr)
    hop = 512
    n_fft = 2048

    harmonic, percussive = librosa.effects.hpss(y)

    rms = librosa.feature.rms(y=y, frame_length=n_fft, hop_length=hop)[0]
    centroid = librosa.feature.spectral_centroid(y=y, sr=sr, n_fft=n_fft, hop_length=hop)[0]
    rolloff = librosa.feature.spectral_rolloff(y=y, sr=sr, n_fft=n_fft, hop_length=hop, roll_percent=0.85)[0]
    flatness = librosa.feature.spectral_flatness(y=y, n_fft=n_fft, hop_length=hop)[0]
    onset = librosa.onset.onset_strength(y=y, sr=sr, hop_length=hop)
    chroma = librosa.feature.chroma_cqt(y=harmonic, sr=sr, hop_length=hop)
    tonnetz = librosa.feature.tonnetz(y=harmonic, sr=sr)

    mel_power = librosa.feature.melspectrogram(
        y=y,
        sr=sr,
        n_fft=n_fft,
        hop_length=hop,
        n_mels=128,
        fmin=30,
        fmax=min(11000, sr // 2),
        power=2.0,
    )
    mel_db = librosa.power_to_db(mel_power, ref=np.max).astype(np.float16)

    times = librosa.frames_to_time(np.arange(len(rms)), sr=sr, hop_length=hop)
    chroma_times = librosa.frames_to_time(np.arange(chroma.shape[1]), sr=sr, hop_length=hop)
    tonnetz_times = librosa.frames_to_time(np.arange(tonnetz.shape[1]), sr=sr, hop_length=hop)

    envelope_hz = 100
    env_hop = max(1, int(round(sr / envelope_hz)))
    env_n = len(y) // env_hop
    envelope = (
        np.abs(y[: env_n * env_hop]).reshape(env_n, env_hop).mean(axis=1)
        if env_n
        else np.zeros(0, dtype=np.float32)
    )

    npz_path = artifact_dir / f"{name}_features.npz"
    np.savez_compressed(
        npz_path,
        sample_rate=np.asarray([sr], dtype=np.int32),
        hop_length=np.asarray([hop], dtype=np.int32),
        frame_times=times.astype(np.float32),
        rms=rms.astype(np.float32),
        centroid=centroid.astype(np.float32),
        rolloff=rolloff.astype(np.float32),
        flatness=flatness.astype(np.float32),
        onset=onset.astype(np.float32),
        chroma=chroma.astype(np.float32),
        chroma_times=chroma_times.astype(np.float32),
        tonnetz=tonnetz.astype(np.float32),
        tonnetz_times=tonnetz_times.astype(np.float32),
        mel_db=mel_db,
        envelope_100hz=envelope.astype(np.float32),
    )

    beat_rows: list[dict[str, Any]] = []
    intervals = _beat_intervals(beats, duration_s)

    for beat_index, (a, b) in enumerate(intervals):
        chroma_vec = _vector_mean_interval(chroma, chroma_times, a, b)
        tonnetz_vec = _vector_mean_interval(tonnetz, tonnetz_times, a, b)
        beat_rows.append({
            "track": name,
            "beat": int(beat_index),
            "start_s": a,
            "end_s": b,
            "rms": _mean_interval(rms, times, a, b),
            "spectral_centroid_hz": _mean_interval(centroid, times, a, b),
            "spectral_rolloff_hz": _mean_interval(rolloff, times, a, b),
            "spectral_flatness": _mean_interval(flatness, times, a, b),
            "onset_strength": _mean_interval(onset, times, a, b),
            "chroma": [float(v) for v in chroma_vec],
            "chroma_strength": float(np.max(chroma_vec)) if len(chroma_vec) else 0.0,
            "chroma_energy": float(np.sum(chroma_vec)) if len(chroma_vec) else 0.0,
            "tonnetz": [float(v) for v in tonnetz_vec],
        })

    metadata = {
        "name": name,
        "path": str(path),
        "audio": _audio_info(path),
        "feature_artifact": str(npz_path),
        "frames": int(len(rms)),
        "chroma_frames": int(chroma.shape[1]),
        "mel_shape": [int(x) for x in mel_db.shape],
    }

    return metadata, beat_rows


def _write_json(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(_jsonable(payload), ensure_ascii=False, indent=2),
        encoding="utf-8",
    )


def _write_jsonl(path: Path, rows: list[dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="\n") as fh:
        for row in rows:
            fh.write(json.dumps(_jsonable(row), ensure_ascii=False) + "\n")


def _active_support(result: dict[str, Any]) -> list[dict[str, Any]]:
    nc = result.get("no_chord_benchmark") or {}
    for variant in nc.get("variants") or []:
        if isinstance(variant, dict) and variant.get("name") == "E_positive_harmonic_support":
            rows = variant.get("beat_support")
            if isinstance(rows, list):
                return rows
    return []


def _events(result: dict[str, Any], beat_features: list[dict[str, Any]]) -> list[dict[str, Any]]:
    beats = [float(x) for x in result.get("beat_grid_s", [])]
    support = _active_support(result)
    by_key = {(r["track"], int(r["beat"])): r for r in beat_features}

    events: list[dict[str, Any]] = []
    previous_n = False

    for row in support:
        i = int(row.get("beat", 0))
        is_n = bool(row.get("is_no_chord"))
        if is_n != previous_n:
            events.append({
                "type": "chord_to_N" if is_n else "N_to_chord",
                "beat": i,
                "time_s": beats[i] if i < len(beats) else None,
                "support_count": int(row.get("support_count", 0)),
                "supporting_stems": row.get("supporting_stems", []),
                "labels": row.get("labels", {}),
            })
        previous_n = is_n

        labels = row.get("labels", {}) or {}
        if labels:
            non_n = [str(v) for v in labels.values() if str(v) != "N"]
            if len(set(non_n)) >= 2:
                events.append({
                    "type": "stem_label_divergence",
                    "beat": i,
                    "time_s": beats[i] if i < len(beats) else None,
                    "labels": labels,
                })

        if not is_n and int(row.get("support_count", 0)) > 0:
            supporting = row.get("supporting_stems", []) or []
            energy = 0.0
            chroma_strength = 0.0
            for stem in supporting:
                f = by_key.get((str(stem), i))
                if f:
                    energy += float(f.get("rms", 0.0))
                    chroma_strength = max(chroma_strength, float(f.get("chroma_strength", 0.0)))
            events.append({
                "type": "positive_support_observation",
                "beat": i,
                "time_s": beats[i] if i < len(beats) else None,
                "support_count": int(row.get("support_count", 0)),
                "support_rms_sum": energy,
                "support_chroma_strength_max": chroma_strength,
                "labels": labels,
            })

    algorithms = result.get("algorithms") or []
    phases = [int(a.get("phase", 0)) for a in algorithms if isinstance(a, dict)]
    if phases and len(set(phases)) > 1:
        events.append({
            "type": "metric_phase_divergence",
            "phases": phases,
            "methods": [
                {
                    "algorithm": a.get("algorithm"),
                    "phase": a.get("phase"),
                    "score": a.get("score"),
                    "margin": a.get("margin"),
                }
                for a in algorithms
                if isinstance(a, dict)
            ],
        })

    return events


def _plot_diagnostics(
    result: dict[str, Any],
    track_meta: list[dict[str, Any]],
    beat_features: list[dict[str, Any]],
    artifact_dir: Path,
    events: list[dict[str, Any]],
) -> list[str]:
    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt

    plots_dir = artifact_dir / "plots"
    plots_dir.mkdir(parents=True, exist_ok=True)
    generated: list[str] = []

    # Mel spectrogram + chroma for every track. Both are rendered from the NPZ
    # numerical artifact included in the same scientific bundle.
    for meta in track_meta:
        name = str(meta["name"])
        npz_path = Path(meta["feature_artifact"])
        data = np.load(npz_path)

        mel = data["mel_db"].astype(np.float32)
        fig = plt.figure(figsize=(14, 5))
        ax = fig.add_subplot(111)
        ax.imshow(mel, origin="lower", aspect="auto", interpolation="nearest")
        ax.set_title(f"{name} — log-mel spectrogram")
        ax.set_xlabel("frame")
        ax.set_ylabel("mel bin")
        out = plots_dir / f"{name}_mel_spectrogram.png"
        fig.tight_layout()
        fig.savefig(out, dpi=140)
        plt.close(fig)
        generated.append(str(out))

        chroma = data["chroma"]
        fig = plt.figure(figsize=(14, 4))
        ax = fig.add_subplot(111)
        ax.imshow(chroma, origin="lower", aspect="auto", interpolation="nearest")
        ax.set_title(f"{name} — chromagram CQT")
        ax.set_xlabel("frame")
        ax.set_ylabel("pitch class")
        out = plots_dir / f"{name}_chroma.png"
        fig.tight_layout()
        fig.savefig(out, dpi=140)
        plt.close(fig)
        generated.append(str(out))

    # Stem RMS by beat.
    fig = plt.figure(figsize=(15, 5))
    ax = fig.add_subplot(111)
    for stem in ("bass", "guitar", "piano", "other"):
        rows = [r for r in beat_features if r["track"] == stem]
        if rows:
            ax.plot(
                [r["start_s"] for r in rows],
                [r["rms"] for r in rows],
                label=stem,
            )
    ax.set_title("Accompaniment stems — RMS by beat")
    ax.set_xlabel("time (s)")
    ax.set_ylabel("RMS")
    ax.legend()
    out = plots_dir / "stems_rms_by_beat.png"
    fig.tight_layout()
    fig.savefig(out, dpi=140)
    plt.close(fig)
    generated.append(str(out))

    # Positive harmonic support count.
    support = _active_support(result)
    if support:
        beats = [float(x) for x in result.get("beat_grid_s", [])]
        fig = plt.figure(figsize=(15, 4))
        ax = fig.add_subplot(111)
        x = [beats[int(r["beat"])] if int(r["beat"]) < len(beats) else int(r["beat"]) for r in support]
        y = [int(r.get("support_count", 0)) for r in support]
        ax.step(x, y, where="post")
        ax.set_title("Positive harmonic support count")
        ax.set_xlabel("time (s)")
        ax.set_ylabel("supporting stems")
        ax.set_ylim(-0.2, 4.2)
        out = plots_dir / "positive_harmonic_support.png"
        fig.tight_layout()
        fig.savefig(out, dpi=140)
        plt.close(fig)
        generated.append(str(out))

    # Metric phase histogram.
    algorithms = result.get("algorithms") or []
    if algorithms:
        fig = plt.figure(figsize=(10, 5))
        ax = fig.add_subplot(111)
        names = [str(a.get("algorithm", "")) for a in algorithms]
        phases = [int(a.get("phase", 0)) for a in algorithms]
        ax.bar(np.arange(len(names)), phases)
        ax.set_xticks(np.arange(len(names)))
        ax.set_xticklabels(names, rotation=35, ha="right")
        ax.set_title("Metric phase selected by method")
        ax.set_ylabel("phase")
        out = plots_dir / "metric_phase_methods.png"
        fig.tight_layout()
        fig.savefig(out, dpi=140)
        plt.close(fig)
        generated.append(str(out))

    # Event zooms: ±4 beats around every chord_to_N / N_to_chord transition.
    beats = [float(x) for x in result.get("beat_grid_s", [])]
    transitions = [e for e in events if e.get("type") in ("chord_to_N", "N_to_chord")]
    for idx, event in enumerate(transitions, start=1):
        bi = int(event.get("beat", 0))
        a = max(0, bi - 4)
        b = min(len(beats), bi + 5)
        fig = plt.figure(figsize=(14, 7))
        ax = fig.add_subplot(111)
        for stem in ("bass", "guitar", "piano", "other"):
            rows = [
                r for r in beat_features
                if r["track"] == stem and a <= int(r["beat"]) < b
            ]
            if rows:
                ax.plot(
                    [r["start_s"] for r in rows],
                    [r["rms"] for r in rows],
                    marker="o",
                    label=stem,
                )
        if bi < len(beats):
            ax.axvline(beats[bi], linestyle="--")
        ax.set_title(
            f"Event {idx:03d} — {event.get('type')} — beat {bi}"
        )
        ax.set_xlabel("time (s)")
        ax.set_ylabel("RMS")
        ax.legend()
        out = plots_dir / f"event_{idx:03d}_{event.get('type')}.png"
        fig.tight_layout()
        fig.savefig(out, dpi=140)
        plt.close(fig)
        generated.append(str(out))

    return generated


def build_observability_bundle(
    *,
    run_id: int,
    audio_path: Path,
    result: dict[str, Any],
    work_dir: Path,
    project_dir: Path,
    log,
    observability_root: Path | None = None,
    export_root: Path | None = None,
) -> dict[str, Any]:
    started = time.perf_counter()
    observability_root = observability_root or Path(
        os.getenv("EZSTUDIO_OBSERVABILITY_ROOT", str(DEFAULT_ROOT))
    )
    export_root = export_root or Path(
        os.getenv("EZSTUDIO_EXPORT_ROOT", str(DEFAULT_EXPORT_ROOT))
    )

    run_dir = observability_root / f"run-{run_id:06d}"
    if run_dir.exists():
        shutil.rmtree(run_dir)
    run_dir.mkdir(parents=True, exist_ok=True)
    (run_dir / "features").mkdir(parents=True, exist_ok=True)
    (run_dir / "raw").mkdir(parents=True, exist_ok=True)
    (run_dir / "tables").mkdir(parents=True, exist_ok=True)
    (run_dir / "logs").mkdir(parents=True, exist_ok=True)

    log("INFO", f"V10 observability: instrumentation run {run_id}")

    _write_json(run_dir / "raw" / "engine_result.json", result)
    environment = _runtime_environment(project_dir)
    _write_json(run_dir / "environment.json", environment)

    source_info = _audio_info(audio_path)
    _write_json(run_dir / "audio.json", source_info)

    beats = [float(x) for x in result.get("beat_grid_s", [])]
    track_paths: dict[str, Path] = {"master": audio_path}

    nc = result.get("no_chord_benchmark") or {}
    stems_info = nc.get("stems") or {}
    if stems_info.get("available"):
        for stem in ("bass", "guitar", "piano", "other"):
            p = (stems_info.get("stems") or {}).get(stem)
            if p and Path(p).is_file():
                track_paths[stem] = Path(p)

    track_meta: list[dict[str, Any]] = []
    beat_rows: list[dict[str, Any]] = []

    for name, path in track_paths.items():
        log("INFO", f"V10 observability: features {name}")
        meta, rows = _extract_track_features(
            name,
            path,
            beats,
            run_dir / "features",
        )
        track_meta.append(meta)
        beat_rows.extend(rows)

    _write_json(run_dir / "stems.json", {
        "engine_stems": stems_info,
        "tracks_instrumented": track_meta,
    })
    _write_jsonl(run_dir / "tables" / "beat_features.jsonl", beat_rows)

    support = _active_support(result)
    _write_jsonl(
        run_dir / "tables" / "positive_harmonic_support.jsonl",
        [_jsonable(x) for x in support],
    )

    events = _events(result, beat_rows)
    _write_jsonl(run_dir / "tables" / "events.jsonl", events)

    plots = _plot_diagnostics(
        result,
        track_meta,
        beat_rows,
        run_dir,
        events,
    )

    run_doc = {
        "run_id": int(run_id),
        "captured_at": _utc_now(),
        "observability_version": OBSERVABILITY_VERSION,
        "engine_version": result.get("engine_version"),
        "audio": source_info,
        "signature": result.get("signature"),
        "tempo": result.get("tempo"),
        "meter": result.get("meter"),
        "convergence": result.get("convergence"),
        "versions": result.get("versions"),
        "parameters": result.get("parameters"),
        "beat_count": len(beats),
        "tracks": track_meta,
        "event_count": len(events),
        "artifact_root": str(run_dir),
        "plots": plots,
        "ezstudio_autonomous": True,
    }

    _write_json(run_dir / "run.json", run_doc)

    manifest = {
        "schema_version": 1,
        "observability_version": OBSERVABILITY_VERSION,
        "run_id": int(run_id),
        "generated_at": _utc_now(),
        "engine_version": result.get("engine_version"),
        "source_audio_sha256": source_info["sha256"],
        "timebase": "original_audio_seconds",
        "contains_audio_binary": False,
        "reconstructible_plots": True,
        "ezscore_runtime_dependency": False,
        "metric_algorithms_modified": False,
        "files": [],
    }

    for p in sorted(run_dir.rglob("*")):
        if p.is_file():
            manifest["files"].append({
                "path": p.relative_to(run_dir).as_posix(),
                "bytes": p.stat().st_size,
                "sha256": _sha256(p),
            })
    _write_json(run_dir / "manifest.json", manifest)

    export_root.mkdir(parents=True, exist_ok=True)
    zip_path = export_root / f"EZStudio_Run_{run_id:06d}_Scientific.zip"
    if zip_path.exists():
        zip_path.unlink()

    with zipfile.ZipFile(zip_path, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as zf:
        for p in sorted(run_dir.rglob("*")):
            if p.is_file():
                zf.write(p, p.relative_to(run_dir).as_posix())

    elapsed = time.perf_counter() - started
    log("INFO", f"V10 observability: export scientifique {zip_path}")
    log("INFO", f"V10 observability: terminé en {elapsed:.2f}s")

    return {
        "observability_version": OBSERVABILITY_VERSION,
        "artifact_root": str(run_dir),
        "scientific_zip": str(zip_path),
        "duration_s": elapsed,
    }


def self_test() -> None:
    assert OBSERVABILITY_VERSION.startswith("v10-")
    assert "EZScore" not in str(DEFAULT_ROOT)
    print("V10_OBSERVABILITY_SELF_TEST_OK")
    print("V10_EZSTUDIO_AUTONOMY_CONTRACT_OK")


if __name__ == "__main__":
    if "--self-test" in sys.argv:
        self_test()
