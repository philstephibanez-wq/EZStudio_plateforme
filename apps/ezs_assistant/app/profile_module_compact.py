"""Bounded consultative projection of scientific modules; original data remains in LAB."""
import json
import math

def _project(value, samples, depth=0):
    if depth >= 12:
        return {"truncated_depth": True, "type": type(value).__name__}
    if isinstance(value, dict):
        priority=("id","status","family","name","summary","normalized","consensus","confidence","prediction","predictions","scores","top","labels","parameters","thresholds","timing","weights")
        ordered=sorted(value,key=lambda k: (0,priority.index(str(k))) if str(k) in priority else (1,str(k)))
        take=min(len(ordered),max(1,samples*3))
        selected=ordered[:take]
        result={str(k):_project(value[k],samples,depth+1) for k in selected}
        if len(ordered)>take:
            result["_projection_coverage"]={"kind":"dictionary_subset","original_keys":len(ordered),"retained_keys":take,"omitted_keys":len(ordered)-take}
        return result
    if isinstance(value, list):
        if len(value) <= samples:
            return [_project(v,samples,depth+1) for v in value]
        if value and all(type(v) in (int,float) and math.isfinite(v) for v in value):
            return {"kind":"numeric_vector_summary","count":len(value),
                "min":min(value),"max":max(value),"mean":sum(value)/len(value),
                "sample_first":[round(v,6) for v in value[:samples]],
                "omitted":len(value)-samples}
        return {"kind":"sampled_list","count":len(value),
                "sample_first":[_project(v,samples,depth+1) for v in value[:samples]],
                "omitted":len(value)-samples}
    if isinstance(value,str) and len(value)>400:
        return {"kind":"long_text_excerpt","length":len(value),"first":value[:min(200,samples*50)],
                "omitted_characters":len(value)-min(200,samples*50)}
    return value

def module_prompt_payload(request, bound, prompt):
    """No silent clipping: explicitly document sampling; fail closed when still oversized."""
    original=request["module"]
    for samples in (8,4,2,1,0):
        transformed={**request,"module":_project(original,samples),
           "evidence_scope":{"full_scientific_source_in_LAB":True,
              "source_sha256":request["source_sha256"],
              "sample_limit_per_list":samples,
              "consultation_is_on_projection":True,
              "do_not_infer_from_omitted_values":True}}
        body=json.dumps(transformed,ensure_ascii=False,separators=(",",":"),allow_nan=False)
        size=max(len(prompt)+len(body),len(prompt.encode())+len(body.encode()))
        if size <= bound: return body,{"input_chars":size,"sample_limit":samples,
                                      "source_is_projected":True}
    raise ValueError("module_context_over_budget_even_after_projection")
