from __future__ import annotations

import math
import os
import json
import time
import sys
from concurrent.futures import ThreadPoolExecutor
from typing import Any

import numpy as np

from module import ModuleConfig, ModuleContext


def _reference_hz(cents_from_a440: float) -> float:
    return 440.0 * (2.0 ** (float(cents_from_a440) / 1200.0))


def _estimate_cents_librosa(
    y: np.ndarray,
    sr: int,
    resolution: float,
) -> float | None:
    import librosa

    if y.size < max(2048, int(sr * 0.25)):
        return None

    value = float(
        librosa.estimate_tuning(
            y=np.asarray(y, dtype=np.float32),
            sr=int(sr),
            resolution=float(resolution),
            bins_per_octave=12,
        )
    ) * 100.0
    return value if math.isfinite(value) else None


def _chunk_slices(
    y: np.ndarray,
    sr: int,
    *,
    chunk_seconds: float,
    max_chunks: int,
) -> list[np.ndarray]:
    samples_per_chunk = max(1, int(round(float(chunk_seconds) * sr)))
    if y.size <= samples_per_chunk:
        return [np.asarray(y, dtype=np.float32)]

    starts = np.linspace(
        0,
        max(0, y.size - samples_per_chunk),
        num=max(1, int(max_chunks)),
        dtype=int,
    )
    return [
        np.asarray(y[start : start + samples_per_chunk], dtype=np.float32)
        for start in starts.tolist()
    ]


def _chunk_librosa(
    y: np.ndarray,
    sr: int,
    *,
    chunk_seconds: float,
    max_chunks: int,
    resolution: float,
) -> list[float]:
    values = []
    for chunk in _chunk_slices(
        y,
        sr,
        chunk_seconds=chunk_seconds,
        max_chunks=max_chunks,
    ):
        value = _estimate_cents_librosa(chunk, sr, resolution)
        if value is not None:
            values.append(value)
    return values


def _summary(
    values: list[float],
    *,
    minimum_chunk_support: int,
    stable_mad_cents: float,
) -> dict[str, Any]:
    if not values:
        return {
            "available": False,
            "median_cents": None,
            "reference_hz": None,
            "chunk_estimates_cents": [],
            "chunk_mad_cents": None,
            "chunk_support": 0,
            "stable": False,
        }

    median = float(np.median(values))
    mad = (
        float(np.median(np.abs(np.asarray(values) - median)))
        if len(values) >= 2
        else None
    )
    stable = (
        len(values) >= int(minimum_chunk_support)
        and mad is not None
        and mad <= float(stable_mad_cents)
    )
    return {
        "available": True,
        "median_cents": round(median, 4),
        "reference_hz": round(_reference_hz(median), 3),
        "chunk_estimates_cents": [round(x, 4) for x in values],
        "chunk_mad_cents": round(mad, 4) if mad is not None else None,
        "chunk_support": len(values),
        "stable": stable,
    }


def _librosa_family(
    master_global: float | None,
    harmonic_global: float | None,
    master_summary: dict[str, Any],
    harmonic_summary: dict[str, Any],
    *,
    max_internal_disagreement_cents: float,
) -> dict[str, Any]:
    if master_global is None or harmonic_global is None:
        return {"available": False, "stable": False, "cents": None}

    disagreement = abs(float(master_global) - float(harmonic_global))
    stable = (
        bool(master_summary.get("stable"))
        and bool(harmonic_summary.get("stable"))
        and disagreement <= float(max_internal_disagreement_cents)
    )
    cents = float(np.median([master_global, harmonic_global]))
    return {
        "available": True,
        "stable": stable,
        "cents": round(cents, 4),
        "reference_hz": round(_reference_hz(cents), 3),
        "internal_disagreement_cents": round(disagreement, 4),
    }


