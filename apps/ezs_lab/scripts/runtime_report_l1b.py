"""Read-only L1B phase/child coverage report; never publishes or touches SQLite.

Input: existing PROFILE phase-events JSONL; no audio, source paths or prompts.
Output: local summary of events, elapsed durations, attestation and coverage.
"""
from __future__ import annotations
import argparse
from collections import defaultdict
from datetime import datetime, timezone
import json
from pathlib import Path

START_NAMES = {"model_start":"model_done", "inference_start":"inference_done"}
PARENT_PAIRS = {"subprocess_start":"subprocess_done"}
CHILD_REQUIRED = ("start", "imports_done", "cuda_verified", "audio_loaded", "model_start", "model_done", "inference_start", "inference_done", "result_written")


def summarize(events):
    groups=defaultdict(list)
    for ev in events:
        if ev.get("schema") != "ezstudio.observability.profile-phase.v1":
            continue
        module=ev.get("module")
        phase=ev.get("phase")
        if not isinstance(module,str) or not isinstance(phase,str):
            continue
        groups[module].append(ev)
    result={}
    for module,rows in sorted(groups.items()):
        rows.sort(key=lambda x:(x.get("monotonic_ns") if isinstance(x.get("monotonic_ns"),int) else -1))
        phases={r["phase"]:r for r in rows}
        pids=sorted(set(int(row["pid"]) for row in rows if isinstance(row.get("pid"),int)))
        spans={}
        for start,end in {**START_NAMES,**PARENT_PAIRS}.items():
            if start in phases and end in phases and phases[start].get("pid")==phases[end].get("pid"):
                x,y=phases[start].get("monotonic_ns"),phases[end].get("monotonic_ns")
                if isinstance(x,int) and isinstance(y,int) and y>=x:
                    spans[start.replace("_start","")+"_seconds"] = round((y-x)/1e9,6)
        # Wall intervals are only meaningful within the same monotonic clock domain.
        for start,end,field in [("start","imports_done","startup_to_imports_seconds"),("imports_done","audio_loaded","imports_to_audio_loaded_seconds"),("audio_loaded","model_start","pre_model_seconds"),("inference_done","aggregation_done","aggregation_seconds"),("aggregation_done","result_written","result_write_seconds")]:
            if start in phases and end in phases and phases[start].get("pid")==phases[end].get("pid"):
                x,y=phases[start].get("monotonic_ns"),phases[end].get("monotonic_ns")
                if isinstance(x,int) and isinstance(y,int) and y>=x:
                    spans[field]=round((y-x)/1e9,6)
        attestations=[{"phase":r["phase"],"device_index":r.get("cuda_device_index"),"device_name":r.get("cuda_device_name"),"allocated_mib":r.get("cuda_allocated_mib"),"evidence":"worker_torch_cuda_runtime"} for r in rows if "cuda_device_name" in r]
        if module=="passt_child":
            missing=[p for p in CHILD_REQUIRED if p not in phases]
            coverage={"instrumented_stage_markers":len(CHILD_REQUIRED)-len(missing),"required_stage_markers":len(CHILD_REQUIRED),"missing_stage_markers":missing,"actual_cuda_device_attested":bool(attestations),"gpu_utilization_attributed_to_pid":False,"gpu_compute_time_attested":False,"synchronous_cuda_kernel_timing":False}
        else:
            coverage={"stage_markers":len(rows),"actual_cuda_device_attested":False,"subprocess_internal_observed":module!="passt" or "passt_child" in groups}
        result[module]={"event_count":len(rows),"pids":pids,"spans_seconds":spans,"attestation":attestations,"coverage":coverage}
    if "passt" in result and "passt_child" not in result:
        result["passt"]["coverage"]["subprocess_internal_observed"]=False
    return {"schema":"ezstudio.runtime-report.l1b.local.v1","scope":"local_metadata_only_no_automatic_publication","modules":result,"coverage_warning":"Absence of gaps in recorded markers does not establish full runtime coverage. CPU/GPU attribution and CUDA kernel timing are separate."}


def main():
    p=argparse.ArgumentParser()
    p.add_argument("--observability-root",type=Path,required=True)
    p.add_argument("--run",required=True)
    p.add_argument("--output",type=Path,required=True)
    args=p.parse_args()
    if not args.run.startswith("run-") or not args.run[4:].isdigit():
        p.error("run must be run-<digits>")
    source=args.observability_root/"profile"/"phase-events"/args.run/"phases.jsonl"
    if not source.is_file():
        p.error("PHASE_EVENTS_MISSING")
    events=[]
    for i,line in enumerate(source.read_text(encoding="utf-8").splitlines(),1):
        if not line.strip():continue
        try:
            obj=json.loads(line)
            if isinstance(obj,dict):events.append(obj)
        except json.JSONDecodeError as e:
            p.error(f"INVALID_JSONL_LINE:{i}:{e.msg}")
    result=summarize(events)
    args.output.parent.mkdir(parents=True,exist_ok=True)
    args.output.write_text(json.dumps(result,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    print("RUNTIME_L1B_REPORT_OK modules="+str(len(result["modules"]))+" child="+str("passt_child" in result["modules"]))

if __name__=="__main__":
    main()
