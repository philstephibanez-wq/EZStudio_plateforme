"""Compare independently served models with the same fixed documentary benchmark.

Does not start, download, copy, or replace models. The operator must start
candidate on a separate local port. Default production port is never modified.
"""
from __future__ import annotations
import argparse
import json
import time
import urllib.request
from profile_inference_live_benchmark import CASES
from profile_inference_strict_benchmark import PROMPT,SCHEMA,strict_validate
from profile_inference_qualification import score_case

def endpoint_port(endpoint):
    from urllib.parse import urlsplit
    u=urlsplit(endpoint)
    if u.scheme!="http" or u.hostname not in ("127.0.0.1","localhost") or u.username or u.password or u.query or u.fragment or u.path not in ("","/"):
        raise ValueError("loopback_endpoint_required")
    if not u.port or not 1024<=u.port<=65535:raise ValueError("invalid_port")
    return u.port

def infer(endpoint,model,documents,timeout=120):
    endpoint_port(endpoint)
    body={"model":model,"temperature":0,"max_tokens":1600,"stream":False,
      "messages":[{"role":"system","content":PROMPT},{"role":"user","content":json.dumps({"documents":documents},ensure_ascii=False)}],
      "response_format":{"type":"json_schema","schema":SCHEMA}}
    req=urllib.request.Request(endpoint.rstrip("/")+"/v1/chat/completions",
      data=json.dumps(body,ensure_ascii=False).encode("utf-8"),
      headers={"Content-Type":"application/json; charset=utf-8"},method="POST")
    start=time.monotonic()
    with urllib.request.urlopen(req,timeout=timeout) as response:
        payload=json.loads(response.read(2_000_000).decode("utf-8"))
    choice=payload["choices"][0]
    if choice.get("finish_reason")=="length":raise RuntimeError("truncated_completion")
    return json.loads(choice["message"]["content"]),round(time.monotonic()-start,3)

def compare(configurations,infer_fn=infer):
    results=[]
    for label,endpoint,model in configurations:
        endpoint_port(endpoint)
        cases=[]
        for case in CASES:
            try:
                data,seconds=infer_fn(endpoint,model,case["documents"])
                checked=strict_validate(data,case["documents"])
                cases.append({"case":case["id"],"seconds":seconds,
                    **score_case(checked,case["expected"]),
                    "accepted":checked["accepted"],"rejected":checked["rejected"]})
            except Exception as exc:
                cases.append({"case":case["id"],"error":type(exc).__name__+":"+str(exc)[:140]})
        valid=[x for x in cases if "error" not in x]
        tp=sum(x["true_positives"] for x in valid)
        fp=sum(x["false_positives"] for x in valid)
        expected_total=sum(len(c["expected"]) for c in CASES)
        results.append({"label":label,"endpoint":endpoint,"model_argument":model,
            "cases":cases,"completed":len(valid),
            "precision_micro":tp/max(1,tp+fp),
            "recall_micro":tp/expected_total,
            "total_seconds":round(sum(x["seconds"] for x in valid),3),
            "valid_comparison":len(valid)==len(CASES)})
    return {"benchmark":"synthetic_text_v110_same_prompt_and_schema",
        "production_modified":False,"comparisons":results}

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--candidate-endpoint",required=True,help="Second already running local endpoint; do not use port 8720")
    p.add_argument("--candidate-model",default="local")
    p.add_argument("--baseline-endpoint",default="http://127.0.0.1:8720")
    p.add_argument("--baseline-model",default="local")
    args=p.parse_args()
    if endpoint_port(args.candidate_endpoint)==endpoint_port(args.baseline_endpoint):
        p.error("candidate_requires_separate_port")
    result=compare([("baseline",args.baseline_endpoint,args.baseline_model),
                    ("candidate",args.candidate_endpoint,args.candidate_model)])
    print(json.dumps(result,ensure_ascii=False,indent=2))
if __name__=="__main__":main()
