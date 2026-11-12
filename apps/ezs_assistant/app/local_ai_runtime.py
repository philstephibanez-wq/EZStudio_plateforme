from __future__ import annotations

import base64
import json
import os
import socket
import subprocess
import time
import urllib.request
from dataclasses import dataclass
from pathlib import Path, PureWindowsPath
from typing import Any

ASSISTANT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_SHARED_MODEL_ROOT = r"H:\EZSModels"
DEFAULT_CACHE_ROOT = ASSISTANT_ROOT / "runtime" / "models-cache"
DEFAULT_RUNTIME_ROOT = ASSISTANT_ROOT / "runtime" / "ai"
DEFAULT_ENDPOINT = "http://127.0.0.1:8720"
DEFAULT_PORT = 8720
DEFAULT_DEVICE = "Vulkan1"
LOCAL_AI_TASK_NAME = "EZStudioLab Assistant Local AI"
LEGACY_RECOVERY_TASK_NAME = "EZS_ASSISTANT_LOCAL_AI_RECOVERY"


@dataclass(frozen=True)
class LocalAIRuntimeConfig:
    canonical_model: str
    shared_model_root: str
    cache_root: Path
    runtime_root: Path
    endpoint: str
    port: int
    device: str
    gpu_layers: int
    context_size: int
    server_exe: Path


def _relative_model_path(root: str, model: str):
    rt, mt = str(root).strip(), str(model).strip()
    if rt.startswith(chr(92) * 2) or mt.startswith(chr(92) * 2) or rt.startswith("//") or mt.startswith("//"):
        raise RuntimeError("local_ai_smb_forbidden")
    if not PureWindowsPath(rt).is_absolute() or not PureWindowsPath(mt).is_absolute():
        raise RuntimeError("local_ai_absolute_windows_path_required")
    if ".." in PureWindowsPath(mt).parts:
        raise RuntimeError("local_ai_parent_traversal_forbidden")
    if rt.startswith("\\\\") or "\\" in rt or ":" in rt:
        rp, mp = PureWindowsPath(rt), PureWindowsPath(mt)
    else:
        rp, mp = Path(rt), Path(mt)
    try:
        return mp.relative_to(rp)
    except Exception as exc:
        raise RuntimeError("local_ai_model_must_be_under_shared_model_root") from exc



