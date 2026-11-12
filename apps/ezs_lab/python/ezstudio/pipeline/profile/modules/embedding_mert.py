from __future__ import annotations

from pathlib import Path
import os
import gc

import numpy as np

from module import ModuleContext
from runtime_deps import prepare_profile_imports


def _chunks(
    y: np.ndarray,
    sr: int,
    *,
    seconds: float = 10.0,
    max_chunks: int = 6,
) -> list[np.ndarray]:
    y = np.asarray(y, dtype=np.float32)
    length = max(1, int(round(seconds * sr)))
    if len(y) <= length:
        padded = np.zeros(length, dtype=np.float32)
        padded[: len(y)] = y
        return [padded]
    starts = np.linspace(0, len(y) - length, num=max_chunks, dtype=int)
    return [y[int(start): int(start) + length] for start in starts]


def _run(context: ModuleContext, model_dir: Path) -> dict:
    prepare_profile_imports()
    try:
        import librosa
        import torch
        import transformers
        from transformers import AutoModel, Wav2Vec2FeatureExtractor
    except Exception as exc:
        raise RuntimeError(
            f"mert_dependency_unavailable:{type(exc).__name__}:{exc}"
        ) from exc

    if not torch.cuda.is_available():
        raise RuntimeError("mert_cuda_required")
    if not model_dir.is_dir():
        raise RuntimeError(f"mert_model_missing:{model_dir}")

    extractor = Wav2Vec2FeatureExtractor.from_pretrained(
        str(model_dir),
        local_files_only=True,
        trust_remote_code=True,
    )
    target_sr = int(getattr(extractor, "sampling_rate", 24000) or 24000)
    audio = librosa.resample(
        np.asarray(context.y, dtype=np.float32),
        orig_sr=context.sr,
        target_sr=target_sr,
    ).astype(np.float32, copy=False)

    # MERT_R3B14_AUTOCAST:
    # Keep model parameters in their native float32 form. Static conversion of
    # the whole MERT model to float16 causes internal feature-extractor paths to
    # create float32 tensors against fp16 convolution weights. CUDA autocast
    # handles mixed precision operator-by-operator instead.
    gc.collect()
    torch.cuda.empty_cache()

    model = AutoModel.from_pretrained(
        str(model_dir),
        local_files_only=True,
        trust_remote_code=True,
    ).to("cuda")
    model.eval()

    chunks = _chunks(audio, target_sr)
    batch_size = max(1, int(os.getenv("EZSTUDIO_MERT_BATCH_SIZE", "2")))
    chunk_embeddings: list[list[float]] = []
    hidden_size = None
    with torch.inference_mode():
        for start in range(0, len(chunks), batch_size):
            batch_chunks = chunks[start:start + batch_size]
            inputs = extractor(
                batch_chunks,
                sampling_rate=target_sr,
                return_tensors="pt",
                padding=True,
            )
            input_values = inputs["input_values"].to(
                device="cuda",
                dtype=torch.float32,
                non_blocking=True,
            )
            with torch.autocast(
                device_type="cuda",
                dtype=torch.float16,
            ):
                outputs = model(input_values)
            pooled_batch = (
                outputs.last_hidden_state
                .float()
                .mean(dim=1)
                .detach()
                .cpu()
                .numpy()
            )
            for pooled in pooled_batch:
                hidden_size = int(pooled.size)
                chunk_embeddings.append(
                    [round(float(value), 7) for value in pooled]
                )

    if not chunk_embeddings:
        raise RuntimeError("mert_no_embeddings")

    matrix = np.asarray(chunk_embeddings, dtype=np.float64)
    scene = np.mean(matrix, axis=0)
    temporal_std = np.std(matrix, axis=0)

    result = {
        "raw": {
            "sampling_rate": target_sr,
            "chunk_seconds": 10.0,
            "chunk_count": len(chunk_embeddings),
            "hidden_size": hidden_size,
            "chunk_embeddings": chunk_embeddings,
        },
        "normalized": {
            "scene_embedding": [
                round(float(value), 7) for value in scene
            ],
            "temporal_std_mean": round(float(np.mean(temporal_std)), 7),
            "embedding_norm": round(float(np.linalg.norm(scene)), 7),
        },
        "engine": {
            "version": getattr(transformers, "__version__", None),
        },
        "model": {
            "path": str(model_dir),
            "weights_dtype": str(next(model.parameters()).dtype),
            "inference_dtype": "cuda-autocast-float16",
        },
        "device": "cuda:0",
        "performance": {
            "batch_size": batch_size,
            "chunk_count": len(chunks),
            "inference_dtype": "cuda-autocast-float16",
        },
    }

    del model
    del extractor
    gc.collect()
    torch.cuda.empty_cache()
    return result


def run_95m(context: ModuleContext) -> dict:
    return _run(
        context,
        context.ai_models_root
        / "audio"
        / "embeddings"
        / "mert"
        / "MERT-v1-95M",
    )


def run_330m(context: ModuleContext) -> dict:
    return _run(
        context,
        context.ai_models_root
        / "audio"
        / "embeddings"
        / "mert"
        / "MERT-v1-330M",
    )
