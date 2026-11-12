from __future__ import annotations

import os
import sys
from pathlib import Path
from typing import Any

import numpy as np

from ezstudio.runtime.profile_phase_probe import mark as phase_mark
from ezstudio.runtime.decision import apply_decision
from ezstudio.runtime.module_config import ModuleConfigDocument


def _dependency_root() -> Path:
    return Path(
        os.getenv(
            "EZSTUDIO_PROFILE_DEP_ROOT",
            r"H:\EZStudio_plateforme\apps\ezs_lab\var\runtime\deps\profile-r3b10",
        )
    )


def _prepare_imports() -> None:
    dep_root = _dependency_root()
    if dep_root.is_dir() and str(dep_root) not in sys.path:
        sys.path.append(str(dep_root))


def _model_dir(model_root: Path, spec: ModuleConfigDocument) -> Path:
    engine = spec.data.get("engine") or {}
    explicit = engine.get("model_path")
    if isinstance(explicit, str) and explicit.strip():
        p = Path(explicit)
        return p if p.is_absolute() else model_root / p
    model_id = str(engine.get("model") or "").strip()
    if not model_id:
        raise ValueError("clap_module_config_model_required")
    return model_root / model_id.rsplit("/", 1)[-1]


def _taxonomy(spec: ModuleConfigDocument) -> dict[str, list[str]]:
    taxonomy = spec.data.get("taxonomy") or {}
    families = taxonomy.get("families") or {}
    return {
        str(family): [str(label) for label in labels]
        for family, labels in families.items()
        if isinstance(labels, list)
    }


def _prompts(spec: ModuleConfigDocument) -> tuple[list[str], list[tuple[str, str]]]:
    taxonomy = _taxonomy(spec)
    templates = ((spec.data.get("taxonomy") or {}).get("prompts") or {})
    prompts: list[str] = []
    index: list[tuple[str, str]] = []

    for family, labels in taxonomy.items():
        template = templates.get(family)
        if not isinstance(template, str) or "{label}" not in template:
            raise ValueError(f"clap_prompt_template_invalid:{family}")
        for label in labels:
            prompts.append(template.format(label=label))
            index.append((family, label))
    return prompts, index


def _even_chunks(
    y: np.ndarray,
    sr: int,
    *,
    chunk_seconds: float,
    max_chunks: int,
) -> list[np.ndarray]:
    y = np.asarray(y, dtype=np.float32)
    chunk = max(1, int(round(chunk_seconds * sr)))

    if len(y) <= chunk:
        padded = np.zeros(chunk, dtype=np.float32)
        padded[: len(y)] = y
        return [padded]

    max_start = len(y) - chunk
    starts = np.linspace(0, max_start, num=max_chunks, dtype=int)
    return [y[int(start): int(start) + chunk] for start in starts]


def family_rows_from_logits(
    logits: np.ndarray,
    index: list[tuple[str, str]],
    family: str,
) -> list[dict[str, Any]]:
    """Compatibility scoring primitive.

    This deliberately reproduces R3B35C's category-relative softmax.
    The decision layer is separate and comes from the Module Config.
    """
    positions = [i for i, item in enumerate(index) if item[0] == family]
    if not positions:
        return []

    values = np.asarray([logits[i] for i in positions], dtype=np.float64)
    values = values - np.max(values)
    probs = np.exp(values)
    probs /= np.sum(probs) + 1e-12

    order = np.argsort(probs)[::-1]
    rows: list[dict[str, Any]] = []
    for relative_index in order:
        absolute_index = positions[int(relative_index)]
        rows.append(
            {
                "label": index[absolute_index][1],
                "score": round(float(probs[int(relative_index)]), 4),
                "score_type": "category_relative_softmax",
            }
        )
    return rows


def _family_policy(spec: ModuleConfigDocument, family: str) -> dict[str, Any]:
    decision = spec.data.get("decision") or {}
    families = decision.get("families") or {}
    policy = families.get(family)
    if not isinstance(policy, dict):
        raise ValueError(f"clap_decision_policy_missing:{family}")
    return policy


def _temporal_family_summary(
    chunk_logits: list[np.ndarray],
    index: list[tuple[str, str]],
    family: str,
    labels: list[str],
) -> dict[str, dict[str, Any]]:
    positions = [i for i, item in enumerate(index) if item[0] == family]
    if not positions or not chunk_logits:
        return {}

    family_labels = [index[i][1] for i in positions]
    wanted = set(labels)
    rows: dict[str, list[float]] = {label: [] for label in labels}

    for logits in chunk_logits:
        values = np.asarray([logits[i] for i in positions], dtype=np.float64)
        values = values - np.max(values)
        probs = np.exp(values)
        probs /= np.sum(probs) + 1e-12
        for label, value in zip(family_labels, probs):
            if label in wanted:
                rows[label].append(float(value))

    result: dict[str, dict[str, Any]] = {}
    for label, values_list in rows.items():
        if not values_list:
            continue
        values = np.asarray(values_list, dtype=np.float64)
        result[label] = {
            "mean": round(float(np.mean(values)), 6),
            "median": round(float(np.median(values)), 6),
            "max": round(float(np.max(values)), 6),
            "std": round(float(np.std(values)), 6),
            "support_at_0_5": round(float(np.mean(values >= 0.5)), 6),
            "chunk_count": int(values.size),
            "score_semantics": "category_relative_softmax",
        }
    return result