def _cqt_grid_score(
    y: np.ndarray,
    sr: int,
    *,
    offset_cents: float,
    fmin_hz: float,
    n_octaves: int,
    hop_length: int,
) -> float | None:
    import librosa

    if y.size < 4096:
        return None

    tuning_bins = float(offset_cents) / 100.0
    cqt = np.abs(
        librosa.cqt(
            y=np.asarray(y, dtype=np.float32),
            sr=int(sr),
            hop_length=int(hop_length),
            fmin=float(fmin_hz),
            n_bins=int(n_octaves) * 12,
            bins_per_octave=12,
            tuning=tuning_bins,
        )
    )
    if not np.any(cqt > 0):
        return None

    # Robust musical-grid score: reward concentration of harmonic energy
    # on a semitone-aligned CQT bank while reducing loudness dependence.
    frame_sum = np.sum(cqt, axis=0) + 1e-12
    normalized = cqt / frame_sum
    concentration = np.sum(normalized * normalized, axis=0)
    frame_energy = np.sqrt(frame_sum)
    return float(
        np.sum(concentration * frame_energy)
        / (np.sum(frame_energy) + 1e-12)
    )


def _cqt_detune_scan(
    y: np.ndarray,
    sr: int,
    *,
    offset_min_cents: int,
    offset_max_cents: int,
    offset_step_cents: int,
    fmin_hz: float,
    n_octaves: int,
    hop_length: int,
) -> dict[str, Any]:
    offsets = list(
        range(
            int(offset_min_cents),
            int(offset_max_cents) + 1,
            int(offset_step_cents),
        )
    )
    rows = []
    for offset in offsets:
        score = _cqt_grid_score(
            y,
            sr,
            offset_cents=float(offset),
            fmin_hz=fmin_hz,
            n_octaves=n_octaves,
            hop_length=hop_length,
        )
        if score is not None and math.isfinite(score):
            rows.append(
                {
                    "cents": float(offset),
                    "score": float(score),
                }
            )

    if not rows:
        return {
            "available": False,
            "cents": None,
            "reference_hz": None,
            "score_margin": None,
            "candidates": [],
        }

    ranked = sorted(rows, key=lambda row: row["score"], reverse=True)
    best = ranked[0]
    second = ranked[1] if len(ranked) > 1 else None
    margin = (
        float(best["score"] - second["score"])
        if second is not None
        else None
    )
    return {
        "available": True,
        "cents": round(float(best["cents"]), 4),
        "reference_hz": round(_reference_hz(float(best["cents"])), 3),
        "score": round(float(best["score"]), 8),
        "score_margin": round(margin, 8) if margin is not None else None,
        "candidates": [
            {
                "cents": round(float(row["cents"]), 4),
                "score": round(float(row["score"]), 8),
            }
            for row in ranked[:10]
        ],
    }


def _chunk_cqt_scan(
    y: np.ndarray,
    sr: int,
    *,
    chunk_seconds: float,
    max_chunks: int,
    offset_min_cents: int,
    offset_max_cents: int,
    offset_step_cents: int,
    fmin_hz: float,
    n_octaves: int,
    hop_length: int,
) -> list[float]:
    chunks = _chunk_slices(
        y,
        sr,
        chunk_seconds=chunk_seconds,
        max_chunks=max_chunks,
    )

    def scan(chunk: np.ndarray) -> dict[str, Any]:
        return _cqt_detune_scan(
            chunk,
            sr,
            offset_min_cents=offset_min_cents,
            offset_max_cents=offset_max_cents,
            offset_step_cents=offset_step_cents,
            fmin_hz=fmin_hz,
            n_octaves=n_octaves,
            hop_length=hop_length,
        )

    # Opt-in and capped: FFT/Numba/BLAS may already use internal threads.
    # executor.map preserves original chunk order, including tied rankings.
    workers_text = os.getenv("EZSTUDIO_PROFILE_CQT_WORKERS", "1")
    try:
        workers = int(workers_text)
    except ValueError:
        workers = 1
    workers = max(1, min(workers, 2, len(chunks)))
    if workers == 1:
        results = [scan(chunk) for chunk in chunks]
    else:
        with ThreadPoolExecutor(max_workers=workers) as executor:
            results = list(executor.map(scan, chunks))

    values = []
    for result in results:
        if result.get("available") and result.get("cents") is not None:
            values.append(float(result["cents"]))
    return values


