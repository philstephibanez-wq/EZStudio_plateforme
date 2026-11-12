#!/usr/bin/env python3
"""EZStudio_lab — autonomous stem separation pipeline.

Scope:
- BS-RoFormer: vocals, drums, bass, guitar, piano, other
- MelBand-RoFormer karaoke model: lead vocal + backing vocals from vocals

This module belongs to EZStudio_lab and has no runtime dependency on EZScore.
Its persisted manifest remains compatible with the canonical stem names and
original-audio timebase expected by downstream EZScore integration contracts.
"""

from __future__ import annotations

import argparse
import importlib.util
import json
import os
import shutil
import subprocess
import queue
import re
import threading
import sys
import tempfile
import time
import uuid
from datetime import datetime, timezone
from pathlib import Path

BS_MODEL = "roformer-model-bs-roformer-sw-by-jarredou"
KARAOKE_MODEL = "roformer-model-melband-roformer-karaoke-by-becruily"
RAW_STEMS = ("vocals", "drums", "bass", "guitar", "piano", "other")
FINAL_STEMS = (
    "lead_vocals",
    "backing_vocals",
    "drums",
    "bass",
    "guitar",
    "piano",
    "other",
)

def _write_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_name(
        f".{path.name}.{os.getpid()}.{threading.get_ident()}.{uuid.uuid4().hex}.tmp"
    )
    tmp.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    delay = 0.02
    try:
        for attempt in range(8):
            try:
                os.replace(tmp, path)
                return
            except (PermissionError, OSError):
                if attempt >= 7:
                    raise
                time.sleep(delay)
                delay = min(delay * 2.0, 0.25)
    finally:
        try:
            tmp.unlink(missing_ok=True)
        except OSError:
            pass

def _log_perf(log_path: Path, phase: str, seconds: float, **extra: object) -> None:
    details = " ".join(f"{key}={value}" for key, value in extra.items())
    suffix = f" {details}" if details else ""
    with log_path.open("a", encoding="utf-8", errors="replace") as log:
        log.write(f"\n[PERF] phase={phase} duration_seconds={seconds:.3f}{suffix}\n")

def _progress(
    path: Path,
    percent: int,
    stage: str,
    message: str,
    *,
    engine_percent: int | None = None,
    elapsed_seconds: float | None = None,
) -> None:
    payload = {
        "percent": max(0, min(100, int(percent))),
        "stage": stage,
        "message": message,
        "updated_at": datetime.now(timezone.utc).isoformat(),
    }
    if engine_percent is not None:
        payload["engine_percent"] = max(0, min(100, int(engine_percent)))
    if elapsed_seconds is not None:
        payload["elapsed_seconds"] = round(float(elapsed_seconds), 1)
    _write_json(path, payload)

def _run(command: list[str], *, label: str, timeout: int, log_path: Path) -> None:
    env = os.environ.copy()
    env["PYTHONUTF8"] = "1"
    env["PYTHONIOENCODING"] = "utf-8"
    with log_path.open("a", encoding="utf-8", errors="replace") as log:
        log.write(f"\n[{datetime.now(timezone.utc).isoformat()}] {label}\n")
        log.write("COMMAND: " + " ".join(command) + "\n")
        log.flush()
        proc = subprocess.run(
            command,
            stdout=log,
            stderr=subprocess.STDOUT,
            text=True,
            encoding="utf-8",
            errors="replace",
            env=env,
            timeout=timeout,
            check=False,
        )
    if proc.returncode != 0:
        raise RuntimeError(f"{label} failed with exit code {proc.returncode}; see {log_path}")

_PERCENT_RE = re.compile(r"(?<!\d)(100|\d{1,2})\s*%")

