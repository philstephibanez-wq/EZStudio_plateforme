"""Authenticated read-only PROFILE scientific-item client for MAIN.

No queue claim, Qwen invocation, scientific mutation or local persistent data.
"""
from __future__ import annotations
import hashlib
import json
from typing import Any, Callable

SCHEMA = "ezstudio.profile-scientific-items.r1"


def validate_items(payload: Any, run_id: int) -> dict:
    if not isinstance(payload, dict) or payload.get("schema") != SCHEMA:
        raise ValueError("scientific_items_schema_invalid")
    if payload.get("run_id") != run_id or not isinstance(payload.get("items"), list):
        raise ValueError("scientific_items_identity_invalid")
    items = payload["items"]
    coverage = payload.get("coverage")
    if not isinstance(coverage, dict) or payload.get("item_total") != len(items) or coverage.get("registry_total") != len(items) or coverage.get("returned") != len(items) or coverage.get("complete_for_registry") is not True:
        raise ValueError("scientific_items_coverage_mismatch")
    seen = set()
    for index, item in enumerate(items):
        if not isinstance(item, dict):
            raise ValueError("scientific_item_invalid")
        key = item.get("item_id")
        if not isinstance(key, str) or not key or key in seen:
            raise ValueError("scientific_item_duplicate_or_missing_id")
        seen.add(key)
        if item.get("index") != index + 1 or item.get("source_pointer") != "/diagnostics/module_registry/modules/" + str(index):
            raise ValueError("scientific_item_order_invalid")
        module = item.get("module")
        if not isinstance(module, dict) or module.get("id") != key:
            raise ValueError("scientific_item_module_mismatch")
    return payload


def fetch_items(run_id: int, api_get: Callable[[str], dict]) -> dict:
    if not isinstance(run_id, int) or isinstance(run_id, bool) or run_id <= 0:
        raise ValueError("scientific_run_id_invalid")
    path = "/internal/analysis/desktop/profile/" + str(run_id) + "/scientific-items"
    return validate_items(api_get(path), run_id)


def describe_items(payload: dict) -> dict:
    items = payload["items"]
    return {
        "schema": "ezstudio.profile-scientific-items-diagnostic.r1",
        "run_id": payload["run_id"],
        "item_total": len(items),
        "items": [
            {"index": item["index"], "item_id": item["item_id"],
             "family": item.get("family"), "status": item.get("status"),
             "sha256": hashlib.sha256(json.dumps(item["module"], sort_keys=True, ensure_ascii=False, separators=(",", ":"), allow_nan=False).encode("utf-8")).hexdigest()}
            for item in items
        ],
        "no_inference_performed": True,
    }