def run_clap_zero_shot(
    source: Path,
    model_root: Path,
    spec: ModuleConfigDocument,
) -> tuple[dict[str, Any], list[str]]:
    if spec.engine_adapter != "clap-zero-shot":
        raise ValueError(
            f"clap_adapter_mismatch:{spec.engine_adapter}"
        )

    parameters = spec.data.get("parameters") or {}
    sample_rate = int(parameters.get("sample_rate", 48000))
    chunk_seconds = float(parameters.get("chunk_seconds", 10.0))
    max_chunks = int(parameters.get("max_chunks", 8))
    if sample_rate <= 0 or chunk_seconds <= 0 or max_chunks <= 0:
        raise ValueError("clap_module_config_parameters_invalid")

    taxonomy = _taxonomy(spec)
    families = tuple(taxonomy.keys())
    result: dict[str, Any] = {
        "available": False,
        "backend": "clap-zero-shot",
        "model": str((spec.data.get("engine") or {}).get("model") or ""),
        "score_semantics": str(
            (spec.data.get("output") or {}).get(
                "score_semantics",
                "category_relative_softmax",
            )
        ),
        "module_config": spec.provenance(),
    }
    for family in families:
        result[family] = []

    warnings: list[str] = []
    phase_mark("clap", "dependency_start")
    _prepare_imports()

    model_dir = _model_dir(model_root, spec)
    if not model_dir.is_dir():
        warnings.append(f"profile_clap_model_missing:{model_dir}")
        return result, warnings

    try:
        phase_mark("clap", "import_librosa_start")
        import librosa
        phase_mark("clap", "import_librosa_done")
        phase_mark("clap", "import_torch_start")
        import torch
        phase_mark("clap", "import_torch_done")
        phase_mark("clap", "import_transformers_start")
        from transformers import ClapModel, ClapProcessor
        phase_mark("clap", "import_transformers_done")
    except Exception as exc:
        warnings.append(
            f"profile_clap_dependency_unavailable:{type(exc).__name__}:{exc}"
        )
        return result, warnings

    phase_mark("clap", "dependency_done")
    if not torch.cuda.is_available():
        warnings.append("profile_clap_cuda_required")
        return result, warnings

    try:
        phase_mark("clap", "model_start")
        processor = ClapProcessor.from_pretrained(
            str(model_dir),
            local_files_only=True,
        )
        model = ClapModel.from_pretrained(
            str(model_dir),
            local_files_only=True,
        ).to("cuda")
        model.eval()
        phase_mark("clap", "model_done")

        phase_mark("clap", "decode_start")
        y, sr = librosa.load(str(source), sr=sample_rate, mono=True)
        phase_mark("clap", "decode_done")
        chunks = _even_chunks(
            y,
            sr,
            chunk_seconds=chunk_seconds,
            max_chunks=max_chunks,
        )

        prompts, index = _prompts(spec)
        accumulated = None
        chunk_logits: list[np.ndarray] = []

        phase_mark("clap", "inference_start")
        with torch.inference_mode():
            for chunk in chunks:
                inputs = processor(
                    text=prompts,
                    audio=chunk,
                    sampling_rate=sr,
                    return_tensors="pt",
                    padding=True,
                )
                inputs = {
                    key: value.to("cuda") if hasattr(value, "to") else value
                    for key, value in inputs.items()
                }
                output = model(**inputs)
                logits = (
                    output.logits_per_audio.detach()
                    .float()
                    .cpu()
                    .numpy()[0]
                )
                chunk_logits.append(logits)
                accumulated = (
                    logits
                    if accumulated is None
                    else accumulated + logits
                )

        phase_mark("clap", "inference_done")
        if accumulated is None:
            warnings.append("profile_clap_no_audio_chunks")
            return result, warnings

        phase_mark("clap", "postprocess_start")
        mean_logits = accumulated / float(len(chunks))
        decisions: dict[str, Any] = {}
        for family in families:
            ranked = family_rows_from_logits(
                mean_logits,
                index,
                family,
            )
            decision = apply_decision(
                ranked,
                _family_policy(spec, family),
            )
            selected = list(decision.get("selected") or [])
            result[family] = selected
            decisions[family] = {
                key: value
                for key, value in decision.items()
                if key not in {"selected", "rejected"}
            }

        result["temporal"] = {
            family: _temporal_family_summary(
                chunk_logits,
                index,
                family,
                [row["label"] for row in result[family]],
            )
            for family in families
        }
        result["decisions"] = decisions
        result["available"] = True
        phase_mark("clap", "postprocess_done")
        result["chunks"] = len(chunks)
        result["chunk_seconds"] = chunk_seconds
        result["sample_rate"] = sample_rate

        try:
            import transformers
            result["transformers_version"] = transformers.__version__
        except Exception:
            pass

        return result, warnings

    except Exception as exc:
        warnings.append(
            f"profile_clap_inference_error:{type(exc).__name__}:{exc}"
        )
        return result, warnings