def _consensus_r2d(
    librosa_family: dict[str, Any],
    cqt_summary: dict[str, Any],
    cqt_global: dict[str, Any],
    *,
    max_cross_method_disagreement_cents: float,
) -> dict[str, Any]:
    if (
        not librosa_family.get("available")
        or not cqt_summary.get("available")
        or not cqt_global.get("available")
    ):
        return {
            "decision": "unavailable",
            "reference_hz": None,
            "cents_from_a440": None,
            "cross_method_disagreement_cents": None,
        }

    a = float(librosa_family["cents"])
    b = float(cqt_summary["median_cents"])
    disagreement = abs(a - b)

    if (
        not bool(librosa_family.get("stable"))
        or not bool(cqt_summary.get("stable"))
        or disagreement > float(max_cross_method_disagreement_cents)
    ):
        return {
            "decision": "ambiguous",
            "reference_hz": None,
            "cents_from_a440": None,
            "cross_method_disagreement_cents": round(disagreement, 4),
        }

    cents = float(np.median([a, b]))
    return {
        "decision": "accepted",
        "reference_hz": round(_reference_hz(cents), 3),
        "cents_from_a440": round(cents, 3),
        "cross_method_disagreement_cents": round(disagreement, 4),
    }


def _tuning_probe(stage: str, elapsed_s: float) -> None:
    """Opt-in stage timings; stderr only, no scientific output changes."""
    if os.getenv("EZSTUDIO_PROFILE_TUNING_PROBE") != "1":
        return
    print(
        "EZSTUDIO_PROFILE_TUNING_PROBE "
        + json.dumps({"stage": stage, "elapsed_seconds": round(elapsed_s, 6)}),
        file=sys.stderr,
        flush=True,
    )


