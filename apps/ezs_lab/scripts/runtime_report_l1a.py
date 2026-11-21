"""Consolidate existing PROFILE observability and phase markers, read-only.

Writes a local metadata-only report. Does not publish to GitHub or access SQLite.
No audio/model bytes, paths, command lines or secrets are exported into JSON.
"""
from __future__ import annotations

import argparse
import json
from datetime import datetime, timezone
from pathlib import Path
from collections import defaultdict


def _rows(path):
    if not path.is_file():
        return []
    data = []
    for number, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        try:
            o = json.loads(line)
            if isinstance(o, dict) and o.get("schema") == "ezstudio.observability.profile-phase.v1":
                data.append(o)
        except (ValueError, TypeError):
            data.append({"module": "telemetry", "phase": "invalid_jsonl_record", "invalid_line_number": number})
    return data


def _duration(a, b):
    try:
        if a.get("pid") != b.get("pid"):
            return None
        delta = int(b["monotonic_ns"]) - int(a["monotonic_ns"])
        return round(delta / 1e9, 6) if delta >= 0 else None
    except (TypeError, ValueError, KeyError):
        return None


def phases(rows):
    groups = defaultdict(list)
    for x in rows:
        groups[str(x.get("module", "unknown"))].append(x)
    output = {}
    for module, events in groups.items():
        open_events = defaultdict(list)
        spans = []
        missing = []
        prev = None
        for row in events:
            label = str(row.get("phase", "unknown"))
            if label.endswith("_start"):
                open_events[label[:-6]].append(row)
            elif label.endswith("_done"):
                stem = label[:-5]
                if open_events.get(stem):
                    first = open_events[stem].pop()
                    spans.append({"phase": stem, "seconds": _duration(first, row),
                                  "method": "explicit_start_done"})
                elif prev is not None:
                    spans.append({"phase": label, "seconds": _duration(prev, row),
                                  "method": "since_previous_marker"})
                else:
                    missing.append("unpaired_done:" + label)
            elif prev is not None:
                spans.append({"phase": label, "seconds": _duration(prev, row),
                              "method": "since_previous_marker"})
            prev = row
        missing += ["unpaired_start:"+k for k,v in open_events.items() for _ in v]
        output[module] = {"event_count": len(events), "spans": spans, "coverage_gaps": missing}
    return output


def build(root: Path, run: str, hashdir: str):
    if not run.startswith("run-") or not run[4:].isdigit():
        raise ValueError("invalid_run_name")
    if len(hashdir) != 64 or any(c not in "0123456789abcdef" for c in hashdir.lower()):
        raise ValueError("invalid_sha256_directory")
    directory = root / "profile" / hashdir / run
    if not directory.is_dir():
        raise FileNotFoundError("profile_telemetry_directory_missing")
    records = []
    for f in sorted(directory.glob("*.json")):
        if f.is_symlink():
            continue
        try:
            data = json.loads(f.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            continue
        if data.get("schema") != "ezstudio.observability.profile-module.v1":
            continue
        module = data.get("module") or {}
        runtime = data.get("runtime_telemetry_v1") or {}
        summary = data.get("summary") or {}
        records.append({
            "module_id": module.get("id"), "status": data.get("status"),
            "declared_device": module.get("declared_device") or "unreported",
            "wall_seconds": data.get("elapsed_seconds"),
            "process_cpu_seconds": runtime.get("process_cpu_seconds"),
            "peak_rss_mib_sampled": runtime.get("peak_rss_mib_sampled"),
            "peak_child_rss_mib_sampled": runtime.get("peak_child_rss_mib_sampled"),
            "gpu_host_utilization_mean_percent": (summary.get("gpu_util_percent") or {}).get("mean"),
            "gpu_host_utilization_max_percent": (summary.get("gpu_util_percent") or {}).get("max"),
            "gpu_sample_count": summary.get("sample_count", 0),
            "runtime_sample_count": runtime.get("sample_count", 0),
            "actual_physical_device": "unverified",
            "gpu_process_attribution": "unverified",
            "process_counters": "available" if runtime.get("sample_count") else "not_available",
        })
    phase_path = root / "profile" / "phase-events" / run / "phases.jsonl"
    phase_list = _rows(phase_path)
    spans = phases(phase_list)
    return {
        "schema": "ezstudio.runtime-report.local.v1",
        "scope": "local_metadata_only_no_automatic_publication",
        "run": run,
        "created_utc": datetime.now(timezone.utc).isoformat(),
        "module_count": len(records),
        "modules": records,
        "phases": spans,
        "coverage": {
            "phase_events_available": bool(phase_list),
            "cpu_process_metrics": "available_if_runtime_telemetry_present",
            "cuda_actual_device": "not_attested_by_this_deliverable",
            "vulkan_offload": "not_attested_by_this_deliverable",
            "intel_gpu": "not_instrumented",
            "subprocess_internal_gpu_timing": "not_instrumented",
            "decode_count": "not_instrumented",
            "other_applications": "not_instrumented_in_L1A",
            "report_publisher": "not_modified_privacy_gate_preserved",
        },
    }


def main(argv=None):
    ap = argparse.ArgumentParser(description="Rapport runtime PROFILE local, sans transfert GitHub")
    ap.add_argument("--observability-root", type=Path, required=True)
    ap.add_argument("--run", required=True, help="run-000201")
    ap.add_argument("--audio-sha256", required=True)
    ap.add_argument("--output", type=Path, required=True)
    a = ap.parse_args(argv)
    o = build(a.observability_root, a.run, a.audio_sha256)
    a.output.parent.mkdir(parents=True, exist_ok=True)
    tmp = a.output.with_suffix(".pending")
    tmp.write_text(json.dumps(o, ensure_ascii=False, indent=2, allow_nan=False)+"\n", encoding="utf-8")
    tmp.replace(a.output)
    print("RUNTIME_REPORT_OK modules="+str(o["module_count"]))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
