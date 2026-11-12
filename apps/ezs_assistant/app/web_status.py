from __future__ import annotations

import json
import os
import socket
import subprocess
import sys
from pathlib import Path

from transport import AnalysisApiClient, AnalysisApiError

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_STATUS_FILE = ROOT / "logs" / "assistant.status.json"
DEFAULT_TOKEN_FILE = ROOT / "runtime" / "state" / "analysis-worker-token.dpapi"

LAB_TIMEOUT_SECONDS = 1.5
SMB_TIMEOUT_SECONDS = 1.5
TOKEN_TIMEOUT_SECONDS = 3.0


def _read_json(path: Path) -> dict:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return value if isinstance(value, dict) else {}


def _read_dpapi_token(path: Path) -> str:
    """Read the per-user DPAPI token without exposing it in command arguments."""
    if not path.is_file():
        return ""

    script = (
        "$ErrorActionPreference='Stop';"
        "$p=$env:EZSTUDIOLAB_ASSISTANT_TOKEN_FILE;"
        "$cipher=[System.IO.File]::ReadAllText("
        "$p,[System.Text.Encoding]::ASCII).Trim();"
        "$secure=ConvertTo-SecureString $cipher;"
        "$credential=New-Object "
        "System.Management.Automation.PSCredential("
        "'assistant-token',$secure);"
        "[Console]::Out.Write("
        "$credential.GetNetworkCredential().Password)"
    )
    env = os.environ.copy()
    env["EZSTUDIOLAB_ASSISTANT_TOKEN_FILE"] = str(path)
    kwargs = {
        "capture_output": True,
        "text": True,
        "encoding": "utf-8",
        "errors": "replace",
        "timeout": TOKEN_TIMEOUT_SECONDS,
        "env": env,
    }
    if hasattr(subprocess, "CREATE_NO_WINDOW"):
        kwargs["creationflags"] = subprocess.CREATE_NO_WINDOW

    try:
        completed = subprocess.run(
            [
                "powershell",
                "-NoProfile",
                "-NonInteractive",
                "-ExecutionPolicy",
                "Bypass",
                "-Command",
                script,
            ],
            **kwargs,
        )
    except (OSError, subprocess.TimeoutExpired):
        return ""

    if completed.returncode != 0:
        return ""
    return completed.stdout.strip()


def _probe_lab(base_url: str, token: str) -> dict:
    result = {
        "transport": "REST",
        "base_url": base_url,
        "endpoint": "/internal/analysis/desktop/hello",
        "reachable": False,
        "authorized": False,
    }

    if not token:
        result["error"] = "analysis_token_missing"
        return result

    try:
        client = AnalysisApiClient(
            base_url=base_url,
            token=token,
            user_agent="EZStudioLab_assistant/web-status",
        )
        response = client.request(
            "POST",
            "/internal/analysis/desktop/hello",
            {
                "schema": "ezstudio.assistant.web-probe.v1",
                "node_id": socket.gethostname(),
                "state": "status_probe",
            },
            timeout=LAB_TIMEOUT_SECONDS,
        )
        state = response.get("state") if isinstance(response, dict) else None
        result["reachable"] = True
        result["authorized"] = bool(
            isinstance(state, dict) and state.get("ok") is True
        )
        result["target"] = (
            response.get("target") if isinstance(response, dict) else None
        )
        result["server_time"] = (
            response.get("server_time") if isinstance(response, dict) else None
        )
        if not result["authorized"]:
            result["error"] = "lab_hello_invalid_response"
    except AnalysisApiError as exc:
        result["error"] = str(exc)[:500]
    except Exception as exc:
        result["error"] = f"{type(exc).__name__}:{exc}"[:500]

    return result


def _probe_model_root(model_root: Path) -> dict:
    code = (
        "import os,sys;"
        "raise SystemExit(0 if os.path.exists(sys.argv[1]) else 3)"
    )
    kwargs = {
        "capture_output": True,
        "text": True,
        "timeout": SMB_TIMEOUT_SECONDS,
    }
    if hasattr(subprocess, "CREATE_NO_WINDOW"):
        kwargs["creationflags"] = subprocess.CREATE_NO_WINDOW

    result = {
        "transport": "SMB",
        "root": str(model_root),
        "accessible": False,
        "scope": "AI models only",
    }

    try:
        completed = subprocess.run(
            [sys.executable, "-c", code, str(model_root)],
            **kwargs,
        )
        result["accessible"] = completed.returncode == 0
        if completed.returncode != 0:
            result["error"] = "model_root_unreachable"
    except subprocess.TimeoutExpired:
        result["error"] = "model_root_timeout"
    except Exception as exc:
        result["error"] = f"{type(exc).__name__}:{exc}"[:500]

    return result