def run(
    context: ModuleContext,
    config: ModuleConfig,
) -> dict[str, Any]:
    import librosa

    stage_clock = time.perf_counter()

    def mark(name: str) -> None:
        nonlocal stage_clock
        now = time.perf_counter()
        _tuning_probe(name, now - stage_clock)
        stage_clock = now

    p = config.parameters or {}
    t = config.thresholds or {}

    resolution = float(p.get("resolution_semitones", 0.01))
    chunk_seconds = float(p.get("chunk_seconds", 12.0))
    max_chunks = int(p.get("max_chunks", 8))
    hpss_margin = float(p.get("hpss_margin", 1.0))

    offset_min = int(p.get("cqt_offset_min_cents", -30))
    offset_max = int(p.get("cqt_offset_max_cents", 30))
    offset_step = int(p.get("cqt_offset_step_cents", 2))
    cqt_fmin = float(p.get("cqt_fmin_hz", 55.0))
    cqt_octaves = int(p.get("cqt_n_octaves", 6))
    cqt_hop = int(p.get("cqt_hop_length", 2048))

    min_chunks = int(t.get("minimum_chunk_support", 3))
    stable_mad = float(t.get("stable_mad_cents", 5.0))
    max_internal = float(t.get("max_estimator_disagreement_cents", 5.0))
    max_cross = float(t.get("max_cross_method_disagreement_cents", 5.0))

    master_y = np.asarray(context.y, dtype=np.float32)
    cached_harmonic = (
        context._meter_harmonic
        if os.getenv("EZSTUDIO_PROFILE_HPSS_REUSE") == "1"
        and hpss_margin == 1.0
        and context._meter_harmonic_sr == int(context.sr)
        and isinstance(context._meter_harmonic, np.ndarray)
        and context._meter_harmonic.shape == master_y.shape
        and context.y.dtype == np.float32
        else None
    )
    if cached_harmonic is not None:
        harmonic_y = cached_harmonic
    else:
        harmonic_y = np.asarray(
            librosa.effects.harmonic(master_y, margin=hpss_margin),
            dtype=np.float32,
        )
    if os.getenv("EZSTUDIO_PROFILE_TUNING_PROBE") == "1":
        print(
            "EZSTUDIO_PROFILE_HPSS_REUSE "
            + ("cache_hit" if cached_harmonic is not None else "fallback"),
            file=sys.stderr,
            flush=True,
        )
    mark("harmonic_extraction")

    master_global = _estimate_cents_librosa(
        master_y,
        int(context.sr),
        resolution,
    )
    harmonic_global = _estimate_cents_librosa(
        harmonic_y,
        int(context.sr),
        resolution,
    )
    mark("global_tuning_estimates")

    master_values = _chunk_librosa(
        master_y,
        int(context.sr),
        chunk_seconds=chunk_seconds,
        max_chunks=max_chunks,
        resolution=resolution,
    )
    harmonic_values = _chunk_librosa(
        harmonic_y,
        int(context.sr),
        chunk_seconds=chunk_seconds,
        max_chunks=max_chunks,
        resolution=resolution,
    )
    mark("chunk_tuning_estimates")

    master = _summary(
        master_values,
        minimum_chunk_support=min_chunks,
        stable_mad_cents=stable_mad,
    )
    master["global_cents"] = (
        round(master_global, 4) if master_global is not None else None
    )

    harmonic = _summary(
        harmonic_values,
        minimum_chunk_support=min_chunks,
        stable_mad_cents=stable_mad,
    )
    harmonic["global_cents"] = (
        round(harmonic_global, 4) if harmonic_global is not None else None
    )

    librosa_family = _librosa_family(
        master_global,
        harmonic_global,
        master,
        harmonic,
        max_internal_disagreement_cents=max_internal,
    )

    mark("summaries")
    cqt_global = _cqt_detune_scan(
        harmonic_y,
        int(context.sr),
        offset_min_cents=offset_min,
        offset_max_cents=offset_max,
        offset_step_cents=offset_step,
        fmin_hz=cqt_fmin,
        n_octaves=cqt_octaves,
        hop_length=cqt_hop,
    )
    mark("global_cqt_scan")
    cqt_values = _chunk_cqt_scan(
        harmonic_y,
        int(context.sr),
        chunk_seconds=chunk_seconds,
        max_chunks=max_chunks,
        offset_min_cents=offset_min,
        offset_max_cents=offset_max,
        offset_step_cents=offset_step,
        fmin_hz=cqt_fmin,
        n_octaves=cqt_octaves,
        hop_length=cqt_hop,
    )
    mark("chunk_cqt_scans")
    cqt_summary = _summary(
        cqt_values,
        minimum_chunk_support=min_chunks,
        stable_mad_cents=stable_mad,
    )

    consensus = _consensus_r2d(
        librosa_family,
        cqt_summary,
        cqt_global,
        max_cross_method_disagreement_cents=max_cross,
    )

    normalized = {
        "available": consensus["decision"] != "unavailable",
        "decision": consensus["decision"],
        "reference_hz": consensus["reference_hz"],
        "cents_from_a440": consensus["cents_from_a440"],
        "cross_method_disagreement_cents": consensus[
            "cross_method_disagreement_cents"
        ],
        "max_cross_method_disagreement_cents": max_cross,
        "methods": {
            "librosa_family": librosa_family,
            "cqt_detune_scan": {
                "available": cqt_summary.get("available"),
                "stable": cqt_summary.get("stable"),
                "reference_hz": cqt_summary.get("reference_hz"),
                "cents_from_a440": cqt_summary.get("median_cents"),
                "chunk_mad_cents": cqt_summary.get("chunk_mad_cents"),
                "global_cents": cqt_global.get("cents"),
                "global_reference_hz": cqt_global.get("reference_hz"),
                "global_score_margin": cqt_global.get("score_margin"),
            },
        },
        "confidence": None,
        "calibrated": False,
        "source": "cross-method-tuning-consensus-r2d",
    }

    mark("consensus_and_projection")
    return {
        "raw": {
            "librosa_master": master,
            "librosa_harmonic": harmonic,
            "librosa_family": librosa_family,
            "cqt_detune_scan": {
                "global": cqt_global,
                "chunks": cqt_summary,
            },
            "consensus": consensus,
        },
        "normalized": normalized,
        "calibrated": {
            "status": "not_calibrated",
            "method": "cross-method-tuning-consensus-r2d",
            "confidence": None,
        },
        "engine": {
            "method": "librosa-estimate-tuning+cqt-detune-scan",
        },
    }
