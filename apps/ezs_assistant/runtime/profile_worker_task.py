"""Own the existing PROFILE FIFO worker in a headless Windows scheduled task.

Task Scheduler provides instance exclusivity/restarts. This module does not create
jobs, a new queue, or new LAB endpoints. The existing worker owns claim/finish.
"""
from __future__ import annotations

from contextlib import redirect_stderr, redirect_stdout
from datetime import datetime, timezone
from pathlib import Path
import json
import os
import runpy
import sys
import traceback

ROOT = Path(__file__).resolve().parents[1]
WORKER = ROOT / 'runtime' / 'profile_ai_requests_worker.py'
LOG = ROOT / 'var' / 'log' / 'profile_fifo_worker_171.log'


def main() -> int:
    LOG.parent.mkdir(parents=True, exist_ok=True)
    with LOG.open('a', encoding='utf-8', buffering=1) as log:
        with redirect_stdout(log), redirect_stderr(log):
            print(json.dumps({
                'event': 'profile.worker.task.start',
                'at_utc': datetime.now(timezone.utc).isoformat(),
                'pid': os.getpid(),
                'worker_entrypoint': 'runtime/profile_ai_requests_worker.py',
                'execution': 'existing_fifo_polling_mode',
            }), flush=True)
            os.chdir(ROOT)
            sys.argv = [str(WORKER), '--poll-seconds', '8']
            try:
                runpy.run_path(str(WORKER), run_name='__main__')
            except SystemExit as exc:
                if exc.code in (None, 0):
                    return 0
                print('PROFILE_WORKER_STOP_EXIT=' + str(exc.code), flush=True)
                return 1
            except BaseException:
                traceback.print_exc()
                return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
