"""R1 evidence-based crosscheck of archived LAB, Qwen and XPS records.

Pure and read-only: no inference, network calls or preset mutation.
"""
from __future__ import annotations
import hashlib
import json
from profile_critical_audit import audit

def digest(value):
    return hashlib.sha256(json.dumps(value,ensure_ascii=False,sort_keys=True,separators=(",",":"),allow_nan=False).encode("utf-8")).hexdigest()

def crosscheck(lab, revision, xps):
    base=audit(revision)
    run=base["run_id"]
    if (lab.get("schema")!="ezstudio.diagnostics.main.v1" or lab.get("producer")!="lab"
        or lab.get("phase")!="profile" or lab.get("run_id")!=run
        or lab.get("fields",{}).get("id")!=run or lab.get("fields",{}).get("item")!="profile"
        or xps.get("schema")!="ezstudio.profile-execution-report.v1"
        or xps.get("run_id")!=run or xps.get("revision_id")!=base["revision_id"]):
        raise ValueError("crosscheck_archive_identity_invalid")
    registry=lab.get("fields",{}).get("diagnostics_json",{}).get("module_registry",{}).get("modules")
    records=revision["fields"]["comparison_json"]["module_consultations"]["results"]
    xps_records=xps.get("module_audit",{}).get("records")
    if not isinstance(registry,list) or not isinstance(xps_records,list) or len(registry)!=len(records) or len(xps_records)!=len(records):
        raise ValueError("crosscheck_module_coverage_invalid")
    # A privacy-redacted archive cannot reproduce a source hash exactly.
    # Report such modules as unverified; do not accept a hash mismatch as valid.
    redactions=lab.get("redactions",[])
    if not isinstance(redactions,list):
        raise ValueError("crosscheck_redactions_invalid")
    redacted_modules=set()
    for entry in redactions:
        path=entry.get("field","") if isinstance(entry,dict) else ""
        if path.startswith("$.diagnostics_json.module_registry.modules[].raw.audioset_scores."):
            redacted_modules.update(
                m["id"] for m in registry
                if isinstance(m,dict) and isinstance(m.get("raw"),dict)
                and isinstance(m["raw"].get("audioset_scores"),dict)
                and any(v=="[REDACTED_SECRET_OR_BINARY]" for v in m["raw"]["audioset_scores"].values())
            )
    # Export-side attestations are only accepted when the archived object
    # independently matches its post-redaction hash. Pre-redaction SHA must
    # independently match both Qwen's source SHA and the XPS trace.
    attestations=lab.get("module_integrity",[])
    if not isinstance(attestations,list):
        raise ValueError("crosscheck_module_integrity_invalid")
    attested={}
    if attestations:
        if len(attestations)!=len(registry):
            raise ValueError("crosscheck_module_integrity_coverage_invalid")
        for item in attestations:
            if not isinstance(item,dict) or not isinstance(item.get("module"),str) or item["module"] in attested:
                raise ValueError("crosscheck_module_integrity_identity_invalid")
            attested[item["module"]]=item
        if set(attested)!={m.get("id") for m in registry}:
            raise ValueError("crosscheck_module_integrity_identity_invalid")
    verified=[]
    mismatch_modules=[]
    for n,(source,review,trace) in enumerate(zip(registry,records,xps_records),1):
        key=review["item_id"]
        if (not isinstance(source,dict) or source.get("id")!=key or trace.get("item_id")!=key
            or review.get("index")!=n or trace.get("index")!=n
            or trace.get("source_sha256")!=review.get("source_sha256")
            or trace.get("status")!=review.get("status")):
            raise ValueError("crosscheck_source_or_provenance_mismatch:"+key)
        observed=digest(source)
        matched=observed==review.get("source_sha256")
        att=attested.get(key)
        source_attested=False
        archive_changed=False
        if att is not None:
            if (att.get("archive_sha256_after_redaction")!=observed
                or att.get("archive_changed")!=(att.get("source_sha256_before_redaction")!=observed)):
                raise ValueError("crosscheck_archive_attestation_invalid:"+key)
            source_attested=att.get("source_sha256_before_redaction")==review.get("source_sha256")
            archive_changed=att["archive_changed"]
            if matched and not source_attested:
                raise ValueError("crosscheck_source_attestation_invalid:"+key)
        # A pre-redaction digest corroborates the historical Qwen source;
        # it cannot turn a modified archive into a bitwise verified archive.
        if not matched and not (key in redacted_modules and source_attested and archive_changed):
            mismatch_modules.append(key)
        verified.append({"module":key,"source_sha256":observed,
                         "source_provenance_attested":bool(matched or source_attested),
                         "source_attestation_matches_qwen":source_attested if att is not None else None,
                         "archive_changed":archive_changed if att is not None else None,
                         "original_source_sha256":review["source_sha256"],
                         "source_hash_verified":matched,
                         "unverified_reason":None if matched else ("privacy_redaction_in_archived_module" if key in redacted_modules else "unexplained_source_hash_mismatch"),
                         "source_status":source.get("status"),"qwen_status":review["status"],
                         "qwen_request_sha256":trace.get("qwen_request_sha256"),
                         "qwen_response_sha256":trace.get("qwen_response_sha256")})
    tonal=[m for m in registry if str(m["id"]).startswith("tonal.") and m.get("status")=="ok"]
    findings=[]
    tonal_details=[{"module":m["id"],"label":m.get("normalized",{}).get("label"),
                    "confidence":m.get("calibrated",{}).get("confidence"),
                    "calibration_method":m.get("calibrated",{}).get("method"),
                    "calibration_status":m.get("calibrated",{}).get("status")} for m in tonal]
    labels={m["label"] for m in tonal_details}
    if len(tonal_details)>1:
        findings.append({"code":"TONAL_LABEL_AGREEMENT" if len(labels)==1 else "TONAL_LABEL_DISAGREEMENT",
                         "modules":[m["module"] for m in tonal_details],
                         "evidence":tonal_details,
                         "verified_against_scientific_modules":True,
                         "ground_truth_validated":False})
        if len({m["calibration_method"] for m in tonal_details})>1:
            findings.append({"code":"TONAL_CONFIDENCE_HETEROGENEOUS","evidence":tonal_details,
                             "note":"Scores from heterogeneous calibration methods cannot be interpreted as directly comparable probabilities.",
                             "ground_truth_validated":False})
    if mismatch_modules:
        findings.append({"code":"SOURCE_HASH_MISMATCH_BLOCKING","modules":mismatch_modules,
                         "ground_truth_validated":False,"requires_source_investigation":True})
    return {**base,"schema":"ezstudio.profile-crosscheck.r1","scientific_crosscheck_performed":True,
            "integrity_complete":not mismatch_modules and all(item["source_hash_verified"] for item in verified),
            "blocking_source_mismatches":mismatch_modules,
            "crosschecked_module_total":sum(1 for item in verified if item["source_hash_verified"]),
            "unverified_module_total":sum(1 for item in verified if not item["source_hash_verified"]),
            "provenance_checks":verified,
            "scientific_findings":findings,"no_preset_mutation":True,
            "crosscheck_sha256":digest({"provenance":verified,"scientific_findings":findings})}