def _profile_consultation_status() -> dict:
    """Read real independent-verification stages; never claim Qwen module progress."""
    import re
    root = Path(os.getenv("EZS_PROFILE_TRACE_ROOT") or r"C:\temp\EZS_PROFILE_DEBUG")
    try:
        folders = sorted((p for p in root.iterdir()
            if p.is_dir() and re.fullmatch(r"run-\d+-[0-9a-f]{12}", p.name)),
            key=lambda p: p.stat().st_mtime, reverse=True)
        if not folders:
            return {"state": "not_started"}
        timeline = folders[0] / "timeline.jsonl"
        if not timeline.is_file():
            return {"state": "not_started"}
        progress = {"state": "in_progress",
                    "run_id": int(folders[0].name.split("-")[1]),
                    "stage": "initialisation", "completed": 0, "total": 4,
                    "verified_count": None, "evidence_count": None,
                    "revision": None}
        saw_new = False
        saw_finish = False
        with timeline.open(encoding="utf-8") as stream:
            for line in stream:
                try:
                    event = json.loads(line)
                except (ValueError, TypeError):
                    continue
                name = event.get("event")
                data = event.get("data") or {}
                if name == "research.begin":
                    saw_new = True
                    progress.update(stage="recherche des sources", completed=1)
                elif name == "profile.final_verification":
                    saw_new = True
                    assertions = data.get("assertions") or []
                    if isinstance(assertions, list):
                        progress["verified_count"] = len(assertions)
                        progress["evidence_count"] = sum(
                            1 for a in assertions if isinstance(a, dict)
                            and a.get("verdict") in ("bon", "faux")
                            and a.get("sources"))
                    progress.update(stage="verdicts calculés", completed=2)
                elif name == "lab.submit.response":
                    saw_new = True
                    if isinstance(data, dict) and data.get("ok"):
                        progress.update(stage="révision enregistrée", completed=3)
                        revision = data.get("revision") or {}
                        if isinstance(revision, dict):
                            progress["revision"] = revision.get("id")
                elif name == "trace.finish":
                    saw_finish = True
                    status = data.get("status") if isinstance(data, dict) else None
                    progress["state"] = "completed" if status == "completed" else "error"
                    progress["stage"] = "terminé" if status == "completed" else "échec"
                    if status == "completed":
                        progress["completed"] = 4
                elif name == "module.progress" and not saw_new:
                    # Legacy traces must never masquerade as v1.6a verification.
                    pass
        if not saw_new:
            return {"state": "not_started", "run_id": progress["run_id"],
                    "note": "ancienne trace sans vérification indépendante"}
        if not saw_finish:
            progress["state"] = "in_progress"
        return progress
    except (OSError, ValueError):
        return {"state": "unavailable"}

def main() -> int:
    version = (ROOT / "VERSION").read_text(encoding="utf-8").strip()

    status_file = Path(
        os.getenv(
            "EZSTUDIOLAB_ASSISTANT_STATUS_FILE",
            str(DEFAULT_STATUS_FILE),
        )
    )
    model_root = Path(
        os.getenv(
            "EZSTUDIO_SHARED_MODEL_ROOT",
            r"\\EZSTUDIO-MAIN\EZModels",
        )
    )
    base_url = os.getenv(
        "EZSTUDIOLAB_ASSISTANT_BASE_URL",
        "http://EZSTUDIO-MAIN:8702",
    ).rstrip("/")
    token = os.getenv("EZSTUDIOLAB_ASSISTANT_TOKEN", "").strip()
    if not token:
        token = _read_dpapi_token(DEFAULT_TOKEN_FILE)

    runtime = _read_json(status_file)
    lab_rest = _probe_lab(base_url, token)
    models = _probe_model_root(model_root)

    payload = {
        "ok": True,
        "schema": "ezstudio.assistant.web-status.v1",
        "state": runtime.get("state") or "IDLE",
        "activity": runtime.get("activity") or "En attente",
        "hostname": socket.gethostname(),
        "version": version,
        "database": False,
        "persistence_owner": "LAB",
        "dependencies_ok": bool(
            lab_rest.get("reachable")
            and lab_rest.get("authorized")
            and models.get("accessible")
        ),
        "lab_rest": lab_rest,
        "models": models,
        "profile_consultation": _profile_consultation_status(),
        "runtime": {
            "status_file": str(status_file),
            "pid": runtime.get("pid"),
            "job_id": runtime.get("job_id"),
            "phase": runtime.get("phase"),
            "progress": runtime.get("progress"),
        },
    }

    print(json.dumps(payload, ensure_ascii=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
