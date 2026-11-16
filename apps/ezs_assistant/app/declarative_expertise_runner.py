import hashlib, json, os, sqlite3, time
from pathlib import Path

ROOT = Path(os.environ.get("EZS_ROOT", Path(__file__).resolve().parents[3]))
LAB_PRESET_DIR = ROOT / "apps" / "ezs_lab" / "presets" / "assistant"
ASSISTANT_PRESET_DIR = ROOT / "apps" / "ezs_assistant" / "presets" / "assistant"
DB_PATH = Path(os.environ.get("EZS_ASSISTANT_DB", ROOT / "apps" / "ezs_assistant" / "data" / "assistant.sqlite"))
FORBIDDEN_KEYS = {"audio_path", "waveform", "raw_audio", "model_weights", "engine_override"}
CANONICAL_EXECUTION_PATH = "v1"


def canonical_json(data: dict) -> str:
    return json.dumps(data, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def sha256_bytes(raw: bytes) -> str:
    return hashlib.sha256(raw).hexdigest()


def ensure_tables(con: sqlite3.Connection) -> None:
    con.execute("CREATE TABLE IF NOT EXISTS assistant_presets (id TEXT PRIMARY KEY, phase TEXT NOT NULL, version TEXT NOT NULL, schema_version TEXT NOT NULL, content_json TEXT NOT NULL, sha256 TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, updated_at INTEGER NOT NULL)")
    con.execute("CREATE TABLE IF NOT EXISTS assistant_preset_baselines (phase TEXT PRIMARY KEY, preset_id TEXT NOT NULL, updated_at INTEGER NOT NULL)")
    con.execute("CREATE TABLE IF NOT EXISTS assistant_run_preset (run_id INTEGER PRIMARY KEY, preset_id TEXT NOT NULL, preset_sha256 TEXT NOT NULL, executed_at INTEGER NOT NULL)")
    con.execute("CREATE TABLE IF NOT EXISTS assistant_run_preset_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER NOT NULL, preset_id TEXT NOT NULL, preset_sha256 TEXT NOT NULL, executed_at INTEGER NOT NULL)")


def load_preset_file(path: Path) -> tuple[dict, str]:
    raw = path.read_bytes()
    if raw.startswith(b"\xef\xbb\xbf"):
        raise ValueError(f"utf8_bom_forbidden:{path}")
    data = json.loads(raw.decode("utf-8"))
    if not isinstance(data, dict):
        raise ValueError("preset_not_object")
    bad = FORBIDDEN_KEYS.intersection(data.keys())
    if bad:
        raise ValueError("preset_forbidden_keys:" + ",".join(sorted(bad)))
    if not data.get("id") or not data.get("phase") or not data.get("schema"):
        raise ValueError("preset_missing_required_fields")
    return data, sha256_bytes(canonical_json(data).encode("utf-8"))


def sync_presets(db_path: Path = DB_PATH, preset_dir: Path = LAB_PRESET_DIR) -> list[dict]:
    db_path.parent.mkdir(parents=True, exist_ok=True)
    synced = []
    with sqlite3.connect(str(db_path)) as con:
        ensure_tables(con)
        now = int(time.time())
        for path in sorted(preset_dir.glob("*.json")):
            if "v2" in path.name.lower():
                continue
            data, digest = load_preset_file(path)
            pid = str(data["id"])
            phase = str(data["phase"])
            ver = str(data.get("version") or "1")
            schema = str(data["schema"])
            cjson = canonical_json(data)
            con.execute(
                "INSERT INTO assistant_presets(id,phase,version,schema_version,content_json,sha256,active,updated_at) VALUES (?,?,?,?,?,?,1,?) "
                "ON CONFLICT(id) DO UPDATE SET phase=excluded.phase, version=excluded.version, schema_version=excluded.schema_version, content_json=excluded.content_json, sha256=excluded.sha256, updated_at=excluded.updated_at",
                (pid, phase, ver, schema, cjson, digest, now),
            )
            # Immutabilité : la baseline n'est initialisée que si absente ; jamais écrasée automatiquement
            if "baseline" in pid:
                con.execute(
                    "INSERT INTO assistant_preset_baselines(phase,preset_id,updated_at) VALUES (?,?,?) ON CONFLICT(phase) DO NOTHING",
                    (phase, pid, now),
                )
            synced.append({"id": pid, "phase": phase, "sha256": digest, "execution_path": CANONICAL_EXECUTION_PATH})
        con.commit()
    return synced


def set_explicit_baseline(phase: str, preset_id: str, db_path: Path = DB_PATH) -> None:
    """Action explicite et volontaire de changement de baseline (jamais appelée lors d'un run)."""
    with sqlite3.connect(str(db_path)) as con:
        ensure_tables(con)
        row = con.execute("SELECT id FROM assistant_presets WHERE id=? AND phase=?", (preset_id, phase)).fetchone()
        if not row:
            raise ValueError(f"baseline_preset_not_found:{phase}:{preset_id}")
        now = int(time.time())
        con.execute(
            "INSERT INTO assistant_preset_baselines(phase,preset_id,updated_at) VALUES (?,?,?) "
            "ON CONFLICT(phase) DO NOTHING",
            (phase, preset_id, now),
        )
        con.commit()


def record_run_preset(run_id: int, preset_id: str, preset_sha256: str, db_path: Path = DB_PATH) -> None:
    """Enregistre la provenance initiale de façon immuable et trace toutes les tentatives."""
    with sqlite3.connect(str(db_path)) as con:
        ensure_tables(con)
        now = int(time.time())
        con.execute(
            "INSERT INTO assistant_run_preset(run_id,preset_id,preset_sha256,executed_at) VALUES (?,?,?,?) ON CONFLICT(run_id) DO NOTHING",
            (int(run_id), preset_id, preset_sha256, now),
        )
        con.execute(
            "INSERT INTO assistant_run_preset_attempts(run_id,preset_id,preset_sha256,executed_at) VALUES (?,?,?,?)",
            (int(run_id), preset_id, preset_sha256, now),
        )
        con.commit()