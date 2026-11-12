from __future__ import annotations
import json,time
from typing import Any
from runtime_status import write_status
from transport import AnalysisApiClient, AnalysisApiError
def _capability_payload(capabilities:Any,state:str)->dict[str,Any]:
    return {"schema":"ezstudio.distributed.worker-heartbeat.v1","protocol":capabilities.protocol,"node_id":capabilities.node_id,"hostname":capabilities.hostname,"state":state,"capabilities":capabilities.to_dict()}
def observe_queue(client,capabilities):
    client.hello(_capability_payload(capabilities,"observing")); jobs=client.queue(); client.heartbeat(_capability_payload(capabilities,"idle" if not jobs else "queue_visible"))
    return {"schema":"EZStudioLab_assistant.queue-observation.v1","node_id":capabilities.node_id,"mode":"observe_only","claim_enabled":False,"reason":"use_distributed_probe_for_capability_aware_queue","queue_count":len(jobs),"jobs":jobs}
def watch_queue(client,capabilities,*,poll_seconds=5.0,iterations=0):
    count=0; client.hello(_capability_payload(capabilities,"starting"))
    while True:
        jobs=client.queue(); client.heartbeat(_capability_payload(capabilities,"idle" if not jobs else "queue_visible")); print(json.dumps({"event":"queue_snapshot","node_id":capabilities.node_id,"queue_count":len(jobs),"claim_enabled":False,"jobs":jobs},ensure_ascii=False),flush=True); count+=1
        if iterations>0 and count>=iterations:return 0
        time.sleep(poll_seconds)
def distributed_probe(client,capabilities,*,limit=100):
    worker_id=str(capabilities.node_id); cap=capabilities.to_dict(); heartbeat=client.distributed_worker_heartbeat(worker_id,cap,state="probing"); jobs=client.distributed_eligible_jobs(worker_id,limit=limit); client.distributed_worker_heartbeat(worker_id,cap,state="idle" if not jobs else "eligible_jobs_visible")
    return {"schema":"EZStudioLab_assistant.distributed-probe.v1","worker_id":worker_id,"claim_enabled":False,"scientific_execution_enabled":False,"heartbeat":heartbeat,"eligible_count":len(jobs),"eligible_jobs":jobs}
def distributed_claim_once(client,capabilities,*,job_id,lease_seconds=60,limit=100):
    worker_id=str(capabilities.node_id); cap=capabilities.to_dict(); client.distributed_worker_heartbeat(worker_id,cap,state="claim_check"); jobs=client.distributed_eligible_jobs(worker_id,limit=limit)
    eligible_ids={int(r["job_id"]) for r in jobs if isinstance(r,dict) and str(r.get("job_id","")).isdigit()}
    if int(job_id) not in eligible_ids:
        client.distributed_worker_heartbeat(worker_id,cap,state="idle"); raise RuntimeError(f"distributed_job_not_currently_eligible:{int(job_id)}")
    claim=client.distributed_claim_job(int(job_id),worker_id,lease_seconds=lease_seconds); lease=claim.get("lease")
    if not isinstance(lease,dict): raise RuntimeError("distributed_claim_missing_lease")
    if str(lease.get("worker_id") or "")!=worker_id: raise RuntimeError("distributed_claim_worker_mismatch")
    lease_token=str(lease.get("lease_token") or "").strip()
    if not lease_token: raise RuntimeError("distributed_claim_missing_lease_token")
    context=client.distributed_context(int(job_id),worker_id,lease_token); client.distributed_worker_heartbeat(worker_id,cap,state="claimed_contract_only")
    return {"schema":"EZStudioLab_assistant.distributed-claim-once.v1","worker_id":worker_id,"job_id":int(job_id),"claim_enabled":True,"scientific_execution_enabled":False,"lease":{"worker_id":worker_id,"lease_token_present":True,"expires_at_epoch":lease.get("expires_at_epoch"),"lease_seconds":lease.get("lease_seconds")},"context_received":isinstance(context,dict),"context":context}
def _distributed_error_summary(exc):
    message=str(exc).strip()
    if "worker_heartbeat_stale" in message:
        return "Worker distribué expiré côté LAB"
    if message.startswith("HTTP "):
        head=message.split(":",1)[0].strip()
        return head[:180]
    return f"{type(exc).__name__}: {message}"[:180]

