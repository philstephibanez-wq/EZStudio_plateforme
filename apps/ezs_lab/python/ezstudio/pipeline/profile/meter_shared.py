from __future__ import annotations

import os
import sys
from pathlib import Path

import numpy as np
from ezstudio.runtime.profile_phase_probe import mark as phase_mark



def _metric_level_periods(signature: str) -> list[tuple[str, int]]:
    try:
        numerator_text, denominator_text = signature.split("/", 1)
        numerator = int(numerator_text)
        denominator = int(denominator_text)
    except Exception:
        return []

    if denominator == 4:
        rows: list[tuple[str, int]] = [
            ("quarter", numerator),
            ("eighth", numerator * 2),
        ]
        if numerator >= 4 and numerator % 2 == 0:
            rows.append(("half", numerator // 2))
        return rows

    if denominator == 8 and numerator in {6, 9, 12}:
        return [
            ("dotted_quarter", numerator // 3),
            ("eighth", numerator),
        ]

    return []


def _metric_level_candidates(
    signature: str,
    downbeat_activation: np.ndarray,
    choose_phase_fn,
) -> dict:
    values = np.asarray(downbeat_activation, dtype=float)
    candidates = []
    for beat_unit, events_per_bar in _metric_level_periods(signature):
        if events_per_bar < 2:
            continue
        if len(values) < events_per_bar * 2:
            continue
        phase = choose_phase_fn(values, events_per_bar)
        candidates.append(
            {
                "beat_unit": beat_unit,
                "events_per_bar": int(events_per_bar),
                "score": round(float(phase["score"]), 6),
                "phase": int(phase["phase"]),
                "phase_margin": round(float(phase["margin"]), 6),
            }
        )

    candidates.sort(
        key=lambda row: (
            -float(row["score"]),
            -float(row["phase_margin"]),
            str(row["beat_unit"]),
        )
    )
    return {
        "source": "beat-this-downbeat-periodicity",
        "signature": signature,
        "candidates": candidates,
        "calibrated": False,
    }

def _harmonic_novelty_from_harmonic(h, sr, hop, bf):
    """Same computation as engine.harmonic_novelty, reusing existing HPSS."""
    import librosa

    c = librosa.feature.chroma_cqt(y=h, sr=sr, hop_length=hop)
    if c.shape[1] < 2:
        return np.zeros(len(bf))
    a, b = c[:, :-1], c[:, 1:]
    nov = np.r_[0.0, 1.0 - np.sum(a * b, axis=0) / (np.linalg.norm(a, axis=0) * np.linalg.norm(b, axis=0) + 1e-9)]
    from engine import sample_frames
    return sample_frames(nov, bf)


def estimate_meter_from_chords_engine(
    y: np.ndarray,
    sr: int,
    *,
    confidence_threshold: float = 0.50,
    harmonic_sink=None,
) -> dict:
    """
    Reuse the metric logic already validated in python/engine.py without
    launching a CHORDS run.
    """
    project_python = Path(__file__).resolve().parents[3]
    if str(project_python) not in sys.path:
        sys.path.insert(0, str(project_python))

    dep_root = Path(
        os.getenv("EZSTUDIO_DEP_ROOT", r"H:\temp\EZStudio_lab\deps")
    )
    if dep_root.is_dir() and str(dep_root) not in sys.path:
        sys.path.append(str(dep_root))

    phase_mark("meter", "dependency_start")
    import librosa
    import torch

    phase_mark("meter", "dependency_done")
    if not torch.cuda.is_available():
        raise RuntimeError("profile_meter_cuda_required")

    from engine import (
        z,
        sample_frames,
        sample_times,
        sigmoid,
        bass_feature,
        harmonic_novelty,
        auto_signature,
        choose_phase,
        refine_duple_signature_with_beat_this,
    )

    try:
        from beat_this.inference import Audio2Frames
    except Exception as exc:
        raise RuntimeError(
            f"profile_meter_beat_this_unavailable:{type(exc).__name__}:{exc}"
        ) from exc

    hop = 512
    phase_mark("meter", "hpss_start")
    if os.getenv("EZSTUDIO_PROFILE_HPSS_MEDIAN_BACKEND", "scipy") == "medfilt2d":
        from ezstudio.pipeline.profile.hpss_median_optin import hpss_medfilt2d
        harmonic, perc = hpss_medfilt2d(y)
    else:
        harmonic, perc = librosa.effects.hpss(y)
    if harmonic_sink is not None:
        harmonic_sink(harmonic)

    phase_mark("meter", "hpss_done")
    tempo_arr, beats = librosa.beat.beat_track(
        y=perc,
        sr=sr,
        hop_length=hop,
        units="time",
        trim=False,
    )
    beats = np.asarray(beats, dtype=float)

    if len(beats) < 12:
        return {
            "available": True,
            "label": None,
            "suggested_label": None,
            "numerator": None,
            "denominator": None,
            "confidence": 0.0,
            "candidate_scores": {},
            "source": "chords_metric_r9",
            "reason": "too_few_beats",
        }

    phase_mark("meter", "beat_detection_done")
    bf = librosa.time_to_frames(beats, sr=sr, hop_length=hop)
    onset = sample_frames(
        librosa.onset.onset_strength(y=perc, sr=sr, hop_length=hop),
        bf,
    )
    bass = bass_feature(y, sr, hop, bf)
    harm = _harmonic_novelty_from_harmonic(harmonic, sr, hop, bf)

    phase_mark("meter", "features_done")
    meter = auto_signature(z(onset), z(bass), z(harm))
    initial_signature = str(meter["signature"])

    checkpoint = Path(
        os.getenv(
            "EZSTUDIO_BEAT_THIS_CHECKPOINT",
            r"H:\EZStudioModels\beat_this\beat_this-final0.ckpt",
        )
    )
    if not checkpoint.is_file():
        raise RuntimeError(
            f"profile_meter_checkpoint_missing:{checkpoint}"
        )

    phase_mark("meter", "model_start")
    model = Audio2Frames(
        checkpoint_path=str(checkpoint),
        device="cuda",
        float16=False,
    )
    phase_mark("meter", "model_done")
    _, db = model(y, sr)
    phase_mark("meter", "inference_done")
    bt = sample_times(
        sigmoid(db.detach().float().cpu().numpy()),
        beats,
    )

    signature, refinement = refine_duple_signature_with_beat_this(
        initial_signature,
        bt,
    )

    metric_level_candidates = _metric_level_candidates(
        signature,
        bt,
        choose_phase,
    )

    confidence = float(meter.get("confidence") or 0.0)
    confident = confidence >= float(confidence_threshold)
    numerator = int(signature.split("/", 1)[0])
    denominator = int(signature.split("/", 1)[1])

    return {
        "available": True,
        "label": signature if confident else None,
        "suggested_label": signature,
        "numerator": numerator if confident else None,
        "denominator": denominator if confident else None,
        "confidence": round(confidence, 4),
        "confidence_threshold": float(confidence_threshold),
        "candidate_scores": {
            str(k): round(float(v), 6)
            for k, v in (meter.get("scores") or {}).items()
        },
        "initial_signature": initial_signature,
        "duple_refinement": refinement,
        "metric_level": metric_level_candidates,
        "source": "chords_metric_r9",
        "beat_this_checkpoint": str(checkpoint),
        "tempo_bpm": float(np.asarray(tempo_arr).squeeze()),
    }
