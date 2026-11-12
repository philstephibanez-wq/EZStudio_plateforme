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
from profile_review import load_review_payload, profile_review_queue_snapshot
from profile_auto_review import NetworkBudget, auto_review_once, auto_review_sweep, auto_review_watch
from ai_provider import ai_provider_status
from profile_diagnostic import diagnose_profile
from local_ai_runtime import local_ai_start, local_ai_status, local_ai_stop, sync_local_model_cache
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
    sub.add_parser("ai-status")
    sub.add_parser("ai-local-sync")
    sub.add_parser("ai-local-status")
    sub.add_parser("ai-local-start")
    sub.add_parser("ai-local-stop")
    resolve=sub.add_parser("resolve-model"); resolve.add_argument("relative_path"); resolve.add_argument("--cache",action="store_true")
    check=sub.add_parser("check-job"); check.add_argument("--requires-cuda",action="store_true"); check.add_argument("--min-vram-mb",type=int,default=0); check.add_argument("--min-ram-mb",type=int,default=0); check.add_argument("--model",default=None)
    queue=sub.add_parser("queue"); _network_args(queue)
    watch=sub.add_parser("watch"); _network_args(watch); watch.add_argument("--poll-seconds",type=float,default=5.0); watch.add_argument("--iterations",type=int,default=0)
    probe=sub.add_parser("distributed-probe"); _network_args(probe); probe.add_argument("--limit",type=int,default=100)
    dwatch=sub.add_parser("distributed-watch"); _network_args(dwatch); dwatch.add_argument("--poll-seconds",type=float,default=5.0); dwatch.add_argument("--iterations",type=int,default=0); dwatch.add_argument("--limit",type=int,default=100)
    claim=sub.add_parser("distributed-claim-once"); _network_args(claim); claim.add_argument("--job-id",type=int,required=True); claim.add_argument("--lease-seconds",type=int,default=60); claim.add_argument("--limit",type=int,default=100)
    execute=sub.add_parser("distributed-execute-protocol-once"); _network_args(execute); execute.add_argument("--job-id",type=int,required=True); execute.add_argument("--lease-seconds",type=int,default=60); execute.add_argument("--limit",type=int,default=100)
    reviewq=sub.add_parser("profile-review-queue"); _network_args(reviewq); reviewq.add_argument("--limit",type=int,default=100)
    auto=sub.add_parser("profile-review-auto-once"); _network_args(auto); auto.add_argument("--limit",type=int,default=100); auto.add_argument("--dry-run",action="store_true"); auto.add_argument("--min-confidence",type=float,default=0.75); auto.add_argument("--network-min-interval",type=float,default=2.0); auto.add_argument("--network-max-response-kb",type=int,default=256); auto.add_argument("--network-max-requests",type=int,default=6)
    sweep=sub.add_parser("profile-review-auto-sweep"); _network_args(sweep); sweep.add_argument("--limit",type=int,default=100); sweep.add_argument("--max-items",type=int,default=5); sweep.add_argument("--dry-run",action="store_true"); sweep.add_argument("--min-confidence",type=float,default=0.75); sweep.add_argument("--network-min-interval",type=float,default=2.0); sweep.add_argument("--network-max-response-kb",type=int,default=256); sweep.add_argument("--network-max-requests",type=int,default=6)
    paw=sub.add_parser("profile-review-auto-watch"); _network_args(paw); paw.add_argument("--state-file",required=True); paw.add_argument("--poll-seconds",type=float,default=900.0); paw.add_argument("--draft-cooldown-hours",type=float,default=24.0); paw.add_argument("--abstain-cooldown-hours",type=float,default=168.0); paw.add_argument("--limit",type=int,default=100); paw.add_argument("--max-items",type=int,default=5); paw.add_argument("--iterations",type=int,default=0); paw.add_argument("--min-confidence",type=float,default=0.75); paw.add_argument("--network-min-interval",type=float,default=2.0); paw.add_argument("--network-max-response-kb",type=int,default=256); paw.add_argument("--network-max-requests",type=int,default=6)
    diagnostic=sub.add_parser("profile-review-diagnostic"); _network_args(diagnostic); diagnostic.add_argument("--run-id",type=int,required=True); diagnostic.add_argument("--limit",type=int,default=500); diagnostic.add_argument("--dry-run",action="store_true",required=True)
    reviews=sub.add_parser("profile-review-submit"); _network_args(reviews); reviews.add_argument("review_json")
    guard=sub.add_parser("profile-guard"); guard.add_argument("baseline_json"); guard.add_argument("candidate_json"); guard.add_argument("--threshold",type=float,default=0.75); guard.add_argument("--metric-tolerance",type=float,default=0.0); guard.add_argument("--max-song-regression",type=float,default=0.10)
    gate=sub.add_parser("evaluate-gate"); gate.add_argument("report_json"); args=parser.parse_args()
    if args.command=="capabilities": print(detect_capabilities().to_json()); return 0
    if args.command=="ai-status": print(json.dumps(ai_provider_status(),ensure_ascii=True,indent=2)); return 0
    if args.command=="ai-local-sync": print(json.dumps(sync_local_model_cache(),ensure_ascii=True,indent=2)); return 0
    if args.command=="ai-local-status": print(json.dumps(local_ai_status(),ensure_ascii=True,indent=2)); return 0
    if args.command=="ai-local-start": print(json.dumps(local_ai_start(),ensure_ascii=True,indent=2)); return 0
    if args.command=="ai-local-stop": print(json.dumps(local_ai_stop(),ensure_ascii=True,indent=2)); return 0
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
    if args.command=="profile-review-auto-watch":
        def make_watch_budget():
            return NetworkBudget(min_interval_seconds=max(1.0,float(args.network_min_interval)),max_response_bytes=max(64,min(512,int(args.network_max_response_kb)))*1024,timeout_seconds=8.0,max_requests_per_item=max(2,min(8,int(args.network_max_requests))))
        result=auto_review_watch(_client_from_args(args),state_file=args.state_file,poll_seconds=max(60.0,float(args.poll_seconds)),draft_cooldown_seconds=max(60.0,float(args.draft_cooldown_hours)*3600.0),abstain_cooldown_seconds=max(60.0,float(args.abstain_cooldown_hours)*3600.0),limit=max(1,min(500,int(args.limit))),max_items=max(1,min(100,int(args.max_items))),iterations=max(0,int(args.iterations)),min_confidence=max(0.0,min(1.0,float(args.min_confidence))),budget_factory=make_watch_budget)
        print(json.dumps(result,ensure_ascii=True,indent=2)); return 0
    if args.command=="profile-review-auto-sweep":
        def make_budget():
            return NetworkBudget(min_interval_seconds=max(1.0,float(args.network_min_interval)),max_response_bytes=max(64,min(512,int(args.network_max_response_kb)))*1024,timeout_seconds=8.0,max_requests_per_item=max(2,min(8,int(args.network_max_requests))))
        result=auto_review_sweep(_client_from_args(args),limit=max(1,min(500,int(args.limit))),max_items=max(1,min(100,int(args.max_items))),dry_run=bool(args.dry_run),min_confidence=max(0.0,min(1.0,float(args.min_confidence))),budget_factory=make_budget)
        print(json.dumps(result,ensure_ascii=True,indent=2)); return 0
    if args.command=="profile-review-auto-once":
        budget=NetworkBudget(min_interval_seconds=max(1.0,float(args.network_min_interval)),max_response_bytes=max(64,min(512,int(args.network_max_response_kb)))*1024,timeout_seconds=8.0,max_requests_per_item=max(2,min(8,int(args.network_max_requests))))
        result=auto_review_once(_client_from_args(args),limit=max(1,min(500,int(args.limit))),dry_run=bool(args.dry_run),min_confidence=max(0.0,min(1.0,float(args.min_confidence))),budget=budget)
        print(json.dumps(result,ensure_ascii=True,indent=2)); return 0 if result.get("status") in {"completed","draft","abstain","idle"} else 5
    if args.command=="profile-review-queue":
        print(json.dumps(profile_review_queue_snapshot(_client_from_args(args),limit=max(1,min(500,int(args.limit)))),ensure_ascii=True,indent=2)); return 0
    if args.command=="profile-review-diagnostic":
        result=diagnose_profile(_client_from_args(args),run_id=args.run_id,limit=max(1,min(500,int(args.limit))))
        print(json.dumps(result,ensure_ascii=True,indent=2)); return 0 if result.get("status")=="completed" else 5
    if args.command=="profile-review-submit":
        payload=load_review_payload(args.review_json); result=_client_from_args(args).submit_profile_review(payload); print(json.dumps(result,ensure_ascii=False,indent=2)); return 0
    if args.command=="profile-guard":
        baseline=json.loads(Path(args.baseline_json).read_text(encoding="utf-8")); candidate=json.loads(Path(args.candidate_json).read_text(encoding="utf-8"))
        result=evaluate_preset_candidate(baseline,candidate,threshold=float(args.threshold),metric_tolerance=max(0.0,float(args.metric_tolerance)),max_song_regression=max(0.0,float(args.max_song_regression)))
        print(json.dumps(result,ensure_ascii=False,indent=2)); return 0 if result["decision"]=="CANDIDATE_ACCEPTABLE" else 4
    if args.command=="evaluate-gate":
        payload=json.loads(Path(args.report_json).read_text(encoding="utf-8")); result=evaluate_profile_gate(payload); print(json.dumps(result,ensure_ascii=False,indent=2)); return 0 if result["decision"]=="READY_FOR_STEMS" else 3
    raise RuntimeError("unsupported_command")
if __name__=="__main__": raise SystemExit(main())
