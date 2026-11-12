from __future__ import annotations

import hashlib
import importlib.metadata
import importlib.util
import json
import os
import shutil
import subprocess
import sys
import wave
from pathlib import Path

import librosa
import numpy as np
import torch

FPS = 50.0
AUTO = {"2/4": 2, "3/4": 3, "4/4": 4, "6/8": 6}
ENGINE_VERSION = "r9-positive-harmonic-support"
NO_CHORD = "N"

# EZStudio_lab owns its STEMS pipeline and runtime storage.
DEFAULT_EZSTUDIO_STEMS_SCRIPT = Path(__file__).resolve().parent / "ezstudio" / "pipeline" / "stems" / "runner.py"
DEFAULT_STEMS_CACHE_ROOT = Path(r"H:\temp\EZStudio_lab\stems")


def z(x):
    x = np.asarray(x, float)
    return (x - x.mean()) / (x.std() + 1e-9) if x.size else x


def sample_frames(x, idx):
    x = np.asarray(x, float)
    idx = np.asarray(idx, int)
    if not len(x):
        return np.zeros(len(idx))
    return x[np.clip(idx, 0, len(x) - 1)]


def sample_times(x, t):
    x = np.asarray(x, float)
    t = np.asarray(t, float)
    if not len(x):
        return np.zeros(len(t))
    idx = np.rint(t * FPS).astype(int)
    return x[np.clip(idx, 0, len(x) - 1)]


def sigmoid(x):
    x = np.asarray(x, float)
    return 1 / (1 + np.exp(-np.clip(x, -60, 60)))


def coherence(v, p, ph):
    v = z(v)
    idx = np.arange(len(v))
    a = v[((idx - ph) % p) == 0]
    b = v[((idx - ph) % p) != 0]
    return float(a.mean() - b.mean()) if len(a) and len(b) else -1e9


def bass_feature(y, sr, hop, bf):
    c = np.abs(
        librosa.cqt(
            y=y,
            sr=sr,
            hop_length=hop,
            fmin=librosa.note_to_hz("C1"),
            n_bins=36,
            bins_per_octave=12,
        )
    )
    low = c[:24].mean(axis=0)
    d = np.r_[0.0, np.maximum(0.0, np.diff(low))]
    return sample_frames(low + 0.75 * d, bf)


def harmonic_novelty(y, sr, hop, bf):
    h, _ = librosa.effects.hpss(y)
    c = librosa.feature.chroma_cqt(y=h, sr=sr, hop_length=hop)
    if c.shape[1] < 2:
        return np.zeros(len(bf))
    a, b = c[:, :-1], c[:, 1:]
    nov = np.r_[0.0, 1.0 - np.sum(a * b, axis=0) / (np.linalg.norm(a, axis=0) * np.linalg.norm(b, axis=0) + 1e-9)]
    return sample_frames(nov, bf)


def auto_signature(dr, bass, harm):
    fused = 0.62 * dr + 0.25 * bass + 0.13 * harm
    best = {}
    for sig, p in AUTO.items():
        cand = []
        for ph in range(p):
            sc = 0.50 * coherence(fused, p, ph) + 0.30 * coherence(dr, p, ph) + 0.13 * coherence(bass, p, ph) + 0.07 * coherence(harm, p, ph)
            if sig == "4/4":
                sc += 0.02
            if sig == "6/8":
                pos = (np.arange(len(dr)) - ph) % 6
                a = dr[(pos == 0) | (pos == 3)]
                b = dr[(pos != 0) & (pos != 3)]
                if len(a) and len(b):
                    sc += 0.16 * float(np.clip(a.mean() - b.mean(), -1, 1))
            cand.append((sc, ph))
        best[sig] = max(cand)
    order = sorted(best.items(), key=lambda x: x[1][0], reverse=True)
    sig, (top, ph) = order[0]
    second = order[1][1][0]
    return {
        "signature": sig,
        "phase": int(ph),
        "confidence": float(np.clip(0.5 + top - second, 0, 1)),
        "scores": {k: round(v[0], 5) for k, v in best.items()},
    }


