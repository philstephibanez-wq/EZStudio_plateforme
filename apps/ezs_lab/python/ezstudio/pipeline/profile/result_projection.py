"""Read-only assembly of the E02 PROFILE contract from a single LAB run.
Historical runs without an E04 projection are explicitly not analyzed in this contract.
No scientific backfill, database writes, or inference is performed here.
"""
from __future__ import annotations
import copy
import json
import math
import sqlite3
from pathlib import Path
from jsonschema import Draft202012Validator


def strict_json(raw):
    def pairs(items):
        result = {}
        for key, value in items:
            if key in result:
                raise ValueError("duplicate_json_key:" + key)
            result[key] = value
        return result
    def reject(value):
        raise ValueError("nonfinite_json:" + value)
    def number(value):
        result = float(value)
        if not math.isfinite(result):
            raise ValueError("nonfinite_json:" + value)
        return result
    return json.loads(raw, object_pairs_hook=pairs, parse_constant=reject, parse_float=number)


def item(state="not_analyzed", value=None, provenance=None):
    return {"state": state, "value": value,
            "confidence": {"kind": "not_calculated", "value": None},
            "provenance": copy.deepcopy(provenance or [])}


def assemble(row, schema_dir):
    schema_dir = Path(schema_dir)
    def validate(name, value):
        schema = strict_json((schema_dir / name).read_text(encoding="utf8"))
        Draft202012Validator(schema).validate(value)
    if row["item"] != "profile" or row["state"] != "done":
        raise ValueError("profile_completed_run_required")
    document = None
    if row["profile_documentary_json"] is not None:
        document = strict_json(row["profile_documentary_json"])
        validate("profile-documentary.v1.schema.json", document)
    items = {i: item() for i in ("1", "1a", "1b", "1c", *"23456789")}
    version = None
    identity_source = [{"kind": "catalogue", "ref": f"songs:{row['song_id']}"}]
    if document and document["recording_version"]["state"] == "known":
        version = document["recording_version"]["value"]
        for ref in document["recording_version"]["provenance"]:
            if ref not in identity_source:
                identity_source.append(ref)
    title, artist = row["title"], row["artist"]
    if isinstance(title, str) and title.strip():
        items["1"] = item("known", {"title": title, "artists": [artist] if isinstance(artist, str) and artist.strip() else [], "recording_version": version}, identity_source)
    else:
        items["1"] = item("unknown")
    for ident, field in (("1a", "lyricists"), ("1b", "composers"), ("1c", "arrangers")):
        if document:
            d = document[field]
            items[ident] = item(d["state"], d["value"], d["provenance"])
        else:
            items[ident] = item("unknown")
    diagnostics = strict_json(row["diagnostics_json"])
    if not isinstance(diagnostics, dict):
        raise ValueError("profile_diagnostics_invalid")
    view = diagnostics.get("profile_view", {})
    if not isinstance(view, dict):
        raise ValueError("profile_view_invalid")
    if "scientific_items" in view:
        projection = view["scientific_items"]
        if not isinstance(projection, dict) or projection.get("schema") != "ezstudio.profile.scientific-items.v1":
            raise ValueError("profile_scientific_projection_invalid")
        scientific = projection.get("items")
        if not isinstance(scientific, dict) or set(scientific) != set("23456789"):
            raise ValueError("profile_scientific_item_set_invalid")
        items.update(copy.deepcopy(scientific))
    result = {"schema": "ezstudio.profile.result.v1", "run_id": row["run_id"], "song_id": row["song_id"], "audio_sha256": row["audio_sha256"], "items": items}
    validate("profile-result.v1.schema.json", result)
    for ident in "789":
        value = items[ident]["value"]
        if value is not None:
            ids = [r["id"] for r in value["entries"]]
            if len(ids) != len(set(ids)):
                raise ValueError("duplicate_part_id:" + ident)
            for r in value["entries"]:
                for interval in r["intervals"] or []:
                    if interval["end_seconds"] <= interval["start_seconds"]:
                        raise ValueError("invalid_interval:" + ident)
    return result


def read_result(database, run_id, schema_dir):
    if type(run_id) is not int or run_id < 1:
        raise ValueError("invalid_run_id")
    database = Path(database).resolve(strict=True)
    if not database.is_file():
        raise ValueError("database_not_file")
    conn = sqlite3.connect(database.as_uri() + "?mode=ro", uri=True)
    try:
        conn.execute("PRAGMA query_only=ON")
        conn.row_factory = sqlite3.Row
        row = conn.execute("""SELECT sr.id AS run_id, sr.song_id, sr.item, sr.state,
                    sr.diagnostics_json, s.title, s.artist, s.audio_sha256,
                    s.profile_documentary_json
               FROM scientific_runs sr JOIN songs s ON s.id=sr.song_id
              WHERE sr.id=?""", (run_id,)).fetchone()
        if row is None:
            raise ValueError("profile_run_missing")
        return assemble(row, schema_dir)
    finally:
        conn.close()
