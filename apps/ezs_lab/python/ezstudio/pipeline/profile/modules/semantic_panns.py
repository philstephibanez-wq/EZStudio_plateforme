from __future__ import annotations

from pathlib import Path

import numpy as np

from module import ModuleContext, file_sha256
from runtime_deps import prepare_profile_imports


def _chunks(
    y: np.ndarray,
    sr: int,
    seconds: float = 10.0,
    max_chunks: int = 8,
) -> list[np.ndarray]:
    length = int(round(seconds * sr))
    y = np.asarray(y, dtype=np.float32)
    if len(y) <= length:
        padded = np.zeros(length, dtype=np.float32)
        padded[: len(y)] = y
        return [padded]
    starts = np.linspace(0, len(y) - length, num=max_chunks, dtype=int)
    return [y[int(start): int(start) + length] for start in starts]


def _temporal_summary(
    matrix: np.ndarray,
    labels: list[str],
    order: np.ndarray,
    top_n: int = 25,
) -> dict[str, dict]:
    result: dict[str, dict] = {}
    for index in order[:top_n]:
        idx = int(index)
        values = np.asarray(matrix[:, idx], dtype=np.float64)
        per_chunk_order = np.argsort(matrix, axis=1)[:, ::-1]
        top5_support = np.mean(
            np.any(per_chunk_order[:, :5] == idx, axis=1)
        )
        top10_support = np.mean(
            np.any(per_chunk_order[:, :10] == idx, axis=1)
        )
        mean_vector = np.mean(matrix, axis=0)
        rank = int(np.where(np.argsort(mean_vector)[::-1] == idx)[0][0]) + 1
        class_count = int(mean_vector.size)
        rank_percentile = (
            1.0
            if class_count <= 1
            else 1.0 - ((rank - 1) / float(class_count - 1))
        )

        result[labels[idx]] = {
            "mean": round(float(np.mean(values)), 6),
            "median": round(float(np.median(values)), 6),
            "max": round(float(np.max(values)), 6),
            "std": round(float(np.std(values)), 6),
            "support_at_0_5": round(float(np.mean(values >= 0.5)), 6),
            "top5_support": round(float(top5_support), 6),
            "top10_support": round(float(top10_support), 6),
            "rank": rank,
            "class_count": class_count,
            "rank_percentile": round(float(rank_percentile), 6),
            "chunk_count": int(values.size),
        }
    return result


def run(context: ModuleContext) -> dict:
    prepare_profile_imports()
    try:
        import librosa
        import torch
        import panns_inference
        from panns_inference import AudioTagging, labels
    except Exception as exc:
        raise RuntimeError(
            f"panns_dependency_unavailable:{type(exc).__name__}:{exc}"
        ) from exc

    if not torch.cuda.is_available():
        raise RuntimeError("panns_cuda_required")

    checkpoint = (
        context.ai_models_root
        / "audio"
        / "semantic"
        / "panns"
        / "Cnn14_mAP=0.431.pth"
    )
    if not checkpoint.is_file():
        raise RuntimeError(f"panns_checkpoint_missing:{checkpoint}")

    audio = context.resampled_audio(32000)

    tagger = AudioTagging(
        checkpoint_path=str(checkpoint),
        device="cuda",
    )

    chunks = _chunks(audio, 32000)
    batch = np.stack(chunks, axis=0).astype(np.float32, copy=False)
    clipwise, embedding = tagger.inference(batch)
    score_rows = [
        np.asarray(row, dtype=np.float64)
        for row in np.asarray(clipwise)
    ]
    embedding_rows = [
        np.asarray(row, dtype=np.float64)
        for row in np.asarray(embedding)
    ]

    score_matrix = np.stack(score_rows, axis=0)
    scores = np.mean(score_matrix, axis=0)
    embedding = np.mean(np.stack(embedding_rows, axis=0), axis=0)
    label_list = [str(item) for item in labels]
    if len(label_list) != len(scores):
        label_list = [f"audioset_{index}" for index in range(len(scores))]

    order = np.argsort(scores)[::-1]
    top = [
        {
            "label": label_list[int(index)],
            "score": round(float(scores[int(index)]), 6),
            "score_type": "clip_probability_mean",
        }
        for index in order[:25]
    ]

    return {
        "raw": {
            "audioset_scores": {
                label_list[index]: round(float(scores[index]), 8)
                for index in range(len(scores))
            },
            "scene_embedding": [
                round(float(value), 7) for value in embedding
            ],
            "chunk_count": len(score_rows),
            "chunk_seconds": 10.0,
            "temporal": {
                "audioset": _temporal_summary(
                    score_matrix,
                    label_list,
                    order,
                ),
            },
        },
        "normalized": {
            "audioset": top,
        },
        "model": {
            "id": "PANNs-Cnn14-AudioSet-mAP0.431",
            "path": str(checkpoint),
            "sha256": file_sha256(checkpoint),
        },
        "engine": {
            "version": getattr(panns_inference, "__version__", None),
        },
        "device": "cuda:0",
    }
