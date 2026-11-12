from __future__ import annotations

import os
import tempfile
import wave
from pathlib import Path
import sys

import numpy as np

from module import ModuleContext, file_sha256
from runtime_deps import prepare_profile_imports

def _temporary_wav(context: ModuleContext) -> Path:
    project_root = Path(__file__).resolve().parents[5]
    tmp_root = Path(
        os.getenv(
            "EZSTUDIO_TMP_ROOT",
            str(project_root / "var" / "tmp"),
        )
    )
    target_dir = tmp_root / "profile" / "madmom"
    target_dir.mkdir(parents=True, exist_ok=True)

    fd, raw_path = tempfile.mkstemp(
        prefix="key-",
        suffix=".wav",
        dir=str(target_dir),
    )
    os.close(fd)
    path = Path(raw_path)

    audio = np.asarray(context.y, dtype=np.float32)
    if int(context.sr) != MADMOM_SAMPLE_RATE:
        import librosa
        audio = librosa.resample(
            audio,
            orig_sr=int(context.sr),
            target_sr=MADMOM_SAMPLE_RATE,
        ).astype(np.float32, copy=False)

    audio = np.clip(audio, -1.0, 1.0)
    pcm = np.asarray(audio * 32767.0, dtype="<i2")

    with wave.open(str(path), "wb") as handle:
        handle.setnchannels(1)
        handle.setsampwidth(2)
        handle.setframerate(MADMOM_SAMPLE_RATE)
        handle.writeframes(pcm.tobytes())

    return path


MADMOM_SAMPLE_RATE = 44100

KEY_LABELS = [
    "A major", "Bb major", "B major", "C major", "Db major", "D major",
    "Eb major", "E major", "F major", "F# major", "G major", "Ab major",
    "A minor", "Bb minor", "B minor", "C minor", "C# minor", "D minor",
    "D# minor", "E minor", "F minor", "F# minor", "G minor", "G# minor",
]


def _predict_cuda_custom_weights(processor, wav_path: Path) -> np.ndarray:
    """Run historical NumPy weights through Torch CUDA, preserving Madmom frontend/postprocessing.

    Explicit opt-in only. Never changes the active model files or NumPy processor.
    """
    import torch
    from madmom_infer.torch.ml.nn import to_torch

    if not torch.cuda.is_available():
        raise RuntimeError("madmom_cuda_unavailable")
    stages = list(processor.processors)
    neural_indexes = [
        index for index, stage in enumerate(stages)
        if type(stage).__name__ in ("NeuralNetworkEnsemble", "NeuralNetwork")
    ]
    if len(neural_indexes) != 1 or neural_indexes[0] != len(stages) - 3:
        raise RuntimeError("madmom_cuda_unexpected_pipeline")
    index = neural_indexes[0]
    features = str(wav_path)
    for stage in stages[:index]:
        features = stage(features)
    features = np.asarray(features, dtype=np.float32)

    network = to_torch(stages[index]).eval().to("cuda")
    torch.backends.cuda.matmul.allow_tf32 = False
    torch.backends.cudnn.allow_tf32 = False
    with torch.inference_mode():
        output = network(torch.as_tensor(features, device="cuda"))
        if not isinstance(output, torch.Tensor):
            raise RuntimeError("madmom_cuda_unexpected_output")
        prediction = output.detach().cpu().numpy()
    for stage in stages[index + 1:]:
        prediction = stage(prediction)
    return np.asarray(prediction, dtype=np.float64)


def _run(context: ModuleContext, model_files: list[Path]) -> dict:
    roots = prepare_profile_imports()

    # MAD_MOM_R3B14_IMPORT_PRECEDENCE:
    # the repaired madmom_infer package in profile-r3b13 must win over any
    # globally installed/older madmom_infer package. Other PROFILE dependency
    # roots remain appended by prepare_profile_imports().
    if roots:
        primary = str(roots[0])
        while primary in sys.path:
            sys.path.remove(primary)
        sys.path.insert(0, primary)

    try:
        import madmom_infer
        from madmom_infer.features.key import CNNKeyRecognitionProcessor
    except Exception as exc:
        raise RuntimeError(
            f"madmom_infer_unavailable:{type(exc).__name__}:{exc}"
        ) from exc

    missing = [str(path) for path in model_files if not path.is_file()]
    if missing:
        raise RuntimeError("madmom_key_models_missing:" + ",".join(missing))

    processor = CNNKeyRecognitionProcessor(
        nn_files=[str(path) for path in model_files],
        backend="numpy",
    )
    backend = os.getenv('EZSTUDIO_PROFILE_MADMOM_BACKEND', 'numpy').strip().lower()
    if backend not in ('numpy', 'cuda'):
        raise RuntimeError('madmom_invalid_backend:' + backend)
    wav_path = _temporary_wav(context)
    try:
        if backend == 'cuda':
            prediction = _predict_cuda_custom_weights(processor, wav_path)
        else:
            prediction = np.asarray(processor(str(wav_path)), dtype=np.float64)
    finally:
        try:
            wav_path.unlink(missing_ok=True)
        except Exception:
            pass
    vector = np.ravel(prediction)
    if vector.size != len(KEY_LABELS):
        raise RuntimeError(
            f"madmom_key_unexpected_shape:{tuple(prediction.shape)}"
        )

    order = np.argsort(vector)[::-1]
    candidates = [
        {
            "label": KEY_LABELS[int(index)],
            "score": round(float(vector[int(index)]), 6),
        }
        for index in order
    ]
    best = candidates[0]
    second = candidates[1]
    margin = max(0.0, float(best["score"]) - float(second["score"]))

    return {
        "raw": {
            "probabilities": {
                KEY_LABELS[index]: round(float(vector[index]), 8)
                for index in range(len(KEY_LABELS))
            },
            "candidate_scores": candidates,
        },
        "normalized": {
            "label": best["label"],
            "confidence": round(float(best["score"]), 6),
            "margin": round(margin, 6),
        },
        "calibrated": {
            "status": "model_probability",
            "method": "madmom_cnn_softmax",
            "confidence": round(float(best["score"]), 6),
        },
        "engine": {
            "version": getattr(madmom_infer, "__version__", None),
        },
        "model": {
            "sha256": {
                path.name: file_sha256(path)
                for path in model_files
            },
        },
        "device": "cuda:torch" if backend == "cuda" else "cpu:numpy",
    }


def run_2017(context: ModuleContext) -> dict:
    root = (
        context.ai_models_root
        / "audio"
        / "tonal"
        / "madmom-key-cnn"
        / "2017"
    )
    return _run(
        context,
        [root / f"key_cnn_{index}.pkl" for index in range(1, 5)],
    )


def run_2018(context: ModuleContext) -> dict:
    root = (
        context.ai_models_root
        / "audio"
        / "tonal"
        / "madmom-key-cnn"
        / "2018"
    )
    return _run(context, [root / "key_cnn.pkl"])
