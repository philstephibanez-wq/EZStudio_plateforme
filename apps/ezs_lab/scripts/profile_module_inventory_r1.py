"""Inventaire R1 des modules PROFILE à partir d'une archive JSON LAB existante.

Lecture seule. Aucune conclusion musicale ou validation scientifique implicite.
"""
import argparse
import hashlib
import json
from pathlib import Path


def canonical(value):
    return json.dumps(value, sort_keys=True, ensure_ascii=False, separators=(",", ":"), allow_nan=False).encode("utf-8")


def module_inventory(report):
    if report.get("producer") != "lab" or report.get("phase") != "profile" or not isinstance(report.get("run_id"), int):
        raise ValueError("archive_profile_lab_required")
    data = report.get("fields", report)
    if "diagnostics_json" in data:
        diagnostics = data["diagnostics_json"]
    else:
        diagnostics = data.get("diagnostics")
    if not isinstance(diagnostics, dict):
        raise ValueError("diagnostics_missing")
    registry = diagnostics.get("module_registry")
    if not isinstance(registry, dict) or not isinstance(registry.get("modules"), list):
        raise ValueError("module_registry_missing")
    modules = registry["modules"]
    items = []
    seen = set()
    for index, module in enumerate(modules):
        if not isinstance(module, dict) or not isinstance(module.get("id"), str) or not module["id"]:
            raise ValueError("module_invalid")
        identifier = module["id"]
        if identifier in seen:
            raise ValueError("duplicate_module_id:" + identifier)
        seen.add(identifier)
        items.append({
            "index": index + 1,
            "item_id": identifier,
            "label": module.get("name") or identifier,
            "family": module.get("family"),
            "status": module.get("status"),
            "source_pointer": "/diagnostics/module_registry/modules/" + str(index),
            "source_sha256": hashlib.sha256(canonical(module)).hexdigest(),
            "field_count": len(module),
            "candidate_data_preserved_in_source": True,
        })
    counts = (diagnostics.get("profile_view") or {}).get("counts") or {}
    return {
        "schema":"ezstudio.profile-module-inventory.r1",
        "run_id":report["run_id"],
        "archive_schema":report.get("schema"),
        "source_sha256":hashlib.sha256(canonical(data)).hexdigest(),
        "module_count":len(items),
        "module_status_counts":{status:sum(x["status"]==status for x in items) for status in sorted({str(x["status"]) for x in items})},
        "source_counts":counts,
        "coverage":{"enumerated":len(items),"registry_total":len(modules),
                    "complete_for_supplied_registry":len(items)==len(modules),
                    "scientific_database_exhaustiveness_verified":False},
        "items":items,
    }


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("archive",type=Path)
    parser.add_argument("--output",type=Path,required=True)
    args=parser.parse_args()
    if args.archive.resolve()==args.output.resolve():
        parser.error("source and output must differ")
    data=json.loads(args.archive.read_text(encoding="utf-8"))
    result=module_inventory(data)
    args.output.parent.mkdir(parents=True,exist_ok=True)
    args.output.write_text(json.dumps(result,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    print("PROFILE_R1_INVENTORY run=%s modules=%s status=%s"%(result["run_id"],result["module_count"],result["module_status_counts"]))

if __name__=="__main__":
    main()
