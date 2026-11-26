from __future__ import annotations
import argparse, json, os, sys
from pathlib import Path
ASSISTANT_ROOT=Path(__file__).resolve().parents[1]
_explicit_host=os.getenv("EZSTUDIOLAB_HOST_REPO")
if _explicit_host:
    HOST_REPO=Path(_explicit_host).resolve()
elif (ASSISTANT_ROOT/"runtime"/"python").is_dir():
    HOST_REPO=(ASSISTANT_ROOT/"runtime").resolve()
else:
    HOST_REPO=(ASSISTANT_ROOT.parent/"ezs_lab").resolve()
APP_ROOT=Path(__file__).resolve().parent
PYTHON_ROOT=HOST_REPO/"python"
if not PYTHON_ROOT.is_dir(): raise RuntimeError("EZStudio runtime python root not found. Set EZSTUDIOLAB_HOST_REPO or provide runtime\\python under the Assistant root.")
if str(APP_ROOT) in sys.path: sys.path.remove(str(APP_ROOT))
sys.path.insert(0,str(APP_ROOT))
if str(PYTHON_ROOT) not in sys.path: sys.path.insert(1,str(PYTHON_ROOT))
from ezstudio.distributed.capabilities import detect_capabilities
from ezstudio.distributed.model_resolver import ModelResolver
from ezstudio.distributed.worker_protocol import JobRequirements, compatibility_report
from gate import evaluate_profile_gate
from transport import AnalysisApiClient
from preset_guard import evaluate_preset_candidate
from worker import distributed_claim_once, distributed_execute_protocol_once, distributed_probe, observe_queue, watch_distributed, watch_queue
def _client_from_args(args):
    base_url=args.base_url or os.getenv("EZSTUDIOLAB_ASSISTANT_BASE_URL"); token=args.token or os.getenv("EZSTUDIOLAB_ASSISTANT_TOKEN")
    if not base_url: raise RuntimeError("analysis_base_url_required:set EZSTUDIOLAB_ASSISTANT_BASE_URL or --base-url")
    if not token: raise RuntimeError("analysis_token_required:set EZSTUDIOLAB_ASSISTANT_TOKEN or --token")
    return AnalysisApiClient(base_url=base_url,token=token)
def _network_args(command):
    command.add_argument("--base-url",default=None); command.add_argument("--token",default=None)
