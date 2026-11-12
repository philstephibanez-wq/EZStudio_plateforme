from __future__ import annotations

import argparse
import json
import os
import subprocess
import threading
import sys
import traceback
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

PROTOCOL = "ezscore.analysis-job.v2"
ALLOWED_KINDS = {"benchmark", "profile", "stems", "chords_scientific"}


_CURRENT_LOG_PATH: Path | None = None
_CURRENT_LAB_LOG_PATH: Path | None = None


def _utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def _append_line(path: Path | None, message: str) -> None:
    if path is None:
        return
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8", newline="\n") as handle:
        handle.write(message.rstrip("\r\n") + "\n")


def _log_event(message: str) -> None:
    line = f"{_utc_now()} {message}"
    _append_line(_CURRENT_LOG_PATH, line)
    _append_line(_CURRENT_LAB_LOG_PATH, line)


def _run_logged(
    command: list[str],
    *,
    cwd: str,
    env: dict[str, str],
) -> int:
    _log_event("process_start command=" + " ".join(command))
    proc = subprocess.Popen(
        command,
        cwd=cwd,
        env=env,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        encoding="utf-8",
        errors="replace",
        bufsize=1,
    )
    # Only the LAB one-shot CLI supplies this trusted path.
    # Stop the Popen child we created, never enumerate or kill unrelated PIDs.
    cancel_path = os.environ.get("EZSTUDIO_PROFILE_ONESHOT_CANCEL_FILE", "").strip()
    stop_watch = threading.Event()
    cancelled = threading.Event()
    watcher = None
    if cancel_path:
        def watch_cancel() -> None:
            while not stop_watch.wait(0.2):
                if Path(cancel_path).is_file() and proc.poll() is None:
                    cancelled.set()
                    _log_event("profile_oneshot_cancel_requested")
                    proc.terminate()
                    try:
                        proc.wait(timeout=3)
                    except subprocess.TimeoutExpired:
                        proc.kill()
                    return
        watcher = threading.Thread(target=watch_cancel, name="profile-once-cancel", daemon=True)
        watcher.start()
    try:
        if proc.stdout is not None:
            for line in proc.stdout:
                clean = line.rstrip("\r\n")
                print(clean, flush=True)
                _append_line(_CURRENT_LOG_PATH, clean)
        returncode = int(proc.wait())
    finally:
        stop_watch.set()
        if watcher is not None:
            watcher.join(timeout=2)
    if cancelled.is_set():
        _log_event('profile_oneshot_cancel_worker_terminated')
    _log_event(f"process_end returncode={returncode}")
    return returncode


def _resolved(path: Path) -> Path:
    return path.resolve(strict=False)


def _is_under(path: Path, root: Path) -> bool:
    try:
        _resolved(path).relative_to(_resolved(root))
        return True
    except ValueError:
        return False


def _required_string(mapping: dict[str, Any], key: str) -> str:
    value = str(mapping.get(key) or "").strip()
    if not value:
        raise RuntimeError(f"missing_{key}")
    return value


def _common_env(runtime_root: Path) -> dict[str, str]:
    env = os.environ.copy()
    models_root = Path(env.get("AI_MODELS_ROOT") or r"H:\AIModels")
    env.setdefault("AI_MODELS_ROOT", str(models_root))
    env.setdefault(
        "BS_ROFORMER_MODELS_PATH",
        str(models_root / "audio" / "separation" / "bs-roformer"),
    )
    env.setdefault(
        "MELBAND_ROFORMER_MODELS_PATH",
        str(models_root / "audio" / "separation" / "melband-roformer"),
    )
    env.setdefault(
        "EZSTUDIO_PROFILE_MODELS",
        str(models_root / "audio" / "profile"),
    )
    env.setdefault(
        "EZSTUDIO_BEAT_THIS_CHECKPOINT",
        str(
            models_root
            / "audio"
            / "rhythm"
            / "beat-this"
            / "beat_this-final0.ckpt"
        ),
    )
    env.setdefault("EZSTUDIO_RUNTIME_ROOT", str(runtime_root))
    env.setdefault("EZSTUDIO_STEM_DEVICE", "cuda:0")
    env["PYTHONUTF8"] = "1"
    env["PYTHONIOENCODING"] = "utf-8"
    return env