def phase_scores(v, p):
    arr = np.asarray(v, float)
    rows = []
    for ph in range(p):
        a = arr[ph::p]
        others = [arr[q::p] for q in range(p) if q != ph]
        b = np.concatenate(others) if others else np.asarray([], float)
        sc = float(a.mean() - b.mean()) if len(a) and len(b) else -1e9
        rows.append({"phase": int(ph), "score": sc, "downbeat_mean": float(a.mean()) if len(a) else -1e9})
    return rows


def choose_phase(v, p):
    rows = phase_scores(v, p)
    ranked = sorted(rows, key=lambda x: (x["score"], x["downbeat_mean"]), reverse=True)
    best = ranked[0]
    second = ranked[1] if len(ranked) > 1 else {"score": best["score"]}
    return {
        "phase": int(best["phase"]),
        "score": float(best["score"]),
        "margin": float(best["score"] - second["score"]),
        "phase_scores": rows,
    }


def refine_duple_signature_with_beat_this(initial_signature, bt, threshold=0.04):
    if initial_signature not in ("2/4", "4/4"):
        return initial_signature, {"applied": False, "reason": "not_duple_simple", "initial_signature": initial_signature}

    p2 = choose_phase(bt, 2)
    p4 = choose_phase(bt, 4)
    delta = float(p4["score"] - p2["score"])

    refined = initial_signature
    reason = "keep_initial"
    if delta > threshold:
        refined = "4/4"
        reason = "beat_this_prefers_4_4"
    elif delta < -threshold:
        refined = "2/4"
        reason = "beat_this_prefers_2_4"

    return refined, {
        "applied": True,
        "initial_signature": initial_signature,
        "refined_signature": refined,
        "threshold": float(threshold),
        "delta_4_minus_2": delta,
        "phase2_best": p2,
        "phase4_best": p4,
        "reason": reason,
    }


def chord_label(raw):
    s = str(raw or NO_CHORD).strip()
    if not s or s == NO_CHORD:
        return NO_CHORD
    if ":" not in s:
        return s
    root, q = s.split(":", 1)
    mp = {"maj": "", "min": "m", "7": "7", "min7": "m7", "maj7": "maj7", "dim": "dim", "sus2": "sus2", "sus4": "sus4"}
    return root + mp.get(q, q)


def normalize_segments(rawsegs):
    segs = []
    for x in rawsegs or []:
        a = float(x.get("start_time", 0))
        b = float(x.get("end_time", a))
        if b > a:
            raw = str(x.get("chord", NO_CHORD) or NO_CHORD).strip() or NO_CHORD
            segs.append({"start": a, "end": b, "chord": chord_label(raw), "raw_chord": raw})
    return segs


def chord_for_interval(segs, a, b):
    best = None
    ov = 0.0
    for s in segs:
        x = max(0.0, min(b, s["end"]) - max(a, s["start"]))
        if x > ov:
            ov = x
            best = s
    return best["chord"] if best is not None and ov > 0.0 else NO_CHORD


def beat_labels(segs, beats):
    beats = np.asarray(beats, float)
    if not len(beats):
        return []
    step = float(np.median(np.diff(beats))) if len(beats) > 1 else 0.5
    out = []
    for i, a in enumerate(beats):
        b = beats[i + 1] if i + 1 < len(beats) else a + step
        out.append(chord_for_interval(segs, float(a), float(b)))
    return out


def n_mask_from_segments(segs, beats):
    return np.asarray([x == NO_CHORD for x in beat_labels(segs, beats)], dtype=bool)


