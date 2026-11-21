"""EZS L1C: read-only local diagnostic for llama.cpp, with no inference or process changes.

Only purpose-built numeric measurements and recognized log evidence leave this script.
No command line, model path, user name, environment, request, response or raw log is copied.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

SCHEMA = 'ezstudio.runtime-report.assistant-qwen.local.v1'
FLAG_DEVICE = re.compile(r'(?:^|\s)-dev\s+(Vulkan\d+)(?=\s|$)', re.I)
FLAG_NGL = re.compile(r'(?:^|\s)(?:-ngl|--gpu-layers)\s+(\d+)(?=\s|$)', re.I)
FLAG_HOST = re.compile(r'(?:^|\s)--host\s+(127\.0\.0\.1)(?=\s|$)', re.I)
FLAG_PORT = re.compile(r'(?:^|\s)--port\s+(\d+)(?=\s|$)', re.I)
# llama.cpp log formats vary by version; never infer offload if no recognized explicit line.
RE_OFFLOAD = [
    re.compile(r'offloaded\s+(\d+)\s*/\s*(\d+)\s+layers?\s+to\s+GPU', re.I),
    re.compile(r'offloaded\s+(\d+)\s+of\s+(\d+)\s+layers?\s+to\s+GPU', re.I),
]
RE_SELECTED = re.compile(r'(?:using|selected|device|ggml_vulkan[^:]*:)\s*(Vulkan\d+)\b', re.I)
RE_LOG_DEVICE = re.compile(r'\b(Vulkan\d+)\s*[:=]\s*(NVIDIA|Intel|AMD)\b', re.I)
MAX_LOG_BYTES = 2_000_000


def parse_command(command: str | None) -> dict:
    """Return allowlisted launch flags only. Never return the raw command string."""
    command = command or ''
    dev = FLAG_DEVICE.search(command)
    ngl = FLAG_NGL.search(command)
    host = FLAG_HOST.search(command)
    port = FLAG_PORT.search(command)
    return {
        'requested_device': dev.group(1) if dev else None,
        'requested_gpu_layers': int(ngl.group(1)) if ngl else None,
        'localhost_only': host is not None,
        'port': int(port.group(1)) if port else None,
        'flags_observed': bool(dev or ngl or host or port),
    }


def parse_log(content: str) -> dict:
    """Parse recognized evidence with bounded sanitized fields; no raw lines emitted."""
    offloads: list[dict] = []
    selection: list[str] = []
    inventories: dict[str, str] = {}
    for line in content.splitlines():
        if len(line) > 1600:
            continue
        for regex in RE_OFFLOAD:
            match = regex.search(line)
            if match:
                count, total = int(match.group(1)), int(match.group(2))
                if 0 <= count <= total <= 10000:
                    offloads.append({'offloaded_layers': count, 'total_layers': total})
                break
        found = RE_SELECTED.search(line)
        if found:
            selection.append(found.group(1))
        found = RE_LOG_DEVICE.search(line)
        if found:
            inventories[found.group(1)] = found.group(2).upper()
    return {
        'offload_observations': offloads[-5:],
        'selected_device_markers': sorted(set(selection)),
        'device_inventory': inventories,
        'explicit_offload_observed': bool(offloads),
    }


def _powershell_processes() -> tuple[list[dict], str]:
    if os.name != 'nt':
        return [], 'windows_only'
    # No shell interpolation of user values and no model paths in persistent outputs.
    script = ("$ErrorActionPreference='Stop';"
              "$p=@(Get-CimInstance Win32_Process -Filter \"Name='llama-server.exe'\" | "
              "Select-Object ProcessId,ParentProcessId,CreationDate,ExecutablePath,CommandLine);"
              "ConvertTo-Json -InputObject $p -Depth 3 -Compress")
    try:
        args = ['powershell', '-NoProfile', '-NonInteractive', '-Command', script]
        cp = subprocess.run(args, capture_output=True, timeout=9, check=False)
        if cp.returncode:
            return [], 'cim_query_failed'
        decoded = cp.stdout.decode('utf-8-sig', 'replace').strip()
        rows = json.loads(decoded) if decoded else []
        if isinstance(rows, dict):
            rows = [rows]
        if not isinstance(rows, list):
            return [], 'cim_unexpected_shape'
        clean = []
        for p in rows:
            if not isinstance(p, dict):
                continue
            try:
                pid = int(p.get('ProcessId'))
                ppid = int(p.get('ParentProcessId') or 0)
                if not 0 < pid < 2**31:
                    continue
            except (TypeError, ValueError):
                continue
            flags = parse_command(p.get('CommandLine'))
            # EXE basename only; never export the path or process command line.
            filename = str(p.get('ExecutablePath') or '').replace('\\', '/').rsplit('/', 1)[-1].lower()
            clean.append({'pid': pid, 'parent_pid': ppid,
                          'executable_is_llama_server': filename == 'llama-server.exe',
                          'launch': flags,
                          'process_creation_reported': bool(p.get('CreationDate'))})
        return clean, 'ok'
    except (OSError, subprocess.TimeoutExpired, ValueError, json.JSONDecodeError):
        return [], 'cim_unavailable'


def _gpu_inventory() -> tuple[list[dict], str]:
    try:
        cp = subprocess.run(['nvidia-smi', '--query-gpu=index,name,memory.used,utilization.gpu',
                             '--format=csv,noheader,nounits'], capture_output=True, timeout=5, check=False)
        if cp.returncode:
            return [], 'nvidia_smi_failed'
        out = []
        for line in cp.stdout.decode('utf-8', 'replace').splitlines()[:8]:
            fields = [x.strip() for x in line.split(',')]
            if len(fields) != 4:
                continue
            try:
                idx = int(fields[0]); mem = float(fields[2]); util = float(fields[3])
            except ValueError:
                continue
            vendor = 'NVIDIA' if 'nvidia' in fields[1].lower() else 'unclassified'
            out.append({'nvidia_smi_index': idx, 'vendor': vendor,
                        'memory_used_mib_global': mem, 'utilization_percent_global': util})
        return out, 'ok'
    except (OSError, subprocess.TimeoutExpired):
        return [], 'nvidia_smi_unavailable'


def _read_log_excerpt(path: Path) -> tuple[str | None, str]:
    try:
        if not path.is_file() or path.is_symlink():
            return None, 'log_missing'
        with path.open('rb') as f:
            f.seek(0, 2)
            size = f.tell()
            f.seek(max(0, size - MAX_LOG_BYTES))
            data = f.read(MAX_LOG_BYTES)
        return data.decode('utf-8', 'replace'), 'ok'
    except OSError:
        return None, 'log_unreadable'


def report(root: Path, explicit_log: Path | None = None) -> dict:
    processes, process_status = _powershell_processes()
    gpus, gpu_status = _gpu_inventory()
    folder = root / 'var' / 'log' / 'ezs_assistant' / 'llama'
    # Existing paths originate in AssistantLlamaLifecycle; alternative app runtime log root.
    candidate_logs: list[Path] = []
    if explicit_log:
        candidate_logs = [explicit_log]
    else:
        for base in (folder, root / 'apps' / 'ezs_assistant' / 'runtime' / 'ai'):
            if not base.is_dir():
                continue
            candidate_logs.extend(p for p in base.glob('assistant-llama-native-*.log') if p.is_file())
            candidate_logs.extend(p for p in base.glob('llama-server.*.log') if p.is_file())
        candidate_logs.sort(key=lambda x: x.stat().st_mtime, reverse=True)
        candidate_logs = candidate_logs[:3]
    log_evidence = []
    for path in candidate_logs:
        data, status = _read_log_excerpt(path)
        log_evidence.append({'source': 'explicit_log' if explicit_log else 'latest_candidate_log',
                             'status': status, 'evidence': parse_log(data) if data is not None else None,
                             'bound_to_current_pid': False})
    process_verified = [p for p in processes if p['executable_is_llama_server']]
    return {
        'schema': SCHEMA, 'created_utc': datetime.now(timezone.utc).isoformat(),
        'scope': 'local_metadata_only_no_automatic_publication',
        'process_query': process_status, 'processes': process_verified,
        'nvidia_query': gpu_status, 'nvidia_gpu_global': gpus,
        'llama_logs': log_evidence,
        'attestation': {
            'launch_parameters': 'observed' if any(p['launch']['flags_observed'] for p in process_verified) else 'unverified',
            'actual_vulkan_device_for_active_pid': 'unverified',
            'actual_gpu_offload_for_active_pid': 'unverified',
            'logged_offload_available_unbound': any(e['evidence'] and e['evidence']['explicit_offload_observed'] for e in log_evidence),
            'cuda_kernel_time': 'not_measured', 'vulkan_kernel_time': 'not_measured',
            'gpu_pid_attribution': 'not_attested',
        },
        'coverage_warning': 'Global GPU load does not prove process attribution. Old or unmatched llama logs do not prove offload of an active PID.',
    }


def main(argv=None):
    parser = argparse.ArgumentParser(description='Read-only Qwen/Vulkan process + log visibility; no launch/restart')
    parser.add_argument('--root', required=True, type=Path)
    parser.add_argument('--output', required=True, type=Path)
    parser.add_argument('--log', type=Path, help='Optional exact native llama log; provenance remains unbound to PID')
    args = parser.parse_args(argv)
    if not args.root.is_dir():
        parser.error('root_directory_missing')
    target = args.output.resolve()
    root = args.root.resolve()
    if target.is_relative_to(root):
        parser.error('output_must_be_outside_repository')
    data = report(root, args.log)
    target.parent.mkdir(parents=True, exist_ok=True)
    # Atomic write, no echo of potentially sensitive source paths or commands.
    tmp = target.with_suffix(target.suffix + '.tmp')
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    os.replace(tmp, target)
    print('L1C_REPORT_OK processes=%d logs=%d device_attested=false' % (len(data['processes']), len(data['llama_logs'])))
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
