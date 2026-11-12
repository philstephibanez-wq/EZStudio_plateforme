"""Read-only, deterministic audit of normalized PROFILE consultations."""
import hashlib
import json

def audit(report):
    if report.get("schema") != "ezstudio.diagnostics.main.v1" or report.get("producer") != "assistant":
        raise ValueError("archive_schema_invalid")
    fields=report["fields"]
    if fields["run_id"] != report["run_id"] or fields["id"] != report["revision_id"]:
        raise ValueError("archive_identity_invalid")
    comparison=fields["comparison_json"]
    review=comparison["module_consultations"]
    records=review["results"]
    if not records or review.get("complete") is not True or review["examined"] != len(records) or review["item_total"] != len(records):
        raise ValueError("coverage_invalid")
    seen=set()
    checks=[]
    for index, record in enumerate(records,1):
        key=record["item_id"]
        assessment=record["assessment"]
        if key in seen or record["index"]!=index or record["status"] not in ("completed","abstain") or assessment["status"]!=record["status"]:
            raise ValueError("module_invalid")
        seen.add(key)
        checks.append({"module":key,"status":record["status"],"source_sha256":record["source_sha256"],
                       "supporting_count":len(assessment["supporting_evidence"]),
                       "conflicting_count":len(assessment["conflicting_evidence"]),
                       "uncertainty_provided":bool(assessment.get("uncertainty"))})
    findings=[]
    if all(x["conflicting_count"]==0 for x in checks):
        findings.append({"code":"NO_CONTRADICTIONS_REPORTED","interpretation":"No contradiction reported does not establish scientific agreement.","needs_source_crosscheck":True})
    tonal=sorted(x for x in seen if x.startswith("tonal."))
    if len(tonal)>1:
        findings.append({"code":"TONAL_CALIBRATION_UNVERIFIED","modules":tonal,"interpretation":"Confidence values from different modules cannot be assumed comparable.","needs_reference_corpus":True})
    coverage=comparison.get("assistant_ai",{}).get("coverage",{})
    if coverage.get("scored_count")==0:
        findings.append({"code":"ZERO_VALIDATED_DIMENSIONS","count":0,"total":coverage.get("total_count"),"needs_external_reference":True})
    raw=json.dumps({"checks":checks,"findings":findings},sort_keys=True,ensure_ascii=False).encode("utf-8")
    return {"schema":"ezstudio.profile-critical-audit.r1","run_id":report["run_id"],"revision_id":report["revision_id"],
            "module_total":len(checks),"module_checks":checks,"findings":findings,
            "assessment_sha256":hashlib.sha256(raw).hexdigest(),
            "no_inference_performed":True,"no_preset_mutation":True,"scientific_crosscheck_performed":False}