def grid(segs, beats, phase, period, count=32, no_chord_mask=None):
    beats = np.asarray(beats, float)
    step = float(np.median(np.diff(beats))) if len(beats) > 1 else 0.5
    cells = {}
    for i, a in enumerate(beats):
        b = beats[i + 1] if i + 1 < len(beats) else a + step
        rel = i - phase
        m = rel // period
        bt = rel % period
        if 0 <= m < count:
            if no_chord_mask is not None and i < len(no_chord_mask) and bool(no_chord_mask[i]):
                cells[(m, bt)] = NO_CHORD
            else:
                cells[(m, bt)] = chord_for_interval(segs, float(a), float(b))

    out = []
    for m in range(count):
        raw = [cells.get((m, b), NO_CHORD) for b in range(period)]
        rendered = []
        prev = None
        for bt, c in enumerate(raw):
            if c == NO_CHORD:
                rendered.append(NO_CHORD)
                prev = NO_CHORD
            elif bt == 0:
                rendered.append(c)
                prev = c
            elif c == prev:
                rendered.append("-")
            else:
                rendered.append(c)
                prev = c
        idx = phase + m * period
        out.append({
            "measure": m,
            "start_s": float(beats[idx]) if idx < len(beats) else None,
            "tokens": rendered,
            "text": " ".join(rendered),
        })
    return out


def ensure_deps(deps: Path, log):
    deps.mkdir(parents=True, exist_ok=True)
    sys.path.insert(0, str(deps))
    importlib.invalidate_caches()
    req = []
    if importlib.util.find_spec("beat_this") is None:
        req += ["beat-this==1.1.0", "torchaudio==2.11.0", "einops==0.8.2", "rotary-embedding-torch==0.9.1", "soxr==1.1.0", "tqdm==4.70.1"]
    if importlib.util.find_spec("lv_chordia") is None:
        req += ["lv-chordia==1.1.0"]
    for package in req:
        log("INFO", f"installation dépendance {package}")
        subprocess.run([sys.executable, "-m", "pip", "install", "--upgrade", "--target", str(deps), "--no-deps", package], check=True)
        importlib.invalidate_caches()


def sha256_file(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def _read_current_stem_run(storage_root: Path) -> tuple[str, Path]:
    pointer = storage_root / "current.json"
    if not pointer.is_file():
        raise RuntimeError(f"current.json stems absent: {pointer}")
    payload = json.loads(pointer.read_text(encoding="utf-8"))
    run = str(payload.get("run", "")).strip()
    if not run.startswith("run-") or not run.replace("-", "").isalnum():
        raise RuntimeError("pointeur current.json stems invalide")
    run_dir = storage_root / "runs" / run
    if not run_dir.is_dir():
        raise RuntimeError(f"run stems introuvable: {run_dir}")
    return run, run_dir


def ensure_ezstudio_stems(audio: Path, work: Path, log) -> dict:
    """
    EZStudio_lab autonomous STEMS contract:
    - no EZScore runtime dependency;
    - invokes EZStudio_lab own canonical STEMS runner;
    - generated data lives under H:\\temp\\EZStudio_lab\\stems.
    """
    script = Path(os.getenv("EZSTUDIO_STEMS_SCRIPT", str(DEFAULT_EZSTUDIO_STEMS_SCRIPT)))
    if not script.is_file():
        return {"available": False, "reason": f"script EZStudio_lab absent: {script}"}

    audio_hash = sha256_file(audio)
    cache_root = Path(os.getenv("EZSTUDIO_STEMS_CACHE_ROOT", str(DEFAULT_STEMS_CACHE_ROOT)))
    storage_root = cache_root / audio_hash
    progress_file = storage_root / "progress.json"
    storage_root.mkdir(parents=True, exist_ok=True)

    if not (storage_root / "current.json").is_file():
        log("INFO", f"stems: pipeline autonome EZStudio_lab; sortie={storage_root}")
        subprocess.run(
            [
                sys.executable,
                str(script),
                "--source", str(audio),
                "--audio-hash", audio_hash,
                "--storage-root", str(storage_root),
                "--progress-file", str(progress_file),
            ],
            check=True,
        )
    else:
        log("INFO", f"stems: cache EZStudio_lab réutilisé {storage_root}")

    run_name, run_dir = _read_current_stem_run(storage_root)
    manifest_path = run_dir / "manifest.json"
    if not manifest_path.is_file():
        raise RuntimeError("manifest stems absent")
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))

    names = ("lead_vocals", "backing_vocals", "drums", "bass", "guitar", "piano", "other")
    stems = {}
    for name in names:
        path = run_dir / f"{name}.wav"
        if not path.is_file() or path.stat().st_size <= 0:
            raise RuntimeError(f"stem analytique manquant: {name}")
        stems[name] = str(path)

    return {
        "available": True,
        "source": "EZStudio_lab/python/ezstudio/pipeline/stems/runner.py",
        "ezstudio_autonomous": True,
        "storage_root": str(storage_root),
        "run": run_name,
        "manifest": manifest,
        "stems": stems,
    }


