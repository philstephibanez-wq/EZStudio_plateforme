#!/usr/bin/env python3
"""Check all commits before push. Git hooks are bypassable; require server branch protection."""
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parent.parent
ZERO = '0' * 40

def run(*args, input_data=None):
    return subprocess.run(args, input=input_data, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, text=True, cwd=ROOT)

def main():
    if len(sys.argv) < 2 or sys.argv[1] != 'pre-push':
        return 2
    updates = sys.stdin.read().splitlines()
    if not updates:
        print('EZS_PREPUSH_BLOCKED: missing ref update data', file=sys.stderr)
        return 2
    for line in updates:
        cols = line.split()
        if len(cols) != 4:
            print('EZS_PREPUSH_BLOCKED: malformed ref update', file=sys.stderr)
            return 2
        _, local, _, remote = cols
        if local == ZERO:
            print('EZS_PREPUSH_BLOCKED: ref deletion requires manual governance', file=sys.stderr)
            return 1
        if remote == ZERO:
            # New branch: all reachable commits not already on remote branches.
            commits = run('git', 'rev-list', local, '--not', '--remotes')
        else:
            commits = run('git', 'rev-list', local, '^' + remote)
        if commits.returncode:
            print('EZS_PREPUSH_BLOCKED: cannot enumerate commits', file=sys.stderr)
            return 2
        # The tip is always validated even when no commits are detected as "new".
        refs = set(commits.stdout.splitlines()) | {local}
        for rev in sorted(refs):
            result = subprocess.run([sys.executable, str(ROOT/'architecture_contracts/validate.py'),
                                     '--root', str(ROOT), '--mode', 'ref', '--ref', rev], cwd=ROOT)
            if result.returncode:
                print('EZS_PREPUSH_BLOCKED: ' + rev, file=sys.stderr)
                return result.returncode
    return 0

if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except (OSError, ValueError) as exc:
        print(f'EZS_PREPUSH_ERROR {exc}', file=sys.stderr)
        raise SystemExit(2)