def _ezs_local_model_config():
    # Persistent Assistant configuration, NOT a model/cache directory.
    from pathlib import PureWindowsPath
    settings_path = Path(__file__).resolve().parents[1] / 'runtime' / 'state' / 'local-model.json'
    try:
        cfg = json.loads(settings_path.read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return {}
    if not isinstance(cfg, dict) or cfg.get('schema') != 'ezs.assistant.model-location.v1':
        return {}
    root = str(cfg.get('root') or '')
    model = str(cfg.get('model') or '')
    rp, mp = PureWindowsPath(root), PureWindowsPath(model)
    if not rp.is_absolute() or not mp.is_absolute() or str(rp).startswith(chr(92)*2) or str(mp).startswith(chr(92)*2) or '..' in mp.parts:
        return {}
    try:
        if not mp.relative_to(rp).parts or mp.suffix.lower() != '.gguf':
            return {}
    except ValueError:
        return {}
    return {'root': root, 'model': model}

def config_from_env() -> LocalAIRuntimeConfig:
    local_settings = _ezs_local_model_config()
    canonical = str(os.getenv("EZSTUDIOLAB_LOCAL_AI_MODEL", local_settings.get("model", ""))).strip()
    if not canonical:
        raise RuntimeError("local_ai_model_path_required")
    shared = str(os.getenv("EZSTUDIO_SHARED_MODEL_ROOT", local_settings.get("root", DEFAULT_SHARED_MODEL_ROOT))).strip() or DEFAULT_SHARED_MODEL_ROOT
    _relative_model_path(shared, canonical)
    cache_root = Path(str(os.getenv("EZSTUDIOLAB_LOCAL_AI_CACHE_ROOT", "")).strip() or DEFAULT_CACHE_ROOT)
    runtime_root = Path(str(os.getenv("EZSTUDIOLAB_LOCAL_AI_RUNTIME_ROOT", "")).strip() or DEFAULT_RUNTIME_ROOT)
    endpoint = (str(os.getenv("EZSTUDIOLAB_LOCAL_AI_ENDPOINT", DEFAULT_ENDPOINT)).strip() or DEFAULT_ENDPOINT).rstrip("/")
    try:
        port = int(os.getenv("EZSTUDIOLAB_LOCAL_AI_PORT", str(DEFAULT_PORT)))
    except ValueError:
        port = DEFAULT_PORT
    try:
        gpu_layers = int(os.getenv("EZSTUDIOLAB_LOCAL_AI_GPU_LAYERS", "99"))
    except ValueError:
        gpu_layers = 99
    try:
        context_size = int(os.getenv("EZSTUDIOLAB_LOCAL_AI_CONTEXT_SIZE", "4096"))
    except ValueError:
        context_size = 4096
    server_exe = Path(str(os.getenv("EZSTUDIOLAB_LLAMA_SERVER", "")).strip() or runtime_root / "bin" / "llama-server.exe")
    return LocalAIRuntimeConfig(
        canonical, shared, cache_root, runtime_root, endpoint,
        max(1024, min(65535, port)),
        str(os.getenv("EZSTUDIOLAB_LOCAL_AI_DEVICE", DEFAULT_DEVICE)).strip() or DEFAULT_DEVICE,
        max(0, gpu_layers), max(512, context_size), server_exe,
    )


def local_model_path(config: LocalAIRuntimeConfig) -> Path:
    # Canonical model on MAIN, no second copy and no remote legacy cache dependency.
    _relative_model_path(config.shared_model_root, config.canonical_model)
    return Path(config.canonical_model)


def _manifest_path(config): return config.runtime_root / "cache-manifest.json"
def _pid_path(config): return config.runtime_root / "llama-server.pid"


def _read_json(path: Path):
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return value if isinstance(value, dict) else {}


def _valid_cached_model(cfg: LocalAIRuntimeConfig, manifest: dict[str, Any]) -> tuple[bool, Path, int]:
    target = local_model_path(cfg)
    if not target.is_file():
        return False, target, -1
    try:
        size = target.stat().st_size
    except OSError:
        return False, target, -1
    expected_size = int(manifest.get("size") or -1)
    same_model = str(manifest.get("canonical_model") or "") == str(cfg.canonical_model)
    return same_model and expected_size > 0 and size == expected_size, target, size


def sync_local_model_cache(config: LocalAIRuntimeConfig | None = None) -> dict[str, Any]:
    # Compatibility API: attest central file; NEVER copy GGUF to runtime.
    cfg = config or config_from_env()
    source = local_model_path(cfg)
    try:
        if not source.is_file():
            raise RuntimeError("local_ai_canonical_model_missing")
        size = source.stat().st_size
    except OSError as exc:
        raise RuntimeError("local_ai_canonical_model_unavailable") from exc
    if size <= 0:
        raise RuntimeError("local_ai_canonical_model_empty")
    return {"schema": "EZStudioLab_assistant.local-ai-cache.v1",
            "status": "canonical_direct", "canonical_model": str(source),
            "local_model": str(source), "size": size, "copied": False}

def _health(endpoint: str, timeout: float = 1.0):
    req = urllib.request.Request(endpoint.rstrip("/") + "/health", headers={"Accept":"application/json"}, method="GET")
    try:
        with urllib.request.urlopen(req, timeout=timeout) as response:
            body = response.read(65536)
        payload = json.loads(body.decode("utf-8"))
        ready = bool(isinstance(payload, dict) and (payload.get("ok") is True or str(payload.get("status") or "").casefold() in {"ok","ready"}))
        return ready, payload if isinstance(payload, dict) else {}
    except Exception as exc:
        return False, {"error":f"{type(exc).__name__}:{exc}"[:300]}


def _process_info(pid: int):
    if os.name != "nt":
        return {"pid":pid,"alive":False}
    script = "$p=Get-CimInstance Win32_Process -Filter \"ProcessId=%d\" -ErrorAction SilentlyContinue;if($null -eq $p){exit 3};[Console]::Out.Write(($p.Name+'|'+$p.CommandLine))" % int(pid)
    kwargs = {"capture_output":True,"text":True,"encoding":"utf-8","errors":"replace","timeout":3}
    if hasattr(subprocess, "CREATE_NO_WINDOW"):
        kwargs["creationflags"] = subprocess.CREATE_NO_WINDOW
    p = subprocess.run(["powershell","-NoProfile","-NonInteractive","-Command",script], **kwargs)
    return {"pid":pid,"alive":p.returncode==0,"identity":p.stdout.strip() if p.returncode==0 else None}


def _powershell(script: str, timeout: int = 30) -> subprocess.CompletedProcess[str]:
    encoded = base64.b64encode(script.encode("utf-16le")).decode("ascii")
    kwargs = {"capture_output":True,"text":True,"encoding":"utf-8","errors":"replace","timeout":timeout}
    if hasattr(subprocess, "CREATE_NO_WINDOW"):
        kwargs["creationflags"] = subprocess.CREATE_NO_WINDOW
    return subprocess.run(
        ["powershell","-NoProfile","-NonInteractive","-ExecutionPolicy","Bypass","-EncodedCommand",encoded],
        **kwargs,
    )


def _ps_single(value: object) -> str:
    return "'" + str(value).replace("'", "''") + "'"


def _listener_pid(port: int) -> int | None:
    if os.name != "nt":
        return None
    script = (
        "$x=Get-NetTCPConnection -State Listen -LocalPort " + str(int(port))
        + " -ErrorAction SilentlyContinue | Select-Object -First 1;"
        + "if($null -eq $x){exit 3};[Console]::Out.Write([string]$x.OwningProcess)"
    )
    p = _powershell(script, timeout=5)
    if p.returncode != 0:
        return None
    try:
        return int(p.stdout.strip())
    except ValueError:
        return None


def _task_state() -> dict[str, Any]:
    if os.name != "nt":
        return {"registered":False,"state":None}
    script = (
        "$t=Get-ScheduledTask -TaskName " + _ps_single(LOCAL_AI_TASK_NAME)
        + " -ErrorAction SilentlyContinue;if($null -eq $t){exit 3};"
        + "$i=Get-ScheduledTaskInfo -TaskName " + _ps_single(LOCAL_AI_TASK_NAME)
        + " -ErrorAction SilentlyContinue;"
        + "[pscustomobject]@{state=[string]$t.State;last_result=$i.LastTaskResult}|ConvertTo-Json -Compress"
    )
    p = _powershell(script, timeout=5)
    if p.returncode != 0:
        return {"registered":False,"state":None}
    try:
        raw = json.loads(p.stdout.strip().splitlines()[-1])
    except Exception:
        return {"registered":True,"state":"UNKNOWN"}
    return {"registered":True,"state":str(raw.get("state") or ""),"last_result":raw.get("last_result")}


def _register_and_start_task(cfg: LocalAIRuntimeConfig, model: Path) -> None:
    if os.name != "nt":
        raise RuntimeError("local_ai_persistent_task_windows_only")
    task_args = (
        '-m "' + str(model) + '"'
        + " -dev " + cfg.device
        + " -ngl " + str(cfg.gpu_layers)
        + " -c " + str(cfg.context_size)
        + " --host 127.0.0.1"
        + " --port " + str(cfg.port)
    )
    script = f"""
$ErrorActionPreference='Stop'
$taskName={_ps_single(LOCAL_AI_TASK_NAME)}
$legacyTask={_ps_single(LEGACY_RECOVERY_TASK_NAME)}
# Idempotent start: a valid existing task needs no administrative registration rights.
$expectedExe={_ps_single(cfg.server_exe)}
$expectedArgs={_ps_single(task_args)}
$expectedRoot={_ps_single(cfg.runtime_root)}
$existing=Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if($null -ne $existing){{
  $actions=@($existing.Actions)
  if($actions.Count -ne 1 -or
     -not [string]::Equals([string]$actions[0].Execute,$expectedExe,[System.StringComparison]::OrdinalIgnoreCase) -or
     -not [string]::Equals([string]$actions[0].Arguments,$expectedArgs,[System.StringComparison]::Ordinal) -or
     -not [string]::Equals(([string]$actions[0].WorkingDirectory).TrimEnd('\'),([string]$expectedRoot).TrimEnd('\'),[System.StringComparison]::OrdinalIgnoreCase)){{
    throw 'local_ai_existing_task_configuration_mismatch_requires_explicit_repair'
  }}
  Start-ScheduledTask -TaskName $taskName -ErrorAction Stop
}} else {{
  # No prior task: registration is permitted, and failure does not alter the runtime.
  $action=New-ScheduledTaskAction -Execute $expectedExe -Argument $expectedArgs -WorkingDirectory $expectedRoot
  $principal=New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited
  $settings=New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
  Register-ScheduledTask -TaskName $taskName -Action $action -Principal $principal -Settings $settings -Description 'EZStudioLab Assistant persistent local AI' -ErrorAction Stop | Out-Null
  Start-ScheduledTask -TaskName $taskName -ErrorAction Stop
}}
"""
    p = _powershell(script, timeout=20)
    if p.returncode != 0:
        detail = (p.stderr.strip() or p.stdout.strip() or f"rc={p.returncode}")[:500]
        raise RuntimeError(f"local_ai_task_start_failed:{detail}")


def _stop_and_unregister_tasks() -> None:
    if os.name != "nt":
        return
    script = f"""
$ErrorActionPreference='Continue'
foreach($name in @({_ps_single(LOCAL_AI_TASK_NAME)},{_ps_single(LEGACY_RECOVERY_TASK_NAME)})){{
  $t=Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
  if($null -ne $t){{
    Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue
  }}
}}
"""
    _powershell(script, timeout=15)


def local_ai_status(config: LocalAIRuntimeConfig | None = None):
    cfg = config or config_from_env()
    model = local_model_path(cfg)
    try:
        pid = int(_pid_path(cfg).read_text(encoding="ascii").strip())
    except (OSError, ValueError):
        pid = None
    if not pid:
        pid = _listener_pid(cfg.port)
    proc = _process_info(pid) if pid else {"pid":None,"alive":False}
    ready, health = _health(cfg.endpoint)
    return {
        "schema":"EZStudioLab_assistant.local-ai-runtime-status.v1",
        "hostname":socket.gethostname(),
        "ready":ready,
        "endpoint":cfg.endpoint,
        "device":cfg.device,
        "canonical_model":cfg.canonical_model,
        "local_model":str(model),
        "local_model_present":model.is_file(),
        "server_exe":str(cfg.server_exe),
        "server_exe_present":cfg.server_exe.is_file(),
        "pid":pid,
        "process_alive":bool(proc.get("alive")),
        "process_identity":proc.get("identity"),
        "task":_task_state(),
        "health":health,
    }


def _start_direct(cfg: LocalAIRuntimeConfig, model: Path):
    cfg.runtime_root.mkdir(parents=True, exist_ok=True)
    out, err = cfg.runtime_root / "llama-server.out.log", cfg.runtime_root / "llama-server.err.log"
    args = [str(cfg.server_exe),"-m",str(model),"-dev",cfg.device,"-ngl",str(cfg.gpu_layers),"-c",str(cfg.context_size),"--host","127.0.0.1","--port",str(cfg.port)]
    flags = 0
    if hasattr(subprocess,"CREATE_NO_WINDOW"): flags |= subprocess.CREATE_NO_WINDOW
    if hasattr(subprocess,"DETACHED_PROCESS"): flags |= subprocess.DETACHED_PROCESS
    with out.open("ab") as stdout, err.open("ab") as stderr:
        return subprocess.Popen(args, cwd=cfg.runtime_root, stdin=subprocess.DEVNULL, stdout=stdout, stderr=stderr, creationflags=flags, close_fds=True)


def local_ai_start(config: LocalAIRuntimeConfig | None = None):
    cfg = config or config_from_env()
    current = local_ai_status(cfg)
    if current["ready"]:
        current["status"] = "already_running"
        return current

    cache = sync_local_model_cache(cfg)
    model = Path(cache["local_model"])
    if not cfg.server_exe.is_file():
        raise RuntimeError(f"llama_server_missing:{cfg.server_exe}")
    cfg.runtime_root.mkdir(parents=True, exist_ok=True)

    proc = None
    if os.name == "nt":
        _register_and_start_task(cfg, model)
    else:
        proc = _start_direct(cfg, model)
        _pid_path(cfg).write_text(str(proc.pid), encoding="ascii")

    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        if proc is not None and proc.poll() is not None:
            raise RuntimeError(f"llama_server_start_failed:exit={proc.returncode}")
        ready, _ = _health(cfg.endpoint, 1.0)
        if ready:
            pid = _listener_pid(cfg.port) if os.name == "nt" else proc.pid
            if pid:
                _pid_path(cfg).write_text(str(pid), encoding="ascii")
            status = local_ai_status(cfg)
            status["status"] = "started"
            status["cache"] = cache
            return status
        time.sleep(.5)
    raise RuntimeError("llama_server_start_timeout")


def local_ai_stop(config: LocalAIRuntimeConfig | None = None):
    cfg = config or config_from_env()
    pid_file = _pid_path(cfg)
    try:
        pid = int(pid_file.read_text(encoding="ascii").strip())
    except (OSError, ValueError):
        pid = None
    if os.name == "nt":
        _stop_and_unregister_tasks()
    if not pid:
        pid = _listener_pid(cfg.port)

    stopped = False
    if pid and os.name == "nt":
        info = _process_info(pid)
        identity = str(info.get("identity") or "")
        if info.get("alive") and "llama-server.exe" in identity.casefold():
            p = subprocess.run(["taskkill","/PID",str(pid),"/T","/F"], capture_output=True, text=True, encoding="utf-8", errors="replace")
            stopped = p.returncode == 0
    pid_file.unlink(missing_ok=True)
    return {
        "schema":"EZStudioLab_assistant.local-ai-runtime-stop.v1",
        "stopped":stopped,
        "pid":pid,
        "task":_task_state(),
        "ready":_health(cfg.endpoint,.5)[0],
    }