def _run_tracked(
    command: list[str],
    *,
    label: str,
    timeout: int,
    log_path: Path,
    progress_path: Path,
    stage: str,
    message: str,
    overall_start: int,
    overall_end: int,
) -> None:
    env = os.environ.copy()
    env["PYTHONUTF8"] = "1"
    env["PYTHONIOENCODING"] = "utf-8"
    output_queue: queue.Queue[str | None] = queue.Queue()
    started = time.monotonic()
    last_engine_percent: int | None = None
    last_overall = int(overall_start)

    with log_path.open("a", encoding="utf-8", errors="replace") as log:
        log.write(f"\n[{datetime.now(timezone.utc).isoformat()}] {label}\n")
        log.write("COMMAND: " + " ".join(command) + "\n")
        log.flush()
        proc = subprocess.Popen(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            encoding="utf-8",
            errors="replace",
            env=env,
            bufsize=1,
        )

        def _reader() -> None:
            assert proc.stdout is not None
            pending = ""
            try:
                while True:
                    chunk = proc.stdout.read(1)
                    if chunk == "":
                        if pending:
                            output_queue.put(pending)
                        break
                    if chunk in {"\r", "\n"}:
                        if pending:
                            output_queue.put(pending + chunk)
                            pending = ""
                        continue
                    pending += chunk
            finally:
                output_queue.put(None)

        reader = threading.Thread(target=_reader, daemon=True)
        reader.start()
        deadline = started + float(timeout)
        reader_done = False
        last_heartbeat = 0.0

        while True:
            now = time.monotonic()
            if now >= deadline and proc.poll() is None:
                proc.kill()
                raise TimeoutError(f"{label} timed out after {timeout} seconds.")

            drained = False
            while True:
                try:
                    item = output_queue.get_nowait()
                except queue.Empty:
                    break
                drained = True
                if item is None:
                    reader_done = True
                    break
                log.write(item)
                log.flush()
                for match in _PERCENT_RE.finditer(item):
                    engine_percent = max(0, min(100, int(match.group(1))))
                    last_engine_percent = engine_percent
                    span = max(0, int(overall_end) - int(overall_start))
                    mapped = int(overall_start) + round(span * engine_percent / 100)
                    last_overall = max(last_overall, min(int(overall_end), mapped))
                    _progress(
                        progress_path,
                        last_overall,
                        stage,
                        message,
                        engine_percent=engine_percent,
                        elapsed_seconds=time.monotonic() - started,
                    )

            if proc.poll() is not None and reader_done:
                break

            if now - last_heartbeat >= 2.0:
                _progress(
                    progress_path,
                    last_overall,
                    stage,
                    message,
                    engine_percent=last_engine_percent,
                    elapsed_seconds=now - started,
                )
                last_heartbeat = now

            if not drained:
                time.sleep(0.20)

        return_code = proc.wait()
        reader.join(timeout=1.0)

    if return_code != 0:
        raise RuntimeError(f"{label} failed with exit code {return_code}; see {log_path}")

    _progress(
        progress_path,
        overall_end,
        stage,
        message,
        engine_percent=100,
        elapsed_seconds=time.monotonic() - started,
    )

def _which_ffmpeg() -> str:
    ffmpeg = shutil.which("ffmpeg")
    if not ffmpeg:
        raise RuntimeError("FFmpeg is required and was not found in PATH.")
    return ffmpeg

