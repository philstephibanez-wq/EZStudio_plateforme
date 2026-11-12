"""Legacy PROFILE guardian; verify prerequisites before opt-in use on MAIN.

Reuses the authenticated PowerShell launcher; never reads/prints any token.
Mutex prevents two guardians. Existing worker is allowed to finish before any
new worker is started. No killing and no LAB queue mutations.
"""
import ctypes
import os
from pathlib import Path
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
LAUNCH = ROOT / 'runtime' / 'start_profile_ai_requests.ps1'
WORKER = str(ROOT / 'runtime' / 'profile_ai_requests_worker.py').lower()
MUTEX_NAME = 'Local\\EZS_ASSISTANT_PROFILE_GUARDIAN_R3'


def workers() -> int:
    script = ('$p = @(Get-CimInstance Win32_Process -ErrorAction Stop | '
              'Where-Object { $_.Name -in @(\'python.exe\',\'pythonw.exe\') -and '
              '$_.CommandLine -and $_.CommandLine.IndexOf(\'' + WORKER.replace("'", "''") +
              '\',[StringComparison]::OrdinalIgnoreCase) -ge 0 }); Write-Output $p.Count')
    check = subprocess.run(['powershell.exe','-NoProfile','-NonInteractive','-Command',script],
                           capture_output=True, text=True, timeout=15, check=True)
    return int(check.stdout.strip().splitlines()[-1])


def main() -> int:
    if os.name != 'nt':
        raise RuntimeError('WINDOWS_ONLY')
    if not LAUNCH.is_file():
        raise RuntimeError('PROFILE_LAUNCHER_MISSING')
    api = ctypes.WinDLL('kernel32', use_last_error=True)
    api.CreateMutexW.argtypes = [ctypes.c_void_p, ctypes.c_int, ctypes.c_wchar_p]
    api.CreateMutexW.restype = ctypes.c_void_p
    mutex = api.CreateMutexW(None, 0, MUTEX_NAME)
    if not mutex:
        raise OSError(ctypes.get_last_error(), 'MUTEX_FAILED')
    duplicate = ctypes.get_last_error() == 183
    try:
        if duplicate:
            return 3
        while True:
            try:
                count = workers()
                if count > 1:
                    print('PROFILE_DUPLICATE_WORKERS', flush=True)
                    time.sleep(10)
                    continue
                if count == 1:
                    time.sleep(3)
                    continue
                print('PROFILE_GUARDIAN_LAUNCH', flush=True)
                proc = subprocess.Popen(['powershell.exe', '-NoProfile', '-NonInteractive',
                    '-ExecutionPolicy', 'Bypass', '-File', str(LAUNCH)], cwd=str(ROOT))
                code = proc.wait()
                print(f'PROFILE_GUARDIAN_WORKER_EXIT code={code}', flush=True)
                # Backoff: never spawn in a tight loop on broken credentials.
                time.sleep(15 if code else 3)
            except Exception as exc:
                print(f'PROFILE_GUARDIAN_ERROR {type(exc).__name__}:{exc}', flush=True)
                time.sleep(15)
    finally:
        api.CloseHandle(mutex)


if __name__ == '__main__':
    sys.exit(main())