def _distributed_retry_delay(poll_seconds, consecutive_errors):
    base = max(1.0, float(poll_seconds))
    factor = 2 ** min(max(0, int(consecutive_errors) - 1), 4)
    return min(30.0, base * factor)


def watch_distributed(client,capabilities,*,poll_seconds=5.0,iterations=0,limit=100):
    count=0
    worker_id=str(capabilities.node_id)
    cap=capabilities.to_dict()
    started=False
    consecutive_api_errors=0
    write_status("ONLINE","Démarrage du worker distribué")

    while True:
        try:
            if not started:
                client.distributed_worker_heartbeat(
                    worker_id,
                    cap,
                    state="starting",
                )
                started=True

            write_status("ONLINE","Recherche de job compatible")
            client.distributed_worker_heartbeat(
                worker_id,
                cap,
                state="polling",
            )
            jobs=client.distributed_eligible_jobs(worker_id,limit=limit)
            state="idle" if not jobs else "eligible_jobs_visible"
            heartbeat=client.distributed_worker_heartbeat(
                worker_id,
                cap,
                state=state,
            )
            consecutive_api_errors=0

            activity=(
                "En veille — aucun job compatible"
                if not jobs
                else f"{len(jobs)} job(s) compatible(s) détecté(s)"
            )
            write_status("ONLINE",activity)
            print(
                json.dumps(
                    {
                        "event":"distributed_queue_snapshot",
                        "worker_id":worker_id,
                        "claim_enabled":False,
                        "scientific_execution_enabled":False,
                        "eligible_count":len(jobs),
                        "eligible_jobs":jobs,
                        "heartbeat":heartbeat,
                    },
                    ensure_ascii=False,
                ),
                flush=True,
            )
            count+=1
            if iterations>0 and count>=iterations:
                return 0
            time.sleep(poll_seconds)
        except AnalysisApiError as exc:
            consecutive_api_errors+=1
            retry_seconds=_distributed_retry_delay(
                poll_seconds,
                consecutive_api_errors,
            )
            write_status(
                "ERROR",
                "Connexion LAB temporairement indisponible — nouvelle tentative",
                extra={
                    "error_type":type(exc).__name__,
                    "details":f"{type(exc).__name__}: {exc}"[:4000],
                    "retry_seconds":retry_seconds,
                    "consecutive_errors":consecutive_api_errors,
                    "worker_continues":True,
                },
            )
            print(
                json.dumps(
                    {
                        "event":"distributed_transport_retry",
                        "worker_id":worker_id,
                        "claim_enabled":False,
                        "scientific_execution_enabled":False,
                        "retry_seconds":retry_seconds,
                        "consecutive_errors":consecutive_api_errors,
                        "error":_distributed_error_summary(exc),
                    },
                    ensure_ascii=False,
                ),
                flush=True,
            )
            time.sleep(retry_seconds)
        except Exception as exc:
            write_status(
                "ERROR",
                _distributed_error_summary(exc),
                extra={
                    "error_type":type(exc).__name__,
                    "details":f"{type(exc).__name__}: {exc}"[:4000],
                    "worker_continues":False,
                },
            )
            raise

def _context_flag_true(payload, key):
    if isinstance(payload, dict):
        if payload.get(key) is True:
            return True
        return any(_context_flag_true(value, key) for value in payload.values())
    if isinstance(payload, list):
        return any(_context_flag_true(value, key) for value in payload)
    return False

def _sha256_file(path):
    import hashlib
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        while True:
            chunk = handle.read(1024 * 1024)
            if not chunk:
                break
            digest.update(chunk)
    return digest.hexdigest().upper()

def _shared_source_metadata(shared_source):
    from pathlib import Path

    value = str(shared_source or "").strip()
    if not value:
        raise RuntimeError("distributed_shared_source_missing")
    if not value.startswith("\\\\"):
        raise RuntimeError("distributed_shared_source_not_unc")

    source_path = Path(value)
    if not source_path.is_file():
        raise RuntimeError("distributed_shared_source_unreadable")

    return int(source_path.stat().st_size), _sha256_file(source_path)