def main():
    parser=argparse.ArgumentParser(prog="EZStudioLab_assistant"); sub=parser.add_subparsers(dest="command",required=True)
    sub.add_parser("capabilities")
    resolve=sub.add_parser("resolve-model"); resolve.add_argument("relative_path"); resolve.add_argument("--cache",action="store_true")
    check=sub.add_parser("check-job"); check.add_argument("--requires-cuda",action="store_true"); check.add_argument("--min-vram-mb",type=int,default=0); check.add_argument("--min-ram-mb",type=int,default=0); check.add_argument("--model",default=None)
    queue=sub.add_parser("queue"); _network_args(queue)
    watch=sub.add_parser("watch"); _network_args(watch); watch.add_argument("--poll-seconds",type=float,default=5.0); watch.add_argument("--iterations",type=int,default=0)
    probe=sub.add_parser("distributed-probe"); _network_args(probe); probe.add_argument("--limit",type=int,default=100)
    dwatch=sub.add_parser("distributed-watch"); _network_args(dwatch); dwatch.add_argument("--poll-seconds",type=float,default=5.0); dwatch.add_argument("--iterations",type=int,default=0); dwatch.add_argument("--limit",type=int,default=100)
    claim=sub.add_parser("distributed-claim-once"); _network_args(claim); claim.add_argument("--job-id",type=int,required=True); claim.add_argument("--lease-seconds",type=int,default=60); claim.add_argument("--limit",type=int,default=100)
    execute=sub.add_parser("distributed-execute-protocol-once"); _network_args(execute); execute.add_argument("--job-id",type=int,required=True); execute.add_argument("--lease-seconds",type=int,default=60); execute.add_argument("--limit",type=int,default=100)
    guard=sub.add_parser("profile-guard"); guard.add_argument("baseline_json"); guard.add_argument("candidate_json"); guard.add_argument("--threshold",type=float,default=0.75); guard.add_argument("--metric-tolerance",type=float,default=0.0); guard.add_argument("--max-song-regression",type=float,default=0.10)
    gate=sub.add_parser("evaluate-gate"); gate.add_argument("report_json"); args=parser.parse_args()
    if args.command=="capabilities": print(detect_capabilities().to_json()); return 0
    resolver=ModelResolver() if args.command in {"resolve-model","check-job"} else None
    if args.command=="resolve-model":
        resolved=resolver.resolve(args.relative_path,use_cache=bool(args.cache)); print(json.dumps({"schema":"EZStudioLab_assistant.model-resolution.v1","relative_path":args.relative_path,"resolved_path":str(resolved),"cached":bool(args.cache)},ensure_ascii=False,indent=2)); return 0
    if args.command=="check-job":
        report=compatibility_report(detect_capabilities(),JobRequirements(requires_cuda=bool(args.requires_cuda),min_vram_mb=max(0,int(args.min_vram_mb)),min_ram_mb=max(0,int(args.min_ram_mb)),model_relative_path=args.model),model_resolver=resolver if args.model else None)
        print(json.dumps(report,ensure_ascii=False,indent=2)); return 0 if report["compatible"] else 2
    if args.command=="queue": print(json.dumps(observe_queue(_client_from_args(args),detect_capabilities()),ensure_ascii=False,indent=2)); return 0
    if args.command=="watch": return watch_queue(_client_from_args(args),detect_capabilities(),poll_seconds=max(1.0,float(args.poll_seconds)),iterations=max(0,int(args.iterations)))
    if args.command=="distributed-probe": print(json.dumps(distributed_probe(_client_from_args(args),detect_capabilities(),limit=max(1,min(500,int(args.limit)))),ensure_ascii=False,indent=2)); return 0
    if args.command=="distributed-watch": return watch_distributed(_client_from_args(args),detect_capabilities(),poll_seconds=max(1.0,float(args.poll_seconds)),iterations=max(0,int(args.iterations)),limit=max(1,min(500,int(args.limit))))
    if args.command=="distributed-claim-once":
        result=distributed_claim_once(_client_from_args(args),detect_capabilities(),job_id=max(1,int(args.job_id)),lease_seconds=max(30,min(300,int(args.lease_seconds))),limit=max(1,min(500,int(args.limit))))
        print(json.dumps(result,ensure_ascii=False,indent=2)); return 0
    if args.command=="distributed-execute-protocol-once":
        result=distributed_execute_protocol_once(_client_from_args(args),detect_capabilities(),job_id=max(1,int(args.job_id)),lease_seconds=max(30,min(300,int(args.lease_seconds))),limit=max(1,min(500,int(args.limit))))
        print(json.dumps(result,ensure_ascii=False,indent=2)); return 0
    if args.command=="profile-guard":
        baseline=json.loads(Path(args.baseline_json).read_text(encoding="utf-8")); candidate=json.loads(Path(args.candidate_json).read_text(encoding="utf-8"))
        result=evaluate_preset_candidate(baseline,candidate,threshold=float(args.threshold),metric_tolerance=max(0.0,float(args.metric_tolerance)),max_song_regression=max(0.0,float(args.max_song_regression)))
        print(json.dumps(result,ensure_ascii=False,indent=2)); return 0 if result["decision"]=="CANDIDATE_ACCEPTABLE" else 4
    if args.command=="evaluate-gate":
        payload=json.loads(Path(args.report_json).read_text(encoding="utf-8")); result=evaluate_profile_gate(payload); print(json.dumps(result,ensure_ascii=False,indent=2)); return 0 if result["decision"]=="READY_FOR_STEMS" else 3
    raise RuntimeError("unsupported_command")
if __name__=="__main__": raise SystemExit(main())
