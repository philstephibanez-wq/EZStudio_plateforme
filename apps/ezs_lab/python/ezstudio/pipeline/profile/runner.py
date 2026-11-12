from __future__ import annotations

import argparse
import hashlib
import json
import os
import platform
import sys
import time
import uuid
from pathlib import Path

_EZSTUDIO_PROFILE_ROOT = Path(__file__).resolve().parent
_EZSTUDIO_PYTHON_ROOT = Path(__file__).resolve().parents[3]

for _bootstrap_path in (
    _EZSTUDIO_PROFILE_ROOT,
    _EZSTUDIO_PYTHON_ROOT,
):
    if str(_bootstrap_path) not in sys.path:
        sys.path.insert(0, str(_bootstrap_path))

import numpy as np

from module import ModuleContext, ModuleConfig
from module_registry import ModuleRegistry
from preset_registry_factory import build_registry_from_phase_preset
from ezstudio.runtime.preset_loader import PresetLoader

R3B13_PROFILE_CONSOLIDATION = True
R3B14_PROFILE_REPAIR = True
SCHEMA = "ezstudio.profile.v1"
R3B15B_MADMOM_44100 = True
R3B15_PROFILE_MODULES = True
R3B16_PROFILE_CONFIDENCE = True
R3B17_PROFILE_CONFIDENCE_V2 = True
R3B18_PROFILE_CONFIDENCE_V3 = True
R3B19_PROFILE_SEMANTIC_ROLES = True
R3B31_PRESET_EVOLUTION = True
R3B35C_CANONICAL_PROFILE = True
R3B35_PROFILE_FAST_STUDY = True
R3B35B_CLAP_CHOIRS = True