def distributed_execute_protocol_once(client, capabilities, *, job_id, lease_seconds=60, limit=100):
    worker_id = str(capabilities.node_id)
    cap = capabilities.to_dict()
    jid = int(job_id)

    client.distributed_worker_heartbeat(worker_id, cap, state="execution_check")
    jobs = client.distributed_eligible_jobs(worker_id, limit=limit)

    selected = None
    for row in jobs:
        if isinstance(row, dict) and int(row.get("job_id") or 0) == jid:
            selected = row
            break

    if selected is None:
        client.distributed_worker_heartbeat(worker_id, cap, state="idle")
        raise RuntimeError(f"distributed_job_not_currently_eligible:{jid}")

    if str(selected.get("kind") or "") != "distributed_test":
        client.distributed_worker_heartbeat(worker_id, cap, state="idle")
        raise RuntimeError(
            f"distributed_execution_kind_not_allowed:{selected.get('kind')}"
        )

    claim = client.distributed_claim_job(
        jid, worker_id, lease_seconds=lease_seconds
    )
    lease = claim.get("lease")
    if not isinstance(lease, dict):
        raise RuntimeError("distributed_claim_missing_lease")
    if str(lease.get("worker_id") or "") != worker_id:
        raise RuntimeError("distributed_claim_worker_mismatch")

    lease_token = str(lease.get("lease_token") or "").strip()
    if not lease_token:
        raise RuntimeError("distributed_claim_missing_lease_token")

    completed = False
    try:
        context = client.distributed_context(jid, worker_id, lease_token)
        if not isinstance(context, dict):
            raise RuntimeError("distributed_context_invalid")
        if not _context_flag_true(context, "protocol_test_only"):
            raise RuntimeError("distributed_protocol_test_flag_required")

        paths = context.get("paths")
        if not isinstance(paths, dict):
            raise RuntimeError("distributed_context_paths_missing")

        shared_source = str(paths.get("shared_source") or "").strip()
        if not shared_source:
            raise RuntimeError("distributed_shared_source_missing")
        if not shared_source.startswith("\\\\"):
            raise RuntimeError("distributed_shared_source_not_unc")

        client.distributed_progress(jid, worker_id, lease_token, 5)
        client.distributed_lease_heartbeat(
            jid, worker_id, lease_token, lease_seconds=lease_seconds
        )
        write_status(
            "ONLINE",
            f"Job protocole #{jid} — validation source partagée",
        )

        size, sha256 = _shared_source_metadata(shared_source)

        client.distributed_progress(jid, worker_id, lease_token, 50)
        lease_heartbeat = client.distributed_lease_heartbeat(
            jid, worker_id, lease_token, lease_seconds=lease_seconds
        )
        client.distributed_progress(jid, worker_id, lease_token, 95)

        client.distributed_worker_heartbeat(
            worker_id, cap, state="completing_protocol_test"
        )
        complete = client.distributed_complete(
            jid, worker_id, lease_token
        )
        completed = True

        client.distributed_worker_heartbeat(
            worker_id, cap, state="idle"
        )
        write_status("ONLINE", f"Job protocole #{jid} terminé")

        return {
            "schema": "EZStudioLab_assistant.distributed-protocol-execution.v1",
            "worker_id": worker_id,
            "job_id": jid,
            "kind": "distributed_test",
            "protocol_test_only": True,
            "scientific_execution_enabled": False,
            "shared_source": shared_source,
            "source_size": size,
            "source_sha256": sha256,
            "lease_heartbeat": lease_heartbeat,
            "complete": complete,
        }
    except Exception as exc:
        if not completed:
            try:
                client.distributed_fail(
                    jid,
                    worker_id,
                    lease_token,
                    f"protocol_execution_failed:{type(exc).__name__}:{exc}",
                )
            except Exception:
                pass

        try:
            client.distributed_worker_heartbeat(
                worker_id, cap, state="error"
            )
        except Exception:
            pass

        write_status(
            "ERROR",
            _distributed_error_summary(exc),
            extra={
                "error_type": type(exc).__name__,
                "details": f"{type(exc).__name__}: {exc}"[:4000],
            },
        )
        raise
