"""Bounded, resumable diagnostic publishing on MAIN. No background service."""
import argparse
import re
import subprocess
import sys
from pathlib import Path

PUBLISHER = Path(__file__).with_name("publish_run_reports.py")
COUNTS = re.compile(r"REPORTS checked=(\d+) attempted=(\d+) published=(\d+) unchanged=(\d+) failed=(\d+)")


def run_batches(*, batch_size=10, max_batches=8, producer="all", runner=subprocess.run):
    totals = {"checked": 0, "attempted": 0, "published": 0, "unchanged": 0, "failed": 0}
    for batch in range(1, max_batches + 1):
        command = [sys.executable, str(PUBLISHER), "--detailed", "--detailed-only",
                   "--producer", producer, "--limit", str(batch_size)]
        process = runner(command, capture_output=True, text=True, check=False)
        if process.stdout:
            print(process.stdout, end="", flush=True)
        if process.stderr:
            print(process.stderr, end="", file=sys.stderr, flush=True)
        matches = COUNTS.findall(process.stdout or "")
        if process.returncode != 0 or len(matches) != 1:
            print(f"BATCH_STOP batch={batch} reason=publisher_error", file=sys.stderr, flush=True)
            return 1
        counts = dict(zip(totals, (int(v) for v in matches[0])))
        for key, val in counts.items():
            totals[key] += val
        if counts["failed"]:
            print(f"BATCH_STOP batch={batch} reason=failed_reports", file=sys.stderr, flush=True)
            return 1
        print(f"BATCH {batch}/{max_batches} attempted={counts['attempted']} published={counts['published']} unchanged={counts['unchanged']}", flush=True)
        if counts["checked"] == 0:
            print("BATCH_STOP reason=all_records_processed", flush=True)
            break
    else:
        print("BATCH_STOP reason=batch_cap_reached", flush=True)
    print("BATCH_TOTAL " + " ".join(f"{k}={v}" for k, v in totals.items()), flush=True)
    return 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--batch-size", type=int, default=10)
    parser.add_argument("--max-batches", type=int, default=8)
    parser.add_argument("--producer", choices=("all", "lab", "assistant"), default="all")
    args = parser.parse_args()
    if args.batch_size < 1 or args.max_batches < 1:
        parser.error("batch-size and max-batches must be >= 1")
    return run_batches(batch_size=args.batch_size, max_batches=args.max_batches, producer=args.producer)


if __name__ == "__main__":
    raise SystemExit(main())