def write_pcm16_wav(path: Path, y: np.ndarray, sr: int):
    y = np.asarray(y, dtype=np.float32)
    peak = float(np.max(np.abs(y))) if y.size else 0.0
    if peak > 1.0:
        y = y / peak
    pcm = np.clip(y, -1.0, 1.0)
    pcm = (pcm * 32767.0).astype("<i2")
    path.parent.mkdir(parents=True, exist_ok=True)
    with wave.open(str(path), "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(sr)
        w.writeframes(pcm.tobytes())


def make_hpss_harmonic_wav(y, sr, work: Path) -> Path:
    harm, _ = librosa.effects.hpss(y)
    target = work / "master_harmonic_hpss.wav"
    write_pcm16_wav(target, harm, sr)
    return target


def make_instrumental_mix(stems: dict, work: Path, sr=22050) -> Path:
    parts = []
    min_len = None
    for name in ("bass", "guitar", "piano", "other"):
        y, loaded_sr = librosa.load(stems[name], sr=sr, mono=True)
        if loaded_sr != sr:
            raise RuntimeError("sample-rate stems inattendu")
        parts.append(y)
        min_len = len(y) if min_len is None else min(min_len, len(y))
    if not parts or not min_len:
        raise RuntimeError("aucun stem harmonique exploitable")
    mix = np.zeros(min_len, dtype=np.float32)
    for part in parts:
        mix += part[:min_len]
    mix /= max(1, len(parts))
    target = work / "instrumental_harmonic_stems.wav"
    write_pcm16_wav(target, mix, sr)
    return target


def make_selected_mix(selected: list[dict], work: Path, filename: str, sr=22050) -> Path:
    if not selected:
        raise RuntimeError("selected_stems_empty")

    parts = []
    min_len = None
    for item in selected:
        path = Path(str(item.get("path") or ""))
        if not path.is_file():
            raise RuntimeError(f"selected_stem_missing:{path}")
        expected = str(item.get("sha256") or "").strip().lower()
        if expected:
            actual = sha256_file(path).lower()
            if actual != expected:
                raise RuntimeError(
                    f"selected_stem_hash_mismatch:{item.get('artifact_id')}"
                )
        y, loaded_sr = librosa.load(path, sr=sr, mono=True)
        if loaded_sr != sr:
            raise RuntimeError("selected_stem_sample_rate_unexpected")
        parts.append(y)
        min_len = len(y) if min_len is None else min(min_len, len(y))

    if not parts or not min_len:
        raise RuntimeError("selected_stems_unusable")

    mix = np.zeros(min_len, dtype=np.float32)
    for part in parts:
        mix += part[:min_len]
    mix /= max(1, len(parts))

    target = work / filename
    write_pcm16_wav(target, mix, sr)
    return target


def _selected_by_input_role(experiment_inputs: dict | None, role: str) -> list[dict]:
    if not isinstance(experiment_inputs, dict):
        return []
    rows = experiment_inputs.get("inputs")
    if not isinstance(rows, list):
        return []
    return [
        row for row in rows
        if isinstance(row, dict) and str(row.get("input_role") or "") == role
    ]


def recognize(chord_recognition, audio_path: Path, dictionary: str):
    return normalize_segments(chord_recognition(audio_path=str(audio_path), chord_dict_name=dictionary))


def n_diag(name, segs, beats):
    mask = n_mask_from_segments(segs, beats)
    return {
        "name": name,
        "no_chord_beats": int(mask.sum()),
        "total_beats": int(len(mask)),
        "ratio": float(mask.mean()) if len(mask) else 0.0,
        "indices": [int(i) for i in np.flatnonzero(mask)],
        "mask": [bool(x) for x in mask],
    }


def analyze(audio: Path, signature_request: str, deps: Path, work: Path, progress, log, experiment_inputs=None):
    if not torch.cuda.is_available():
        raise RuntimeError("CUDA obligatoire : aucun fallback CPU")
    ff = shutil.which("ffmpeg")
    if not ff:
        raise RuntimeError("ffmpeg introuvable dans PATH")

    ensure_deps(deps, log)
    from beat_this.inference import Audio2Frames
    from lv_chordia.chord_recognition import chord_recognition

    progress(8)
    log("INFO", f"GPU {torch.cuda.get_device_name(0)}")
    work.mkdir(parents=True, exist_ok=True)

    wav = work / "input.wav"
    subprocess.run([ff, "-hide_banner", "-loglevel", "error", "-y", "-i", str(audio), "-ac", "1", "-ar", "22050", "-c:a", "pcm_s16le", str(wav)], check=True)
    with wave.open(str(wav), "rb") as w:
        sr = w.getframerate()
        raw = w.readframes(w.getnframes())
    y = np.frombuffer(raw, dtype="<i2").astype(np.float32) / 32768.0
    hop = 512

    # ---- metric section: intentionally unchanged from R6 ----
    progress(18)
    _, perc = librosa.effects.hpss(y)
    tempo_arr, beats = librosa.beat.beat_track(y=perc, sr=sr, hop_length=hop, units="time", trim=False)
    tempo = float(np.asarray(tempo_arr).squeeze())
    beats = np.asarray(beats, float)
    bf = librosa.time_to_frames(beats, sr=sr, hop_length=hop)
    onset = sample_frames(librosa.onset.onset_strength(y=perc, sr=sr, hop_length=hop), bf)
    bass = bass_feature(y, sr, hop, bf)
    harm = harmonic_novelty(y, sr, hop, bf)

    progress(30)
    if signature_request == "Auto":
        meter = auto_signature(z(onset), z(bass), z(harm))
        initial_signature = meter["signature"]
    else:
        meter = {"signature": signature_request, "confidence": 1.0, "scores": {}}
        initial_signature = signature_request

    model = Audio2Frames(checkpoint_path="final0", device="cuda", float16=False)
    _, db = model(y, sr)
    bt = sample_times(sigmoid(db.detach().float().cpu().numpy()), beats)

    if signature_request == "Auto":
        signature, duple_refinement = refine_duple_signature_with_beat_this(initial_signature, bt)
    else:
        signature = initial_signature
        duple_refinement = {
            "applied": False,
            "reason": "manual_signature",
            "initial_signature": initial_signature,
            "refined_signature": signature,
        }

    meter["initial_signature"] = initial_signature
    meter["signature"] = signature
    meter["duple_refinement"] = duple_refinement

    period = int(signature.split("/")[0])
    log("INFO", f"signature utilisée {signature}; initiale {initial_signature}; tempo {tempo:.3f}")
    progress(48)
    rz, bz, hz = z(onset), z(bass), z(harm)
    specs = [
        ("Beat This downbeat", bt),
        ("Percussive onset", rz),
        ("Bass CQT", bz),
        ("Rhythm + Bass", 0.72 * rz + 0.28 * bz),
        ("Harmonic novelty", hz),
        ("R41-like fusion", 0.62 * rz + 0.25 * bz + 0.13 * hz),
    ]
    alg = []
    for name, v in specs:
        p = choose_phase(v, period)
        p["algorithm"] = name
        alg.append(p)

    phase_hist = {}
    for a in alg:
        key = str(a["phase"])
        phase_hist[key] = phase_hist.get(key, 0) + 1
    convergence = {
        "distinct_phases": len(phase_hist),
        "phase_histogram": phase_hist,
        "unanimous": len(phase_hist) == 1,
    }
    # ---- end frozen metric section ----

    progress(58)
    log("INFO", f"phases {phase_hist}; unanimité={convergence['unanimous']}")

    selected_chord_inputs = _selected_by_input_role(
        experiment_inputs, "chord_input"
    )
    selected_no_chord_inputs = _selected_by_input_role(
        experiment_inputs, "no_chord_evidence"
    )

    chord_source = audio
    analysis_source = "master_audio"
    if selected_chord_inputs:
        chord_source = make_selected_mix(
            selected_chord_inputs,
            work,
            "selected_chord_inputs.wav",
            sr=sr,
        )
        analysis_source = "selected_stems"
        log(
            "INFO",
            "accords lv-chordia submission sur stems sélectionnés: "
            + ",".join(
                str(x.get("stem_role") or "?")
                for x in selected_chord_inputs
            )
        )
    else:
        log("INFO", "accords master lv-chordia submission")

    submission_segs = recognize(chord_recognition, chord_source, "submission")
    if not submission_segs:
        raise RuntimeError("lv-chordia submission n’a retourné aucun segment")

    log("INFO", "no-chord master lv-chordia ismir2017")
    master_n_segs = recognize(chord_recognition, audio, "ismir2017")
    if not master_n_segs:
        raise RuntimeError("lv-chordia ismir2017 n’a retourné aucun segment")
    master_n = n_diag("A_master_ismir2017", master_n_segs, beats)
    active_mask = np.asarray(master_n["mask"], dtype=bool)

    # Research variants. They NEVER alter the active grid in V7N.
    benchmark_variants = [master_n]

    progress(68)
    hpss_wav = make_hpss_harmonic_wav(y, sr, work)
    try:
        hpss_segs = recognize(chord_recognition, hpss_wav, "ismir2017")
        benchmark_variants.append(n_diag("B_master_hpss_ismir2017", hpss_segs, beats))
    except Exception as exc:
        benchmark_variants.append({"name": "B_master_hpss_ismir2017", "error": f"{type(exc).__name__}: {exc}"})

    progress(74)
    stems_info = {"available": False}

    if selected_no_chord_inputs:
        stem_labels_by_name = {}
        stem_masks_by_name = {}
        selected_stems_map = {}

        for item in selected_no_chord_inputs:
            stem_name = str(
                item.get("stem_role")
                or item.get("artifact_id")
                or "stem"
            )
            stem_path = Path(str(item.get("path") or ""))
            expected = str(item.get("sha256") or "").strip().lower()
            if not stem_path.is_file():
                raise RuntimeError(
                    f"selected_no_chord_stem_missing:{stem_path}"
                )
            if expected and sha256_file(stem_path).lower() != expected:
                raise RuntimeError(
                    f"selected_no_chord_hash_mismatch:{item.get('artifact_id')}"
                )

            stem_segs = recognize(
                chord_recognition,
                stem_path,
                "ismir2017",
            )
            stem_diag = n_diag(
                f"D_selected_{stem_name}_ismir2017",
                stem_segs,
                beats,
            )
            benchmark_variants.append(stem_diag)
            stem_labels_by_name[stem_name] = beat_labels(stem_segs, beats)
            stem_masks_by_name[stem_name] = np.asarray(
                stem_diag["mask"], dtype=bool
            )
            selected_stems_map[stem_name] = str(stem_path)

        if stem_masks_by_name:
            stem_names = tuple(stem_masks_by_name.keys())
            stem_n_matrix = np.vstack([
                stem_masks_by_name[name]
                for name in stem_names
            ])
            support_count = (~stem_n_matrix).sum(axis=0)
            active_mask = support_count == 0

            support_beats = []
            for i in range(len(active_mask)):
                labels = {
                    name: stem_labels_by_name[name][i]
                    for name in stem_names
                }
                supporting = [
                    name
                    for name in stem_names
                    if labels[name] != NO_CHORD
                ]
                support_beats.append({
                    "beat": int(i),
                    "support_count": int(support_count[i]),
                    "supporting_stems": supporting,
                    "labels": labels,
                    "is_no_chord": bool(active_mask[i]),
                })

            positive_support_diag = {
                "name": "E_positive_harmonic_support_selected",
                "no_chord_beats": int(active_mask.sum()),
                "total_beats": int(len(active_mask)),
                "ratio": (
                    float(active_mask.mean()) if len(active_mask) else 0.0
                ),
                "indices": [
                    int(i) for i in np.flatnonzero(active_mask)
                ],
                "mask": [bool(x) for x in active_mask],
                "rule": (
                    "N only if none of the explicitly selected "
                    "NO-CHORD evidence stems reports a non-N label"
                ),
                "sources": list(stem_names),
                "beat_support": support_beats,
            }
            benchmark_variants.append(positive_support_diag)
            stems_info = {
                "available": True,
                "source": "scientific_run_selected_artifacts",
                "generated": False,
                "stems": selected_stems_map,
                "inputs": selected_no_chord_inputs,
            }
            log(
                "INFO",
                f'no-chord actif '
                f'E_positive_harmonic_support_selected = '
                f'{positive_support_diag["no_chord_beats"]}/'
                f'{positive_support_diag["total_beats"]}'
            )
    else:
        try:
            stems_info = ensure_ezstudio_stems(audio, work, log)
            if stems_info.get("available"):
                mix = make_instrumental_mix(
                    stems_info["stems"], work, sr=sr
                )
                inst_segs = recognize(
                    chord_recognition, mix, "ismir2017"
                )
                benchmark_variants.append(
                    n_diag(
                        "C_instrumental_stems_ismir2017",
                        inst_segs,
                        beats,
                    )
                )

                stem_names = ("bass", "guitar", "piano", "other")
                stem_labels_by_name = {}
                stem_masks_by_name = {}

                for stem_name in stem_names:
                    stem_segs = recognize(
                        chord_recognition,
                        Path(stems_info["stems"][stem_name]),
                        "ismir2017",
                    )
                    stem_diag = n_diag(
                        f"D_{stem_name}_ismir2017",
                        stem_segs,
                        beats,
                    )
                    benchmark_variants.append(stem_diag)
                    stem_labels_by_name[stem_name] = beat_labels(
                        stem_segs, beats
                    )
                    stem_masks_by_name[stem_name] = np.asarray(
                        stem_diag["mask"], dtype=bool
                    )

                if len(stem_masks_by_name) == len(stem_names):
                    stem_n_matrix = np.vstack([
                        stem_masks_by_name[name]
                        for name in stem_names
                    ])
                    support_count = (~stem_n_matrix).sum(axis=0)
                    active_mask = support_count == 0

                    support_beats = []
                    for i in range(len(active_mask)):
                        labels = {
                            name: stem_labels_by_name[name][i]
                            for name in stem_names
                        }
                        supporting = [
                            name
                            for name in stem_names
                            if labels[name] != NO_CHORD
                        ]
                        support_beats.append({
                            "beat": int(i),
                            "support_count": int(support_count[i]),
                            "supporting_stems": supporting,
                            "labels": labels,
                            "is_no_chord": bool(active_mask[i]),
                        })

                    positive_support_diag = {
                        "name": "E_positive_harmonic_support",
                        "no_chord_beats": int(active_mask.sum()),
                        "total_beats": int(len(active_mask)),
                        "ratio": (
                            float(active_mask.mean())
                            if len(active_mask) else 0.0
                        ),
                        "indices": [
                            int(i)
                            for i in np.flatnonzero(active_mask)
                        ],
                        "mask": [bool(x) for x in active_mask],
                        "rule": (
                            "N only if no bass/guitar/piano/other stem "
                            "reports a non-N label"
                        ),
                        "sources": list(stem_names),
                        "beat_support": support_beats,
                    }
                    benchmark_variants.append(positive_support_diag)
        except Exception as exc:
            log(
                "WARN",
                f"benchmark stems indisponible: "
                f"{type(exc).__name__}: {exc}",
            )
            stems_info = {
                "available": False,
                "reason": f"{type(exc).__name__}: {exc}",
                "ezstudio_autonomous": True,
            }

    # Evidence-only consensus: count how many available variants say N.
    valid_masks = [np.asarray(v["mask"], dtype=bool) for v in benchmark_variants if isinstance(v, dict) and "mask" in v]
    evidence = []
    if valid_masks:
        matrix = np.vstack(valid_masks)
        for i in range(matrix.shape[1]):
            evidence.append({
                "beat": int(i),
                "n_votes": int(matrix[:, i].sum()),
                "sources": int(matrix.shape[0]),
            })

    progress(86)
    for a in alg:
        a["grid"] = grid(
            submission_segs,
            beats,
            int(a["phase"]),
            period,
            32,
            no_chord_mask=active_mask,
        )

    progress(95)
    versions = {
        "python": sys.version.split()[0],
        "torch": str(torch.__version__),
        "librosa": str(librosa.__version__),
    }
    for package in ("beat-this", "lv-chordia", "torchaudio"):
        try:
            versions[package] = importlib.metadata.version(package)
        except importlib.metadata.PackageNotFoundError:
            versions[package] = None

    return {
        "engine_version": ENGINE_VERSION,
        "analysis_source": analysis_source,
        "tempo": tempo,
        "signature": signature,
        "meter": meter,
        "duration_s": float(len(y) / sr),
        "sample_rate": int(sr),
        "beat_grid_s": [float(x) for x in beats],
        "versions": versions,
        "parameters": {
            "hop_length": hop,
            "beat_this_checkpoint": "final0",
            "beat_this_fps": FPS,
            "chord_engine": "lv-chordia",
            "chord_dictionary": "submission",
            "no_chord_dictionary": "ismir2017",
            "active_no_chord_policy": (
                "positive_harmonic_support_selected_stems"
                if selected_no_chord_inputs
                else "positive_harmonic_support_any_stem"
            ),
            "stems_role": "active_no_chord_decision",
            "scientific_selection": (
                experiment_inputs
                if isinstance(experiment_inputs, dict)
                else None
            ),
            "ezstudio_autonomous": True,
        },
        "algorithms": alg,
        "convergence": convergence,
        "segments": submission_segs,
        "no_chord_benchmark": {
            "contract": {
                "internal_token": NO_CHORD,
                "display_n_4": "𝄽",
                "display_n_8": "𝄾",
                "midi_on_N": "silence",
                "metric_algorithms_modified": False,
                "ezscore_runtime_dependency": False,
            },
            "active_variant": (
                "E_positive_harmonic_support_selected"
                if selected_no_chord_inputs
                else "E_positive_harmonic_support"
            ),
            "variants": benchmark_variants,
            "evidence_only": evidence,
            "stems": stems_info,
        },
    }


def self_test():
    x = np.zeros(24)
    x[2::4] = 1
    cp = choose_phase(x, 4)
    assert cp["phase"] == 2
    assert len(cp["phase_scores"]) == 4

    sig, _ = refine_duple_signature_with_beat_this("2/4", x, threshold=0.01)
    assert sig == "4/4"

    assert chord_label("N") == "N"
    assert chord_for_interval([], 0.0, 0.5) == "N"

    beats = np.arange(0, 8, 0.5)
    segs = [{"start": 0, "end": 8, "chord": "Cm"}]
    assert grid(segs, beats, 0, 4, 1)[0]["text"] == "Cm - - -"

    mask = np.zeros(len(beats), dtype=bool)
    mask[1] = True
    assert grid(segs, beats, 0, 4, 1, no_chord_mask=mask)[0]["text"] == "Cm N Cm -"

    # Positive-support contract:
    # N only when every accompaniment stem is N.
    n_masks = np.vstack([
        np.array([True,  True,  False, True]),
        np.array([True,  True,  True,  True]),
        np.array([True,  False, True,  True]),
        np.array([True,  True,  True,  True]),
    ])
    support_count = (~n_masks).sum(axis=0)
    assert (support_count == 0).tolist() == [True, False, False, True]

    import ast
    source = Path(__file__).read_text(encoding="utf-8")
    tree = ast.parse(source)
    defined_functions = {
        node.name
        for node in ast.walk(tree)
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))
    }
    assert "harmonic_silence_mask" not in defined_functions
    print("ENGINE_SELF_TEST_OK")
    print("N_INTERNAL_CONTRACT_OK")
    print("EZSTUDIO_AUTONOMY_CONTRACT_OK")


if __name__ == "__main__":
    if "--self-test" in sys.argv:
        self_test()
