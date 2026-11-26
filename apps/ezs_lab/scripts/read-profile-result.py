#!/usr/bin/env python3
"""Explicit read-only LAB contract inspection; no export batch or external calls."""
import argparse
import json
import sys
from pathlib import Path
ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "python/ezstudio/pipeline/profile"))
from result_projection import read_result

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--database", required=True, type=Path)
    parser.add_argument("--run-id", required=True, type=int)
    args = parser.parse_args()
    print(json.dumps(read_result(args.database, args.run_id, ROOT / "schemas"), ensure_ascii=True, allow_nan=False, indent=2))
