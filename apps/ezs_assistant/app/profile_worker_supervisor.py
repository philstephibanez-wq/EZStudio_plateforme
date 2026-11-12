"""Supervise the existing PROFILE request worker (Windows only).

Does not own any queue. The canonical queue remains in Orchestrator/LAB.
Only a scheduler action pointing at this module guarantees serialized launches.
"""
from __future__ import annotations
import ctypes
import os
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKER = ROOT / 'runtime' / 'profile_ai_requests_worker.py'
MUTEX = 'Local\\EZStudioLab_Assistant_PROFILE_Worker'
ERROR_ALREADY_EXISTS = 183


def main() -> int:
    if os.name != 'nt':
        raise RuntimeError('PROFILE_SUPERVISOR_WINDOWS_ONLY')
    if not WORKER.is_file():
        raise RuntimeError(f'PROFILE_WORKER_MISSING:{WORKER}')
    k = ctypes.WinDLL('kernel32', use_last_error=True)
    k.CreateMutexW.argtypes = (ctypes.c_void_p, ctypes.c_int, ctypes.c_wchar_p)
    k.CreateMutexW.restype = ctypes.c_void_p
    k.CloseHandle.argtypes = (ctypes.c_void_p,)
    k.CloseHandle.restype = ctypes.c_int
    handle = k.CreateMutexW(None, 0, MUTEX)
    if not handle:
        raise OSError(ctypes.get_last_error(), 'PROFILE_MUTEX_CREATE_FAILED')
    duplicate = ctypes.get_last_error() == ERROR_ALREADY_EXISTS
    try:
        if duplicate:
            print('PROFILE_SUPERVISOR_ALREADY_RUNNING', flush=True)
            return 3
        # Never launch if a legacy/unmanaged worker survives. Fail closed.
        powershell = ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
          '$w = Get-CimInstance Win32_Process -Filter "Name = \'python.exe\' OR Name = \'pythonw.exe\'" | '
          'Where-Object { $_.CommandLine -and $_.CommandLine.IndexOf(' +
          "'profile_ai_requests_worker.py',[StringComparison]::OrdinalIgnoreCase) -ge 0 }; " +
          'Write-Output @($w).Count']
        check = subprocess.run(powershell, capture_output=True, text=True, timeout=15, check=True)
        if int(check.stdout.strip().splitlines()[-1]) != 0:
            raise RuntimeError('PROFILE_UNMANAGED_WORKER_PRESENT')
        print('PROFILE_SUPERVISOR_START', flush=True)
        # Keep mutex owned until the *actual* worker finishes.
        return subprocess.call([sys.executable, '-u', str(WORKER)], cwd=str(ROOT))
    finally:
        k.CloseHandle(handle)


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception as exc:
        print(f'PROFILE_SUPERVISOR_ERROR:{type(exc).__name__}:{exc}', file=sys.stderr, flush=True)
        sys.exit(2)
