from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path

import numpy as np

from module import ModuleContext, file_sha256
from ezstudio.runtime.profile_phase_probe import mark as phase_mark
from runtime_deps import prepare_profile_imports


def _temporal_summary(
    matrix: np.ndarray,
    labels: list[str],
    order: np.ndarray,
    top_n: int = 25,
) -> dict[str, dict]:
    if matrix.ndim != 2 or matrix.shape[1] != len(labels):
        return {}
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
    phase_mark("passt", "dependency_start")
    prepare_profile_imports()

    try:
        import librosa
        from panns_inference import labels
    except Exception as exc:
        raise RuntimeError(
            f"passt_parent_dependency_unavailable:{type(exc).__name__}:{exc}"
        ) from exc

    phase_mark("passt", "dependency_done")
    passt_python = Path(
        os.getenv(
            "EZSTUDIO_PASST_PYTHON",
            r"H:\EZStudio_plateforme\var\runtime\venvs\passt-r3b12\Scripts\python.exe",
        )
    )
    if not passt_python.is_file():
        raise RuntimeError(f"passt_isolated_python_missing:{passt_python}")

    checkpoint = (
        context.ai_models_root
        / "audio"
        / "semantic"
        / "passt"
        / "passt-s-f128-p16-s10-ap.476-swa.pt"
    )
    if not checkpoint.is_file():
        raise RuntimeError(f"passt_checkpoint_missing:{checkpoint}")

    torch_home = Path(
        os.getenv(
            "EZSTUDIO_PASST_TORCH_HOME",
            r"H:\EZStudio_plateforme\apps\ezs_lab\var\runtime\cache\passt-torch-home",
        )
    )
    torch_home.mkdir(parents=True, exist_ok=True)
    cached = checkpoint

    phase_mark("passt", "resample_start")
    audio = context.resampled_audio(32000)

    phase_mark("passt", "resample_done")
    worker = Path(__file__).resolve().parents[1] / "passt_worker.py"
    if not worker.is_file():
        raise RuntimeError(f"passt_worker_missing:{worker}")

    tmp_root = Path(
        os.getenv(
            "EZSTUDIO_TMP_ROOT",
            r"H:\EZStudio_plateforme\apps\ezs_lab\var\tmp",
        )
    )
    scratch = tmp_root / "profile" / "passt"
    scratch.mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory(
        prefix="r3b12-passt-",
        dir=str(scratch),
    ) as tmp_dir:
        tmp = Path(tmp_dir)
        audio_path = tmp / "audio.f32"
        output_path = tmp / "result.json"
        audio_path.write_bytes(audio.tobytes(order="C"))

        command = [
            str(passt_python),
            str(worker),
            "--audio-f32",
            str(audio_path),
            "--checkpoint",
            str(checkpoint),
            "--output",
            str(output_path),
            "--torch-home",
            str(torch_home),
        ]
        phase_mark("passt", "subprocess_start")
        completed = subprocess.run(
            command,
            check=False,
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
        )
        phase_mark("passt", "subprocess_done")
        if completed.returncode != 0:
            message = (
                (completed.stderr or "").strip()
                or (completed.stdout or "").strip()
                or f"exit={completed.returncode}"
            )
            raise RuntimeError("passt_subprocess_failed:" + message[-4000:])

        child = json.loads(output_path.read_text(encoding="utf-8"))

    phase_mark("passt", "postprocess_start")
    scores = np.asarray(child.get("scores") or [], dtype=np.float64)
    per_chunk_scores = np.asarray(
        child.get("per_chunk_scores") or [],
        dtype=np.float64,
    )
    label_list = [str(item) for item in labels]
    if scores.size != len(label_list):
        raise RuntimeError(
            f"passt_unexpected_scores:{scores.size}:labels={len(label_list)}"
        )

    order = np.argsort(scores)[::-1]
    top = [
        {
            "label": label_list[int(index)],
            "score": round(float(scores[int(index)]), 6),
            "score_type": "isolated_cuda_sigmoid_mean",
        }
        for index in order[:25]
    ]

    phase_mark("passt", "postprocess_done")
    return {
        "raw": {
            "audioset_scores": {
                label_list[index]: round(float(scores[index]), 8)
                for index in range(len(scores))
            },
            "chunk_count": int(child.get("chunk_count") or 0),
            "chunk_seconds": float(child.get("chunk_seconds") or 10.0),
            "temporal": {
                "audioset": _temporal_summary(
                    per_chunk_scores,
                    label_list,
                    order,
                ),
            },
            "isolated_runtime": {
                "python": str(passt_python),
                "torch": child.get("torch"),
                "torchaudio": child.get("torchaudio"),
                "hear21passt": child.get("hear21passt"),
                "cuda": child.get("cuda"),
                "device_name": child.get("device_name"),
            },
            "cache_checkpoint": str(cached),
        },
        "normalized": {
            "audioset": top,
        },
        "model": {
            "id": "PaSST-s-f128-p16-s10-ap476-swa",
            "path": str(checkpoint),
            "sha256": file_sha256(checkpoint),
        },
        "engine": {
            "version": child.get("hear21passt"),
        },
        "device": "cuda:0-isolated",
    }
