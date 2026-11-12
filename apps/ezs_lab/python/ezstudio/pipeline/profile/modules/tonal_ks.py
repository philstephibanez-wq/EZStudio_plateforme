from __future__ import annotations

import numpy as np

from module import ModuleContext


MAJOR = np.array(
    [6.35, 2.23, 3.48, 2.33, 4.38, 4.09, 2.52, 5.19, 2.39, 3.66, 2.29, 2.88],
    dtype=float,
)
MINOR = np.array(
    [6.33, 2.68, 3.52, 5.38, 2.60, 3.53, 2.54, 4.75, 3.98, 2.69, 3.34, 3.17],
    dtype=float,
)
NAMES = ["C", "C#", "D", "D#", "E", "F", "F#", "G", "G#", "A", "A#", "B"]


def estimate_key(chroma: np.ndarray) -> dict:
    x = np.nan_to_num(np.asarray(chroma, dtype=float))
    if x.sum() <= 0:
        return {
            "label": None,
            "tonic": None,
            "mode": None,
            "confidence": 0.0,
        }

    x = (x - x.mean()) / (x.std() + 1e-9)
    scores = []
    for index in range(12):
        for mode, profile in (("major", MAJOR), ("minor", MINOR)):
            p = np.roll(profile, index)
            p = (p - p.mean()) / (p.std() + 1e-9)
            scores.append((float(np.mean(x * p)), index, mode))
    scores.sort(reverse=True)

    best, second = scores[0], scores[1]
    margin = max(0.0, best[0] - second[0])
    confidence = max(0.0, min(1.0, margin / 0.25))
    tonic = NAMES[best[1]]

    return {
        "label": f"{tonic} {best[2]}",
        "tonic": tonic,
        "mode": best[2],
        "confidence": round(confidence, 4),
        "score": round(best[0], 6),
        "margin": round(margin, 6),
        "candidate_scores": [
            {
                "label": f"{NAMES[index]} {mode}",
                "score": round(score, 6),
            }
            for score, index, mode in scores
        ],
    }


def _temporal_key_windows(
    chroma: np.ndarray,
    sr: int,
    *,
    hop_length: int = 512,
    chunk_seconds: float = 10.0,
) -> list[dict]:
    matrix = np.asarray(chroma, dtype=np.float64)
    if matrix.ndim != 2 or matrix.shape[1] <= 0:
        return []

    frames_per_chunk = max(
        1,
        int(round(float(chunk_seconds) * float(sr) / float(hop_length))),
    )
    rows = []
    for start in range(0, matrix.shape[1], frames_per_chunk):
        stop = min(matrix.shape[1], start + frames_per_chunk)
        if stop <= start:
            continue
        window = estimate_key(np.nanmean(matrix[:, start:stop], axis=1))
        rows.append(
            {
                "start_seconds": round(start * hop_length / float(sr), 3),
                "end_seconds": round(stop * hop_length / float(sr), 3),
                "label": window.get("label"),
                "confidence": window.get("confidence"),
                "margin": window.get("margin"),
            }
        )
    return rows


def run(context: ModuleContext) -> dict:
    import librosa

    hop_length = 512
    chroma = librosa.feature.chroma_cqt(
        y=context.y,
        sr=context.sr,
        hop_length=hop_length,
    )
    result = estimate_key(np.nanmean(chroma, axis=1))
    temporal = _temporal_key_windows(
        chroma,
        context.sr,
        hop_length=hop_length,
        chunk_seconds=10.0,
    )
    global_label = result.get("label")
    labelled = [row for row in temporal if row.get("label")]
    matching = [
        row for row in labelled
        if row.get("label") == global_label
    ]
    stability = len(matching) / len(labelled) if labelled else None

    return {
        "raw": {
            "mean_chroma": np.nanmean(chroma, axis=1).round(8).tolist(),
            "candidate_scores": result.get("candidate_scores", []),
            "temporal": {
                "chunk_seconds": 10.0,
                "windows": temporal,
            },
        },
        "normalized": {
            **{
                key: value
                for key, value in result.items()
                if key != "candidate_scores"
            },
            "temporal_stability": (
                round(float(stability), 6)
                if stability is not None
                else None
            ),
            "temporal_window_count": len(labelled),
        },
        "calibrated": {
            "status": "heuristic",
            "method": "ks_best_vs_second_margin",
            "confidence": result.get("confidence"),
        },
        "engine": {"version": getattr(librosa, "__version__", None)},
    }