def main() -> int:
    global _CURRENT_LOG_PATH, _CURRENT_LAB_LOG_PATH
    parser = argparse.ArgumentParser()
    parser.add_argument("--job-file", required=True)
    args = parser.parse_args()

    job_file = Path(args.job_file).resolve()
    raw = json.loads(job_file.read_text(encoding="utf-8"))
    if not isinstance(raw, dict):
        raise RuntimeError("job_envelope_must_be_object")

    if str(raw.get("protocol") or "") != PROTOCOL:
        raise RuntimeError("unsupported_job_protocol")
    if str(raw.get("target") or "").strip().lower() != "lab":
        raise RuntimeError("job_target_must_be_lab")
    if str(os.getenv("EZS_TARGET", "") or "").strip().lower() != "lab":
        raise RuntimeError("EZS_TARGET_must_be_lab")

    kind = str(raw.get("kind") or "").strip().lower()
    if kind not in ALLOWED_KINDS:
        raise RuntimeError(f"unsupported_job_kind:{raw.get('kind')}")

    project_root = Path(__file__).resolve().parents[1]
    job_id = int(raw.get("job_id") or 0)
    if job_id <= 0:
        raise RuntimeError("invalid_job_id")
    log_root = Path(
        str(
            os.getenv("EZSTUDIO_LOG_ROOT")
            or (project_root / "var" / "logs")
        )
    )
    _CURRENT_LOG_PATH = (
        log_root / "jobs" / f"job-{job_id:08d}-{kind}.log"
    )
    _CURRENT_LAB_LOG_PATH = log_root / "lab.log"
    _log_event(
        f"job_start id={job_id} kind={kind} target=lab "
        f"job_file={job_file}"
    )
    expected_root_raw = str(os.getenv("EZS_EXPECTED_ROOT", "") or "").strip()
    if not expected_root_raw:
        raise RuntimeError("EZS_EXPECTED_ROOT_missing")
    expected_root = Path(expected_root_raw)
    if _resolved(expected_root) != _resolved(project_root):
        raise RuntimeError(
            f"unexpected_project_root:expected={expected_root};actual={project_root}"
        )

    paths = raw.get("paths") if isinstance(raw.get("paths"), dict) else {}
    request = raw.get("request") if isinstance(raw.get("request"), dict) else {}

    source = Path(_required_string(paths, "source"))
    progress_file = Path(_required_string(paths, "progress_file"))
    if not source.is_file():
        raise RuntimeError(f"source_missing:{source}")

    storage_root = Path(
        str(
            os.getenv("EZSTUDIO_STORAGE_ROOT")
            or (project_root / "var" / "storage")
        )
    )
    tmp_root = Path(
        str(
            os.getenv("EZSTUDIO_TMP_ROOT")
            or (project_root / "var" / "tmp")
        )
    )
    runtime_deps_root = Path(
        str(
            os.getenv("EZSTUDIO_DEP_ROOT")
            or (project_root / "var" / "runtime" / "deps")
        )
    )

    if not _is_under(source, project_root):
        raise RuntimeError(f"source_outside_lab_root:{source}")
    if not _is_under(progress_file, tmp_root):
        raise RuntimeError(f"progress_outside_lab_tmp:{progress_file}")

    env = _common_env(storage_root)
    env.setdefault("EZSTUDIO_STORAGE_ROOT", str(storage_root))
    env.setdefault("EZSTUDIO_TMP_ROOT", str(tmp_root))
    env.setdefault("EZSTUDIO_LOG_ROOT", str(log_root))
    env.setdefault("EZSTUDIO_DEP_ROOT", str(runtime_deps_root))

    if kind == "profile":
        # PROFILE only. The fresh child inherits this value, without restarting PHP/Caddy.
        # A launcher-level explicit numpy override remains the rollback switch.
        env.setdefault("EZSTUDIO_PROFILE_MADMOM_BACKEND", "cuda")
        _log_event("profile_madmom_backend=" + env["EZSTUDIO_PROFILE_MADMOM_BACKEND"])
        env.setdefault("EZSTUDIO_PROFILE_TUNING_PROBE", "1")
        _log_event("profile_tuning_probe=" + env["EZSTUDIO_PROFILE_TUNING_PROBE"])
        env.setdefault("EZSTUDIO_PROFILE_HPSS_REUSE", "1")
        _log_event("profile_hpss_reuse=" + env["EZSTUDIO_PROFILE_HPSS_REUSE"])
        env.setdefault("EZSTUDIO_PROFILE_HPSS_MEDIAN_BACKEND", "medfilt2d")
        _log_event("profile_hpss_median_backend=" + env["EZSTUDIO_PROFILE_HPSS_MEDIAN_BACKEND"])
        runner = project_root / "python" / "ezstudio" / "pipeline" / "profile" / "runner.py"
        if not runner.is_file():
            raise RuntimeError(f"profile_runner_missing:{runner}")
        audio_hash = _required_string(request, "audio_hash")
        output_path = Path(_required_string(request, "output_path"))
        if not _is_under(output_path, storage_root):
            raise RuntimeError(f"profile_output_outside_lab_storage:{output_path}")
        command = [
            sys.executable, str(runner),
            "--source", str(source),
            "--audio-hash", audio_hash,
            "--output-path", str(output_path),
            "--progress-file", str(progress_file),
        ]
        return _run_logged(
            command,
            cwd=str(project_root),
            env=env,
        )

    if kind == "stems":
        runner = project_root / "python" / "ezstudio" / "pipeline" / "stems" / "runner.py"
        if not runner.is_file():
            raise RuntimeError(f"stems_runner_missing:{runner}")

        audio_hash = _required_string(request, "audio_hash")
        storage_root = Path(_required_string(request, "storage_root"))
        if not _is_under(storage_root, Path(env["EZSTUDIO_STORAGE_ROOT"])):
            raise RuntimeError(f"stems_storage_outside_lab_storage:{storage_root}")

        command = [
            sys.executable,
            str(runner),
            "--source", str(source),
            "--audio-hash", audio_hash,
            "--storage-root", str(storage_root),
            "--progress-file", str(progress_file),
        ]
        if bool(request.get("force", False)):
            command.append("--force")

        return _run_logged(
            command,
            cwd=str(project_root),
            env=env,
        )

    database = Path(_required_string(request, "database"))
    deps = Path(_required_string(request, "deps"))
    run_id = int(request.get("run_id") or 0)
    signature = _required_string(request, "signature")
    keep_upload = "1" if bool(request.get("keep_upload", True)) else "0"
    selection_request_raw = str(request.get("selection_request") or "").strip()
    selection_request = Path(selection_request_raw) if selection_request_raw else None

    if run_id <= 0:
        raise RuntimeError("invalid_run_id")
    if not _is_under(database, project_root):
        raise RuntimeError(f"database_outside_lab_root:{database}")
    if not _is_under(deps, runtime_deps_root):
        raise RuntimeError(f"deps_outside_lab_runtime_deps:{deps}")
    if kind == "chords_scientific":
        if selection_request is None or not selection_request.is_file():
            raise RuntimeError("selection_request_missing")
        if not _is_under(selection_request, tmp_root):
            raise RuntimeError(
                f"selection_request_outside_lab_tmp:{selection_request}"
            )

    worker = project_root / "python" / "worker.py"
    if not worker.is_file():
        raise RuntimeError(f"benchmark_worker_missing:{worker}")

    progress_file.parent.mkdir(parents=True, exist_ok=True)

    command = [
        sys.executable,
        str(worker),
        "--db", str(database),
        "--run-id", str(run_id),
        "--audio", str(source),
        "--signature", signature,
        "--deps", str(deps),
        "--keep-upload", keep_upload,
        "--progress-file", str(progress_file),
    ]
    if kind == "chords_scientific":
        command += ["--selection-request", str(selection_request)]

    env.setdefault("EZSTUDIO_DEP_ROOT", str(deps))
    env.setdefault("EZSTUDIO_STEMS_CACHE_ROOT", str(storage_root / "stems"))
    env.setdefault("EZSTUDIO_OBSERVABILITY_ROOT", str(tmp_root / "observability"))
    env.setdefault("EZSTUDIO_EXPORT_ROOT", str(storage_root / "exports"))

    return _run_logged(
        command,
        cwd=str(project_root),
        env=env,
    )


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        _log_event(
            "worker_fatal "
            + f"{type(exc).__name__}:{exc}"
        )
        if _CURRENT_LOG_PATH is not None:
            _append_line(_CURRENT_LOG_PATH, traceback.format_exc())
        raise