def write_progress(path: Path, percent: int, message: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = json.dumps(
        {
            "percent": int(percent),
            "progress": int(percent),
            "message": message,
            "updated_at": time.time(),
        },
        ensure_ascii=False,
        indent=2,
    )

    tmp = path.with_name(
        f"{path.name}.{os.getpid()}.{uuid.uuid4().hex}.tmp"
    )
    tmp.write_text(payload, encoding="utf-8")

    last_error: PermissionError | None = None
    try:
        for attempt in range(10):
            try:
                os.replace(tmp, path)
                return
            except PermissionError as exc:
                last_error = exc
                if attempt >= 9:
                    break
                time.sleep(0.05 * (attempt + 1))
        if last_error is not None:
            raise last_error
    finally:
        if tmp.exists():
            try:
                tmp.unlink()
            except OSError:
                pass


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def _module_by_id(
    module_registry_result: dict,
    module_id: str,
) -> dict | None:
    for record in module_registry_result.get("modules") or []:
        if record.get("id") == module_id:
            return record
    return None


def _normalized(record: dict | None) -> dict:
    if not isinstance(record, dict):
        return {}
    value = record.get("normalized")
    return value if isinstance(value, dict) else {}


def _legacy_projection(
    module_registry_result: dict,
    duration: float,
) -> tuple[dict, dict, list[str]]:
    rhythm = _module_by_id(module_registry_result, "rhythm.librosa-beat")
    meter = _module_by_id(module_registry_result, "meter.chords-shared-r9")
    tonal_consensus = (
        (module_registry_result.get("consensus") or {}).get("tonal") or {}
    )
    descriptors = _module_by_id(
        module_registry_result,
        "descriptors.librosa-lowlevel",
    )
    tuning = _module_by_id(
        module_registry_result,
        "tuning.librosa-reference-pitch",
    )
    clap = _module_by_id(
        module_registry_result,
        "semantic.clap-open-vocabulary",
    )

    rhythm_n = _normalized(rhythm)
    meter_n = _normalized(meter)
    descriptors_n = _normalized(descriptors)
    tuning_n = _normalized(tuning)
    tempo_metric = (
        (module_registry_result.get("consensus") or {})
        .get("tempo_metric")
        or {}
    )

    tonal_label = tonal_consensus.get("label")
    tonic = None
    mode = None
    if isinstance(tonal_label, str) and " " in tonal_label:
        tonic, mode = tonal_label.rsplit(" ", 1)

    tonal_n = {
        "label": tonal_label,
        "tonic": tonic,
        "mode": mode,
        "confidence": None,
        "calibrated": False,
        "source": "profile-tonal-consensus-v1",
        "decision": tonal_consensus.get("decision"),
        "candidate_label": tonal_consensus.get("candidate_label"),
        "support": tonal_consensus.get("support"),
        "total_sources": tonal_consensus.get("total_sources"),
        "agreement_ratio": tonal_consensus.get("agreement_ratio"),
        "unanimous": tonal_consensus.get("unanimous"),
        "sources": tonal_consensus.get("sources") or [],
        "dissent": tonal_consensus.get("dissent") or [],
    }

    characteristics = {
        "duration_seconds": round(duration, 3),
        "tempo": {
            "bpm": rhythm_n.get("bpm", 0.0),
            "confidence": rhythm_n.get("confidence"),
            "source": rhythm_n.get("source", "librosa.beat"),
            "metric_level": tempo_metric,
        },
        "time_signature": meter_n,
        "key": tonal_n,
        "tuning": tuning_n,
        "dynamics": descriptors_n.get("dynamics") or {},
        "spectral": descriptors_n.get("spectral") or {},
    }

    # Compatibility projection: CLAP remains the visible open-vocabulary
    # tagging backend. Scientific truth stays in the independent module outputs.
    if clap and clap.get("status") == "ok":
        tagging = _normalized(clap)
        tagging["available"] = True
        tagging["backend"] = "clap-zero-shot"
    else:
        tagging = {
            "available": False,
            "backend": None,
            "genre": [],
            "instrumentation": [],
            "mood": [],
            "voice": [],
        }

    warnings: list[str] = []
    for record in module_registry_result.get("modules") or []:
        warnings.extend(
            str(item) for item in (record.get("warnings") or [])
        )
        if record.get("status") == "error":
            error = record.get("error") or {}
            warnings.append(
                "profile_module_error:"
                + str(record.get("id"))
                + ":"
                + str(error.get("type") or "")
                + ":"
                + str(error.get("message") or "")
            )

    return characteristics, tagging, warnings



def _profile_view(module_registry_result: dict) -> dict:
    records = module_registry_result.get("modules") or []
    consensus = module_registry_result.get("consensus") or {}

    module_rows = []
    embeddings = []
    counts = {"ok": 0, "skipped": 0, "error": 0}

    for record in records:
        status = str(record.get("status") or "error")
        counts[status] = counts.get(status, 0) + 1
        error = record.get("error") if isinstance(record.get("error"), dict) else {}
        module_rows.append(
            {
                "id": record.get("id"),
                "name": record.get("name"),
                "family": record.get("family"),
                "status": status,
                "device": record.get("device"),
                "elapsed_seconds": (record.get("timing") or {}).get("elapsed_seconds"),
                "error": error.get("message"),
                "warnings": list(record.get("warnings") or []),
            }
        )

        if record.get("family") == "embedding":
            normalized = (
                record.get("normalized")
                if isinstance(record.get("normalized"), dict)
                else {}
            )
            raw = record.get("raw") if isinstance(record.get("raw"), dict) else {}
            embeddings.append(
                {
                    "id": record.get("id"),
                    "name": record.get("name"),
                    "status": status,
                    "embedding_norm": normalized.get("embedding_norm"),
                    "temporal_std_mean": normalized.get("temporal_std_mean"),
                    "hidden_size": raw.get("hidden_size"),
                    "chunk_count": raw.get("chunk_count"),
                }
            )

    semantic = consensus.get("semantic") if isinstance(consensus.get("semantic"), dict) else {}
    audioset = semantic.get("audioset") if isinstance(semantic.get("audioset"), dict) else {}

    return {
        "counts": counts,
        "modules": module_rows,
        "tonal": consensus.get("tonal") or {},
        "tempo_metric": consensus.get("tempo_metric") or {},
        "tuning": consensus.get("tuning") or {},
        "genre_hierarchy": consensus.get("genre_hierarchy") or {},
        "vocal_profile": consensus.get("vocal_profile") or {},
        "instrumentation": consensus.get("instrumentation") or {},
        "choirs": consensus.get("choirs") or {},
        "audioset": {
            "method": audioset.get("method"),
            "confidence_method": audioset.get("confidence_method"),
            "confidence_threshold": audioset.get("confidence_threshold"),
            "calibrated": bool(audioset.get("calibrated", False)),
            "accepted": list(audioset.get("accepted") or [])[:25],
            "agreements": list(audioset.get("agreements") or [])[:25],
            "divergences": list(audioset.get("divergences") or [])[:25],
        },
        "embeddings": embeddings,
        "policy": consensus.get("policy") or {},
    }


def _model_roots() -> tuple[Path, Path]:
    ai_root = Path(os.getenv("AI_MODELS_ROOT", r"H:\AIModels"))
    profile_root = Path(
        os.getenv(
            "EZSTUDIO_PROFILE_MODELS",
            str(ai_root / "audio" / "profile"),
        )
    )
    return ai_root, profile_root


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", required=True)
    parser.add_argument("--audio-hash", required=True)
    parser.add_argument("--output-path", required=True)
    parser.add_argument("--progress-file", required=True)
    args = parser.parse_args()

    source = Path(args.source).resolve()
    output = Path(args.output_path).resolve()
    progress = Path(args.progress_file).resolve()
    os.environ["EZSTUDIO_PROFILE_OBSERVABILITY_SESSION"] = output.parent.name
    os.environ["EZS_PROFILE_FORENSIC_DIR"] = str(output.parent)
    output.parent.mkdir(parents=True, exist_ok=True)

    write_progress(progress, 3, "PROFILE: chargement")
    import librosa

    y, sr = librosa.load(str(source), sr=22050, mono=True)
    if y.size == 0:
        raise RuntimeError("profile_audio_empty")

    duration = float(librosa.get_duration(y=y, sr=sr))
    ai_root, profile_root = _model_roots()

    context = ModuleContext(
        source=source,
        audio_sha256=args.audio_hash,
        y=np.asarray(y, dtype=np.float32),
        sr=int(sr),
        model_root=profile_root,
        ai_models_root=ai_root,
    )

    preset_loader = PresetLoader(Path(__file__).resolve().parents[4] / "presets")
    loaded_phase_preset = preset_loader.load_phase(
        "profile/profile-r3b35c-compat.v2b.json"
    )
    from profile_forensic_probe import event as forensic_event
    forensic_event("preset.loaded", {"id": loaded_phase_preset.value.id,
        "revision": loaded_phase_preset.value.revision, "sha256": loaded_phase_preset.sha256})
    module_registry = build_registry_from_phase_preset(
        loaded_phase_preset,
        ai_models_root=ai_root,
        profile_models_root=profile_root,
    )
    module_registry_result = module_registry.run(
        context,
        progress=lambda percent, message: write_progress(
            progress,
            percent,
            message,
        ),
    )
    preset_manifest = module_registry.preset_manifest(
        module_registry_result.get("modules") or [],
    )

    characteristics, tagging, warnings = _legacy_projection(
        module_registry_result,
        duration,
    )
    profile_view = _profile_view(module_registry_result)
    forensic_event("projection.interpreted", {"characteristics": characteristics,
        "tagging": tagging, "warnings": warnings, "profile_view": profile_view})

    result = {
        "schema": SCHEMA,
        "audio_sha256": args.audio_hash,
        "source_sha256": sha256(source),
        "characteristics": characteristics,
        "tagging": tagging,
        "profile_view": profile_view,
        "module_registry": {
            **module_registry_result,
            "ai_models_root": str(ai_root),
            "profile_models_root": str(profile_root),
        },
        "preset": preset_manifest,
        "warnings": warnings,
        "environment": {
            "python": sys.version.split()[0],
            "platform": platform.platform(),
            "librosa": getattr(librosa, "__version__", ""),
            "ai_models_root": str(ai_root),
            "profile_models": str(profile_root),
            "profile_semantic_backend": str(
                tagging.get("backend") or ""
            ),
            "profile_meter_source": str(
                characteristics
                .get("time_signature", {})
                .get("source")
                or ""
            ),
            "profile_architecture": "declarative-phase-preset-v2b",
            "profile_phase_preset": loaded_phase_preset.value.id,
            "profile_phase_preset_revision": loaded_phase_preset.value.revision,
            "profile_phase_preset_sha256": loaded_phase_preset.sha256,
            "profile_dependency_root": os.getenv(
                "EZSTUDIO_PROFILE_DEP_ROOT",
                r"H:\EZStudio_plateforme\apps\ezs_lab\var\runtime\deps\profile-r3b12",
            ),
        },
        "automatic_next_stage": False,
    }

    write_progress(progress, 96, "PROFILE: écriture Preset R3B31")
    tmp = output.with_suffix(".tmp")
    tmp.write_text(
        json.dumps(result, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    tmp.replace(output)
    forensic_event("output.persisted", {"sha256": sha256(output),
        "bytes": output.stat().st_size})
    write_progress(progress, 100, "PROFILE terminé")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
