#!/usr/bin/env python3
"""EZS static architecture guard. Evaluates Git index or committed Git tree, never unstaged worktree.

Scope R1 is deliberately limited to the checks listed in rules.json; do not claim full
compliance with EZS-ARCH-001..010 until the remaining contracts are implemented.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path, PurePosixPath
import re
import subprocess
import sys

POLICY = "architecture_contracts/rules.json"
VALIDATOR = "architecture_contracts/validate.py"
# Executable R2 coverage registry: documentation cannot certify a missing validator.
# Future revisions may promote a rule only alongside its real implementation/tests.
IMPLEMENTED_COVERAGE = {
    "EZS-ARCH-001": "partial-explicit-db-paths-and-orchestrator-direct-sql",
    "EZS-ARCH-002": "not-implemented",
    "EZS-ARCH-003": "partial-lab-command-self-dispatch-and-local-claims",
    "EZS-ARCH-004": "not-implemented",
    "EZS-ARCH-005": "not-implemented",
    "EZS-ARCH-006": "not-implemented",
    "EZS-ARCH-007": "not-implemented",
    "EZS-ARCH-008": "partial-sensitive-files",
    "EZS-ARCH-009": "not-implemented",
    "EZS-ARCH-010": "foundation",
}
HOOKS = ("architecture_contracts/hooks/pre-commit", "architecture_contracts/hooks/pre-push", "architecture_contracts/hook_runner.py")
MAX_SOURCE_BYTES = 4_000_000
CODE_SUFFIXES = {".php", ".py", ".ps1", ".psm1", ".js", ".ts", ".yaml", ".yml", ".twig", ".sh", ".bat", ".cmd"}
SENSITIVE_SUFFIXES = {".sqlite", ".sqlite3", ".db", ".mp3", ".wav", ".flac", ".opus", ".ogg", ".mp4", ".pyc", ".p12", ".pfx", ".pem", ".key"}
SENSITIVE_NAMES = {".env.local", ".env.prod.local", ".env.test.local", "id_rsa", "id_ed25519"}
OLD_PHP = re.compile(r"(?i)H:[/\\]PHP[/\\]php-8\.5-x64[/\\](?:php(?:-cgi)?\.exe)")
LAB_DB = re.compile(r"(?i)(?:EZS[_-]?Lab|benchmark)\.sqlite(?:3)?")
ORCH_DB = re.compile(r"(?i)EZS[_-]?Orchestrator\.sqlite(?:3)?")
# R3 narrowly addresses the known LAB autonomous profile drain route.
# Regex is a conservative tripwire, not a proof of exclusive execution globally.
LOCAL_PROFILE_DRAIN = "apps/ezs_lab/src/command/localprofiledraincommand.php"
PROFILE_ONCE_INVOKE = re.compile(r"(?i)(?:['\"]lab:profile:once['\"]|lab:profile:once)")
PROFILE_CHILD_PROCESS = re.compile(r"(?i)\bnew\s+(?:\\?Symfony\\Component\\Process\\)?Process\s*\(")
# R4 targeted boundary tripwires. These are NOT global static-proof guarantees.
# Keep independent path filters to avoid treating authorized Orchestrator subprocess
# dispatch and LAB's own SQLite usage as foreign DB access.
LAB_COMMAND_PATH = re.compile(r"(?i)^apps/ezs_lab/src/command/[^/]+\.php$")
QUEUE_CONSUMER_PATH = "apps/orchestrator/src/command/queueconsumecommand.php"
PROFILE_ONCE_PATH = "apps/ezs_lab/src/command/profileoncecommand.php"
DIRECT_PDO = re.compile(r"(?i)\bnew\s+\\?PDO\s*\(")
SQL_ANALYSIS_JOBS = re.compile(r"(?i)\b(?:FROM|UPDATE|INTO|JOIN)\s+analysis_jobs\b")
LOCAL_DISPATCH_OWNER = re.compile(r"(?i)dispatch_owner\s*=\s*['\"]lab['\"]")
LOCAL_CLAIM = re.compile(r"(?i)UPDATE\s+analysis_jobs\s+SET\s+status\s*=\s*['\"]running['\"]")



def git(root: Path, *args: str, input_data: bytes | None = None) -> bytes:
    p = subprocess.run(["git", "-C", str(root), *args], input=input_data,
                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if p.returncode != 0:
        raise RuntimeError(f"git {' '.join(args[:2])}: {p.stderr.decode(errors='replace')[:350]}")
    return p.stdout


def git_entries(root: Path, mode: str, ref: str) -> dict[str, tuple[str, str]]:
    if mode == "index":
        data = git(root, "ls-files", "--stage", "-z")
        out = {}
        for item in data.split(b"\x00"):
            if not item:
                continue
            meta, name = item.split(b"\t", 1)
            bits = meta.decode("ascii").split()
            if bits[2] != "0":
                raise Violation("EZS-GATE-001", name.decode(errors="replace"), "unmerged index")
            out[name.decode("utf-8", "surrogateescape")] = (bits[0], bits[1])
        return out
    data = git(root, "ls-tree", "-rz", "--full-tree", ref)
    out = {}
    for item in data.split(b"\x00"):
        if not item:
            continue
        meta, name = item.split(b"\t", 1)
        file_mode, kind, sha = meta.decode("ascii").split()
        # Include gitlinks, trees and blobs in the mode audit; do not silently skip links.
        if kind != "tree":
            out[name.decode("utf-8", "surrogateescape")] = (file_mode, sha)
    return out


class Violation(Exception):
    def __init__(self, rule: str, path: str, message: str):
        self.rule, self.path, self.message = rule, path, message
        super().__init__(f"{rule} {path}: {message}")


def blob(root: Path, sha: str, path: str) -> bytes:
    size = int(git(root, "cat-file", "-s", sha).strip())
    if size > MAX_SOURCE_BYTES:
        raise Violation("EZS-GATE-008", path, f"source size exceeds {MAX_SOURCE_BYTES} bytes; cannot inspect")
    return git(root, "cat-file", "blob", sha)


def source_file(path: str) -> bool:
    return PurePosixPath(path).suffix.lower() in CODE_SUFFIXES


def scan(root: Path, entries: dict[str, tuple[str, str]], strict_coverage: bool) -> list[Violation]:
    violations = []
    for required in (POLICY, VALIDATOR, *HOOKS, ".github/workflows/architecture-contracts.yml"):
        if required not in entries:
            violations.append(Violation("EZS-GATE-006", required, "mandatory gate artifact absent from snapshot"))
    if POLICY in entries:
        try:
            cfg = json.loads(blob(root, entries[POLICY][1], POLICY).decode("utf-8-sig"))
            if cfg.get("policy") != "fail-closed":
                raise ValueError("fail-closed policy missing")
            if strict_coverage:
                coverage = cfg.get("coverage", {})
                if not isinstance(coverage, dict):
                    raise ValueError("coverage must be an object")
                unproven = [rule for rule, actual in IMPLEMENTED_COVERAGE.items()
                            if coverage.get(rule) != "complete" or actual != "complete"]
                unknown = sorted(set(coverage) - set(IMPLEMENTED_COVERAGE))
                if unproven or unknown:
                    violations.append(Violation("EZS-GATE-008", POLICY,
                        "mandatory controls lack executable proof: " + ",".join(unproven + unknown)))
        except (ValueError, UnicodeError, TypeError) as e:
            violations.append(Violation("EZS-GATE-006", POLICY, f"invalid policy: {e}"))
    for path, (file_mode, sha) in entries.items():
        # Symlinks (120000) may redirect checks to different files at runtime;
        # submodules/gitlinks (160000) are not inspected by this gate.
        if file_mode not in ("100644", "100755"):
            violations.append(Violation("EZS-GATE-009", path,
                                        f"unsupported git index mode {file_mode}"))
            continue
        basename = PurePosixPath(path).name.lower()
        suffix = PurePosixPath(path).suffix.lower()
        if (suffix in SENSITIVE_SUFFIXES or basename in SENSITIVE_NAMES
                or (basename.startswith(".env.") and basename.endswith(".local"))
                or path.lower().endswith(("-wal", "-shm"))):
            violations.append(Violation("EZS-GATE-002", path, "sensitive or persistent binary tracked"))
            continue
        if not source_file(path):
            continue
        try:
            content = blob(root, sha, path).decode("utf-8-sig")
        except UnicodeError:
            violations.append(Violation("EZS-GATE-008", path, "source is not UTF-8"))
            continue
        except Violation as e:
            violations.append(e)
            continue
        normalized_path = path.replace("\\", "/").lower()
        # The historical LAB local drain spawns profile:once directly rather than
        # obtaining authority from Orchestrator's global queue. Presence of both
        # tokens in the same snapshot is a blocking, specifically scoped finding.
        # This cannot establish that every other worker follows the queue contract.
        if normalized_path == LOCAL_PROFILE_DRAIN and PROFILE_ONCE_INVOKE.search(content) and PROFILE_CHILD_PROCESS.search(content):
            violations.append(Violation("EZS-GATE-011", path,
                "LAB local profile drain launches profile:once independently of Orchestrator global queue"))
        # R4: other LAB Command entrypoints cannot hide the same independent
        # profile drain behind a new file name; the historical file stays R3.
        if (LAB_COMMAND_PATH.match(normalized_path) and normalized_path != LOCAL_PROFILE_DRAIN
                and PROFILE_ONCE_INVOKE.search(content) and PROFILE_CHILD_PROCESS.search(content)):
            violations.append(Violation("EZS-GATE-012", path,
                "LAB command spawns lab:profile:once without global queue authority proof"))
        # R4: direct PDO access to analysis_jobs inside the Orchestrator queue
        # consumer is a private LAB DB boundary violation. Passing a path to
        # a LAB worker is not in itself direct DB access.
        if (normalized_path == QUEUE_CONSUMER_PATH and DIRECT_PDO.search(content)
                and SQL_ANALYSIS_JOBS.search(content)):
            violations.append(Violation("EZS-GATE-013", path,
                "Orchestrator queue consumer directly queries LAB analysis_jobs via PDO"))
        # R4: a LAB local claim of running scientific jobs is independent of
        # the Orchestrator claim, even when other code handles a global job ID.
        if (normalized_path == PROFILE_ONCE_PATH and LOCAL_DISPATCH_OWNER.search(content)
                and LOCAL_CLAIM.search(content)):
            violations.append(Violation("EZS-GATE-014", path,
                "PROFILE worker locally claims running analysis_jobs under dispatch_owner=lab"))
        for n, line in enumerate(content.splitlines(), 1):
            if OLD_PHP.search(line):
                violations.append(Violation("EZS-GATE-003", path, f"line {n}: obsolete PHP path"))
            normalized = path.replace("\\", "/").lower()
            # Textual, explicit path checks only; dynamic ownership requires later controls.
            if normalized.startswith(("apps/orchestrator/", "apps/ezs_assistant/")) and LAB_DB.search(line):
                if ("sqlite" in line.lower() and not line.lstrip().startswith(("//", "#", "*"))):
                    violations.append(Violation("EZS-GATE-004", path, f"line {n}: cross-app LAB DB reference; review required"))
            if normalized.startswith(("apps/ezs_lab/", "apps/ezs_assistant/")) and ORCH_DB.search(line):
                if ("sqlite" in line.lower() and not line.lstrip().startswith(("//", "#", "*"))):
                    violations.append(Violation("EZS-GATE-005", path, f"line {n}: cross-app Orchestrator DB reference; review required"))
    return violations


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=("index", "ref"), default="index")
    parser.add_argument("--ref", default="HEAD")
    parser.add_argument("--root", default=".")
    parser.add_argument("--strict-coverage", action="store_true", default=True,
                        help="Mandatory: full compliance requires every architecture control implemented")
    args = parser.parse_args()
    root = Path(args.root).resolve()
    try:
        entries = git_entries(root, args.mode, args.ref)
        # Non-staged modifications to the guard itself must not weaken local hooks.
        if args.mode == "index":
            for rel in (POLICY, VALIDATOR):
                if rel in entries:
                    src = (root / rel)
                    if not src.is_file() or src.read_bytes() != blob(root, entries[rel][1], rel):
                        raise Violation("EZS-GATE-007", rel, "working validator/policy differs from index")
            if git(root, "diff", "--cached", "--check").strip():
                raise Violation("EZS-GATE-008", "INDEX", "git diff --cached --check failed")
        violations = scan(root, entries, args.strict_coverage)
        if violations:
            for item in violations[:100]:
                print(f"FAIL {item}")
            print(f"EZS_COMMIT_GATE_BLOCKED violations={len(violations)} snapshot={args.mode}:{args.ref}")
            return 1
        print(f"EZS_COMMIT_GATE_PASS files={len(entries)} snapshot={args.mode}:{args.ref}")
        return 0
    except (Violation, RuntimeError, OSError, UnicodeError, ValueError) as e:
        print(f"EZS_COMMIT_GATE_ERROR {e}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
