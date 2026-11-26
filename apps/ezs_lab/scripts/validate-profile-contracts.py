#!/usr/bin/env python3
"""Offline validation of E02 candidate contracts; no DB or runtime access."""
import argparse, json, math, pathlib
from jsonschema import Draft202012Validator

def load(path):
    def unique(pairs):
        out = {}
        for key, value in pairs:
            if key in out: raise ValueError("duplicate_key:" + key)
            out[key] = value
        return out
    def reject(value): raise ValueError("nonfinite:" + value)
    def finite_float(value):
        result = float(value)
        if not math.isfinite(result): raise ValueError("nonfinite:" + value)
        return result
    return json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=unique, parse_constant=reject, parse_float=finite_float)

def validate(schema, data):
    Draft202012Validator.check_schema(schema)
    Draft202012Validator(schema).validate(data)
    if data.get("schema") == "ezstudio.profile.result.v1":
        for ident in ("7", "8", "9"):
            value = data["items"][ident]["value"]
            if value is None: continue
            ids = [row["id"] for row in value["entries"]]
            if len(ids) != len(set(ids)): raise ValueError("duplicate_part_id:" + ident)
            for row in value["entries"]:
                for interval in row["intervals"] or []:
                    if interval["end_seconds"] <= interval["start_seconds"]: raise ValueError("invalid_interval:" + ident)

def main():
    p=argparse.ArgumentParser();p.add_argument("--result", type=pathlib.Path);p.add_argument("--documentary", type=pathlib.Path);a=p.parse_args()
    root=pathlib.Path(__file__).resolve().parents[1]
    validate(load(root/"schemas/profile-items.v1.schema.json"),load(root/"contracts/PROFILE_ITEMS.json"))
    validate(load(root/"schemas/profile-result.v1.schema.json"),load(a.result or root/"contracts/profile-result.v1.example.json"))
    validate(load(root/"schemas/profile-documentary.v1.schema.json"),load(a.documentary or root/"contracts/profile-documentary.v1.example.json"))
    print("PROFILE_CONTRACTS_E02_VALID")
if __name__ == "__main__": main()
