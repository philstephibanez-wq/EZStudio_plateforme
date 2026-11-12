from __future__ import annotations

import json
import urllib.error
import urllib.request
from typing import Any


class AnalysisApiError(RuntimeError):
    pass


class AnalysisApiClient:
    """HTTP client for EZStudio analysis/distributed endpoints.

    The historic /jobs/claim endpoint remains deliberately unused.
    Distributed claim methods exist only under explicit names and are not
    exposed by the CLI in V1C1.
    """

    def __init__(self, base_url: str, token: str, user_agent: str = "EZStudioLab_assistant/1C1"):
        self.base_url = str(base_url).rstrip("/")
        self.token = str(token)
        self.user_agent = user_agent

    def request(self, method: str, path: str, payload: dict[str, Any] | None = None, *, timeout: float = 10.0) -> dict[str, Any]:
        body = None
        headers = {"Accept": "application/json", "X-EZScore-Analysis-Token": self.token, "User-Agent": self.user_agent}
        if payload is not None:
            body = json.dumps(payload).encode("utf-8")
            headers["Content-Type"] = "application/json"
        req = urllib.request.Request(self.base_url + path, data=body, headers=headers, method=method)
        try:
            with urllib.request.urlopen(req, timeout=timeout) as response:
                raw = response.read()
                if int(response.status) == 204 or not raw:
                    return {}
                parsed = json.loads(raw.decode("utf-8"))
                if not isinstance(parsed, dict):
                    raise AnalysisApiError(f"invalid_json_object:{path}")
                return parsed
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode("utf-8", errors="replace")
            raise AnalysisApiError(f"HTTP {exc.code} {path}: {detail[:500]}") from exc
        except (OSError, json.JSONDecodeError) as exc:
            raise AnalysisApiError(f"{path}: {type(exc).__name__}:{exc}") from exc

    def queue(self) -> list[dict[str, Any]]:
        payload = self.request("GET", "/internal/analysis/desktop/jobs/queue")
        jobs = payload.get("jobs") or []
        if not isinstance(jobs, list): raise AnalysisApiError("queue_jobs_not_list")
        return [row for row in jobs if isinstance(row, dict)]

    def hello(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self.request("POST", "/internal/analysis/desktop/hello", payload)

    def heartbeat(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self.request("POST", "/internal/analysis/desktop/heartbeat", payload)

    def distributed_worker_heartbeat(self, worker_id: str, capabilities: dict[str, Any], *, state: str = "idle") -> dict[str, Any]:
        return self.request("POST", "/internal/analysis/desktop/distributed/workers/heartbeat", {"worker_id": worker_id, "capabilities": capabilities, "state": state})

    def distributed_eligible_jobs(self, worker_id: str, *, limit: int = 100) -> list[dict[str, Any]]:
        payload = self.request("POST", "/internal/analysis/desktop/distributed/jobs/eligible", {"worker_id": worker_id, "limit": max(1, min(500, int(limit)))})
        jobs = payload.get("jobs") or []
        if not isinstance(jobs, list): raise AnalysisApiError("distributed_jobs_not_list")
        return [row for row in jobs if isinstance(row, dict)]

    def distributed_claim_job(self, job_id: int, worker_id: str, *, lease_seconds: int = 60) -> dict[str, Any]:
        return self.request("POST", f"/internal/analysis/desktop/distributed/jobs/{int(job_id)}/claim", {"worker_id": worker_id, "lease_seconds": max(30, min(300, int(lease_seconds)))})

    def distributed_lease_heartbeat(self, job_id: int, worker_id: str, lease_token: str, *, lease_seconds: int = 60) -> dict[str, Any]:
        return self.request("POST", f"/internal/analysis/desktop/distributed/jobs/{int(job_id)}/lease-heartbeat", {"worker_id": worker_id, "lease_token": lease_token, "lease_seconds": max(30, min(300, int(lease_seconds)))})

    def distributed_context(self, job_id: int, worker_id: str, lease_token: str) -> dict[str, Any]:
        return self.request("POST", f"/internal/analysis/desktop/distributed/jobs/{int(job_id)}/context", {"worker_id": worker_id, "lease_token": lease_token})

    def distributed_progress(self, job_id: int, worker_id: str, lease_token: str, progress: int) -> dict[str, Any]:
        return self.request("POST", f"/internal/analysis/desktop/distributed/jobs/{int(job_id)}/progress", {"worker_id": worker_id, "lease_token": lease_token, "progress": max(0, min(100, int(progress)))})

    def distributed_complete(self, job_id: int, worker_id: str, lease_token: str) -> dict[str, Any]:
        return self.request("POST", f"/internal/analysis/desktop/distributed/jobs/{int(job_id)}/complete", {"worker_id": worker_id, "lease_token": lease_token})

    def distributed_cancelled(self, job_id: int, worker_id: str, lease_token: str) -> dict[str, Any]:
        return self.request("POST", f"/internal/analysis/desktop/distributed/jobs/{int(job_id)}/cancelled", {"worker_id": worker_id, "lease_token": lease_token})


    def profile_review_queue(self, *, limit: int = 100) -> list[dict[str, Any]]:
        effective = max(1, min(500, int(limit)))
        payload = self.request("GET", f"/internal/analysis/desktop/profile/reviews/queue?limit={effective}")
        reviews = payload.get("reviews") or []
        if not isinstance(reviews, list):
            raise AnalysisApiError("profile_review_queue_not_list")
        return [row for row in reviews if isinstance(row, dict)]

    def submit_profile_review(self, payload: dict[str, Any]) -> dict[str, Any]:
        if not isinstance(payload, dict):
            raise AnalysisApiError("profile_review_payload_must_be_object")
        return self.request("POST", "/internal/analysis/desktop/profile/reviews", payload)

    def distributed_fail(self, job_id: int, worker_id: str, lease_token: str, error: str) -> dict[str, Any]:
        return self.request("POST", f"/internal/analysis/desktop/distributed/jobs/{int(job_id)}/fail", {"worker_id": worker_id, "lease_token": lease_token, "error": str(error)[:400]})