def _probe_duration_seconds(source: Path) -> float:
    ffprobe = shutil.which("ffprobe")
    if not ffprobe:
        raise RuntimeError("FFprobe is required and was not found in PATH.")
    proc = subprocess.run(
        [
            ffprobe,
            "-v", "error",
            "-show_entries", "format=duration",
            "-of", "default=noprint_wrappers=1:nokey=1",
            str(source),
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
        check=False,
    )
    if proc.returncode != 0:
        raise RuntimeError(f"FFprobe failed: {proc.stderr.strip()}")
    return float(proc.stdout.strip())

def _module_required(module_name: str, package_label: str) -> None:
    if importlib.util.find_spec(module_name) is None:
        raise RuntimeError(
            f"{package_label} is not installed in this Python environment "
            f"(missing module {module_name!r})."
        )

def _model_root(env_name: str, fallback: Path | None = None) -> Path:
    value = str(os.getenv(env_name, "") or "").strip()
    root = Path(value).expanduser() if value else fallback
    if root is None:
        raise RuntimeError(f"{env_name} must be configured.")
    if root.drive and root.drive.upper() == "C:":
        raise RuntimeError(f"{env_name} must not point to C:.")
    root.mkdir(parents=True, exist_ok=True)
    return root

def _runtime_tmp(bs_root: Path) -> Path:
    value = str(os.getenv("EZSTUDIO_RUNTIME_TMP", "") or "").strip()
    root = Path(value).expanduser() if value else bs_root.parent / "ezstudio-tmp"
    root.mkdir(parents=True, exist_ok=True)
    return root

def _pick_wav(output_dir: Path, target: str, source_stem: str | None = None) -> Path:
    target_low = target.casefold()
    source_low = (source_stem or "").casefold()
    candidates: list[tuple[int, int, Path]] = []
    for path in output_dir.rglob("*.wav"):
        name = path.stem.casefold()
        score = 0
        if name == target_low:
            score += 20
        if name.endswith("_" + target_low) or name.endswith("-" + target_low):
            score += 12
        if name.startswith(target_low + "_") or name.startswith(target_low + "-"):
            score += 8
        if target_low in name:
            score += 4
        if source_low and source_low in name:
            score += 2
        if score > 0:
            candidates.append((score, path.stat().st_size, path))
    if not candidates:
        produced = ", ".join(sorted(p.name for p in output_dir.rglob("*.wav")))
        raise RuntimeError(
            f"Stem {target!r} not found in {output_dir}. Produced WAV: {produced or 'none'}"
        )
    candidates.sort(key=lambda item: (item[0], item[1]), reverse=True)
    return candidates[0][2]

def _pick_karaoke_output(output_dir: Path, output_id: str) -> Path:
    wanted = output_id.casefold()
    candidates = sorted(
        p for p in output_dir.rglob("*.wav")
        if p.stem.casefold().endswith("_" + wanted)
        or p.stem.casefold() == wanted
    )
    if len(candidates) != 1:
        produced = ", ".join(sorted(p.name for p in output_dir.rglob("*.wav")))
        raise RuntimeError(
            f"Karaoke output {output_id!r} is missing/ambiguous. Produced WAV: {produced or 'none'}"
        )
    return candidates[0]

def _prepare_bs_model(model_root: Path, timeout: int, log_path: Path) -> tuple[str, Path, Path]:
    from bs_roformer import MODEL_REGISTRY
    try:
        entry = MODEL_REGISTRY.get(BS_MODEL)
    except KeyError as exc:
        raise RuntimeError(f"Unknown BS-RoFormer model: {BS_MODEL}") from exc
    model_dir = model_root / entry.slug
    config_path = model_dir / entry.config
    checkpoint_path = model_dir / entry.checkpoint
    if not config_path.is_file() or not checkpoint_path.is_file():
        _run(
            [
                sys.executable, "-m", "bs_roformer.download",
                "--model", entry.slug,
                "--output-dir", str(model_root),
            ],
            label="BS-RoFormer model download",
            timeout=max(timeout, 1800),
            log_path=log_path,
        )
    if not config_path.is_file() or not checkpoint_path.is_file():
        raise RuntimeError("BS-RoFormer model files are still missing after download.")
    return entry.slug, config_path, checkpoint_path

def _prepare_karaoke_model(model_root: Path, timeout: int, log_path: Path) -> str:
    from mel_band_roformer import MODEL_REGISTRY
    try:
        entry = MODEL_REGISTRY.get(KARAOKE_MODEL)
    except KeyError as exc:
        available = []
        try:
            available = [item.slug for item in MODEL_REGISTRY.list("karaoke")]
        except Exception:
            pass
        raise RuntimeError(
            f"Unknown MelBand karaoke model: {KARAOKE_MODEL}. "
            f"Available: {', '.join(available) or 'unknown'}"
        ) from exc
    expected_dir = model_root / entry.slug
    if not expected_dir.exists():
        _run(
            [
                sys.executable, "-m", "mel_band_roformer.download",
                "--model", entry.slug,
                "--output-dir", str(model_root),
            ],
            label="MelBand-RoFormer model download",
            timeout=max(timeout, 1800),
            log_path=log_path,
        )
    return entry.slug

def _cleanup_old_runs(runs_dir: Path, keep: int = 2) -> None:
    runs = sorted(
        (p for p in runs_dir.iterdir() if p.is_dir() and p.name.startswith("run-")),
        key=lambda p: p.stat().st_mtime,
        reverse=True,
    )
    for old in runs[keep:]:
        shutil.rmtree(old, ignore_errors=True)

def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", required=True)
    parser.add_argument("--audio-hash", required=True)
    parser.add_argument("--storage-root", required=True)
    parser.add_argument("--progress-file", required=True)
    parser.add_argument("--force", action="store_true")
    parser.add_argument("--timeout", type=int, default=7200)
    args = parser.parse_args()

    source = Path(args.source).resolve()
    storage_root = Path(args.storage_root).resolve()
    progress_file = Path(args.progress_file).resolve()
    timeout = max(1800, int(args.timeout))

    if not source.is_file() or source.stat().st_size <= 0:
        raise RuntimeError(f"Invalid source audio: {source}")

    _module_required("bs_roformer", "bs-roformer-infer")
    _module_required("mel_band_roformer", "melband-roformer-infer")

    bs_root = _model_root("BS_ROFORMER_MODELS_PATH")
    karaoke_root = _model_root(
        "MELBAND_ROFORMER_MODELS_PATH",
        fallback=bs_root.parent / "melband-roformer",
    )
    runtime_tmp = _runtime_tmp(bs_root)
    device = str(os.getenv("EZSTUDIO_STEM_DEVICE", "cuda:0") or "cuda:0").strip()

    storage_root.mkdir(parents=True, exist_ok=True)
    runs_dir = storage_root / "runs"
    runs_dir.mkdir(parents=True, exist_ok=True)
    current_pointer = storage_root / "current.json"
    log_path = storage_root / "worker.log"

    if current_pointer.is_file() and not args.force:
        _progress(progress_file, 100, "cached", "Stems already available.")
        return 0

    run_name = "run-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
    final_run = runs_dir / run_name
    staging = Path(tempfile.mkdtemp(prefix="ezstudio-stems-", dir=str(runtime_tmp)))

    started = time.monotonic()
    phase_durations: dict[str, float] = {}

    try:
        _progress(progress_file, 3, "prepare", "Preparing audio source.")

        input_dir = staging / "input"
        bs_output = staging / "bs_output"
        karaoke_input = staging / "karaoke_input"
        karaoke_output = staging / "karaoke_output"
        payload = staging / "payload"

        for directory in (input_dir, bs_output, karaoke_input, karaoke_output, payload):
            directory.mkdir(parents=True, exist_ok=True)

        source_wav = input_dir / "source.wav"
        phase_started = time.monotonic()
        _run(
            [
                _which_ffmpeg(),
                "-hide_banner", "-loglevel", "error", "-y",
                "-i", str(source),
                "-vn",
                "-acodec", "pcm_f32le",
                str(source_wav),
            ],
            label="FFmpeg normalization",
            timeout=min(timeout, 900),
            log_path=log_path,
        )
        phase_durations["normalize"] = time.monotonic() - phase_started
        _log_perf(log_path, "normalize", phase_durations["normalize"])

        _progress(progress_file, 10, "models", "Checking separation models.")
        phase_started = time.monotonic()
        bs_slug, bs_config, bs_checkpoint = _prepare_bs_model(bs_root, timeout, log_path)
        phase_durations["bs_model_prepare"] = time.monotonic() - phase_started
        _log_perf(log_path, "bs_model_prepare", phase_durations["bs_model_prepare"])

        _progress(progress_file, 18, "separation", "Separating instrumental stems.")
        phase_started = time.monotonic()
        _run_tracked(
            [
                sys.executable, "-m", "bs_roformer.inference",
                "--config_path", str(bs_config),
                "--model_path", str(bs_checkpoint),
                "--input_folder", str(input_dir),
                "--store_dir", str(bs_output),
                "--device", device,
            ],
            label="BS-RoFormer 6-stem separation",
            timeout=timeout,
            log_path=log_path,
            progress_path=progress_file,
            stage="separation",
            message="Separating instrumental stems.",
            overall_start=18,
            overall_end=70,
        )
        phase_durations["bs_separation"] = time.monotonic() - phase_started
        _log_perf(
            log_path, "bs_separation", phase_durations["bs_separation"],
            device=device, model=bs_slug
        )

        located = {
            name: _pick_wav(bs_output, name, source_wav.stem)
            for name in RAW_STEMS
        }

        _progress(progress_file, 72, "vocals", "Separating lead vocal and backing vocals.")
        phase_started = time.monotonic()
        karaoke_slug = _prepare_karaoke_model(karaoke_root, timeout, log_path)
        phase_durations["karaoke_model_prepare"] = time.monotonic() - phase_started
        _log_perf(log_path, "karaoke_model_prepare", phase_durations["karaoke_model_prepare"])
        shutil.copy2(located["vocals"], karaoke_input / "vocals.wav")

        phase_started = time.monotonic()
        _run_tracked(
            [
                sys.executable, "-m", "mel_band_roformer.inference",
                "--model", karaoke_slug,
                "--models_dir", str(karaoke_root),
                "--input_folder", str(karaoke_input),
                "--store_dir", str(karaoke_output),
                "--device", device,
            ],
            label="MelBand-RoFormer lead/backing separation",
            timeout=timeout,
            log_path=log_path,
            progress_path=progress_file,
            stage="vocals",
            message="Separating lead vocal and backing vocals.",
            overall_start=72,
            overall_end=89,
        )
        phase_durations["vocal_separation"] = time.monotonic() - phase_started
        _log_perf(
            log_path, "vocal_separation", phase_durations["vocal_separation"],
            device=device, model=karaoke_slug
        )

        lead = _pick_karaoke_output(karaoke_output, "vocals")
        backing = _pick_karaoke_output(karaoke_output, "instrumental")

        _progress(progress_file, 90, "persist", "Persisting stems.")
        phase_started = time.monotonic()

        for name in RAW_STEMS:
            shutil.copy2(located[name], payload / f"{name}.wav")
        shutil.copy2(lead, payload / "lead_vocals.wav")
        shutil.copy2(backing, payload / "backing_vocals.wav")
        (payload / "vocals.wav").unlink(missing_ok=True)

        for name in FINAL_STEMS:
            target = payload / f"{name}.wav"
            if not target.is_file() or target.stat().st_size <= 0:
                raise RuntimeError(f"Invalid final stem: {name}")

        audio_duration = _probe_duration_seconds(source)
        elapsed = time.monotonic() - started
        manifest = {
            "schema": "ezstudio.stems",
            "schema_version": 1,
            "scope": "stems_only",
            "audio_hash": str(args.audio_hash),
            "timebase": "original_audio_seconds",
            "generated_at": datetime.now(timezone.utc).isoformat(),
            # Compatibility field: now correctly means AUDIO duration.
            "duration_seconds": round(audio_duration, 6),
            "analysis_elapsed_seconds": round(elapsed, 3),
            "phase_durations_seconds": {
                name: round(seconds, 3)
                for name, seconds in phase_durations.items()
            },
            "device": device,
            "engines": {
                "instrumental": {
                    "engine": "bs-roformer",
                    "model": bs_slug,
                },
                "vocal_split": {
                    "engine": "melband-roformer",
                    "model": karaoke_slug,
                },
            },
            "stems": {
                name: {
                    "file": f"{name}.wav",
                    "bytes": (payload / f"{name}.wav").stat().st_size,
                }
                for name in FINAL_STEMS
            },
            "excluded_analysis": [
                "lyrics", "phonemes", "chords", "tempo", "beats",
                "measures", "sections", "midi", "karaoke",
            ],
        }
        _write_json(payload / "manifest.json", manifest)

        payload.replace(final_run)
        _write_json(current_pointer, {"run": run_name})
        _cleanup_old_runs(runs_dir, keep=2)
        phase_durations["persist_publish"] = time.monotonic() - phase_started
        _log_perf(log_path, "persist_publish", phase_durations["persist_publish"])
        _log_perf(log_path, "stems_total", time.monotonic() - started)

        _progress(
            progress_file, 100, "stems_complete",
            "EZStudio_lab stem separation completed."
        )
        return 0

    except Exception as exc:
        _progress(progress_file, 100, "error", str(exc))
        with log_path.open("a", encoding="utf-8", errors="replace") as log:
            log.write(f"\nERROR: {exc!r}\n")
        raise
    finally:
        shutil.rmtree(staging, ignore_errors=True)

if __name__ == "__main__":
    raise SystemExit(main())
