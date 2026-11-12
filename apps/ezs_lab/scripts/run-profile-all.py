from __future__ import annotations

import argparse
import json
import sqlite3
import sys
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any


ACTIVE = {"queued", "running", "cancelling"}


def rows(
    conn: sqlite3.Connection,
    sql: str,
    params: tuple[Any, ...] = (),
) -> list[dict[str, Any]]:
    cur = conn.execute(sql, params)
    cols = [d[0] for d in cur.description]
    return [dict(zip(cols, row)) for row in cur.fetchall()]


def table_exists(conn: sqlite3.Connection, name: str) -> bool:
    return (
        conn.execute(
            "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?",
            (name,),
        ).fetchone()[0]
        > 0
    )


def load_current_profile_preset(repo: Path) -> dict[str, Any]:
    path = repo / "presets" / "profile" / "profile-r3b35c-compat.v2b.json"
    if not path.is_file():
        raise RuntimeError(f"profile_preset_missing:{path}")
    data = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        raise RuntimeError("profile_preset_invalid")
    return data


def tuning_revision(preset: dict[str, Any]) -> str | None:
    modules = preset.get("modules")
    if not isinstance(modules, list):
        return None

    for module in modules:
        if not isinstance(module, dict):
            continue
        if module.get("id") != "tuning.librosa-reference-pitch":
            continue

        for key in ("revision", "preset_revision"):
            value = module.get(key)
            if isinstance(value, str) and value.strip():
                return value.strip()

        settings = module.get("settings")
        if isinstance(settings, dict):
            value = settings.get("revision")
            if isinstance(value, str) and value.strip():
                return value.strip()

    return None


def source_available(conn: sqlite3.Connection, song_ids: list[int]) -> str | None:
    if not song_ids:
        return None

    marks = ",".join("?" for _ in song_ids)

    for row in rows(
        conn,
        f"""
        SELECT source_path
        FROM songs
        WHERE id IN ({marks})
        ORDER BY id DESC
        """,
        tuple(song_ids),
    ):
        value = str(row.get("source_path") or "").strip()
        if value and Path(value).is_file():
            return value

    if table_exists(conn, "benchmark_runs"):
        for row in rows(
            conn,
            f"""
            SELECT input_path
            FROM benchmark_runs
            WHERE song_id IN ({marks})
            ORDER BY id DESC
            """,
            tuple(song_ids),
        ):
            value = str(row.get("input_path") or "").strip()
            if value and Path(value).is_file():
                return value

    return None


def active_profile_jobs(
    conn: sqlite3.Connection,
    song_ids: list[int],
) -> list[dict[str, Any]]:
    if not song_ids or not table_exists(conn, "analysis_jobs"):
        return []

    marks = ",".join("?" for _ in song_ids)
    return rows(
        conn,
        f"""
        SELECT id,song_id,status,progress,scientific_run_id,updated_at
        FROM analysis_jobs
        WHERE song_id IN ({marks})
          AND kind='profile'
          AND status IN ('queued','running','cancelling')
        ORDER BY id
        """,
        tuple(song_ids),
    )


def grouped_songs(conn: sqlite3.Connection) -> list[dict[str, Any]]:
    songs = rows(
        conn,
        """
        SELECT id,title,artist,source_filename,audio_sha256,source_path
        FROM songs
        ORDER BY id DESC
        """,
    )

    groups: dict[str, dict[str, Any]] = {}
    for song in songs:
        song_id = int(song["id"])
        audio_hash = str(song.get("audio_sha256") or "").strip()
        key = audio_hash if audio_hash else f"song:{song_id}"

        if key not in groups:
            groups[key] = {
                "key": key,
                "song_id": song_id,
                "title": str(song.get("title") or ""),
                "artist": str(song.get("artist") or ""),
                "audio_sha256": audio_hash,
                "song_ids": [],
            }
        groups[key]["song_ids"].append(song_id)

    out = list(groups.values())
    out.sort(
        key=lambda x: (
            str(x["title"]).casefold(),
            str(x["artist"]).casefold(),
            int(x["song_id"]),
        )
    )
    return out


def post_profile(base_url: str, song_id: int, timeout: float) -> None:
    url = base_url.rstrip("/") + "/profile/analyze"
    payload = urllib.parse.urlencode({"song": str(song_id)}).encode("ascii")
    request = urllib.request.Request(
        url,
        data=payload,
        method="POST",
        headers={
            "Content-Type": "application/x-www-form-urlencoded",
            "User-Agent": "EZStudio-profile-run-all/1",
        },
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        status = int(getattr(response, "status", 200))
        if status < 200 or status >= 400:
            raise RuntimeError(f"http_status_{status}")


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Queue one PROFILE run for every catalog audio."
    )
    parser.add_argument(
        "--repo",
        default=str(Path(__file__).resolve().parents[1]),
        help="EZStudio_lab repository root.",
    )
    parser.add_argument(
        "--db",
        default=None,
        help="SQLite path. Defaults to <repo>/data/benchmark.sqlite.",
    )
    parser.add_argument(
        "--base-url",
        default="http://127.0.0.1:8090",
        help="Running EZStudio_lab HTTP server.",
    )
    parser.add_argument(
        "--execute",
        action="store_true",
        help="Actually enqueue jobs. Without this flag, only show the plan.",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=0,
        help="Maximum number of jobs to enqueue. 0 means no limit.",
    )
    parser.add_argument(
        "--expect-tuning-revision",
        default=None,
        help="Refuse execution unless the current tuning module revision matches.",
    )
    parser.add_argument(
        "--timeout",
        type=float,
        default=15.0,
        help="HTTP timeout in seconds.",
    )
    args = parser.parse_args()

    repo = Path(args.repo).resolve()
    db = Path(args.db).resolve() if args.db else repo / "data" / "benchmark.sqlite"
    if not db.is_file():
        raise RuntimeError(f"database_not_found:{db}")

    preset = load_current_profile_preset(repo)
    current_tuning_revision = tuning_revision(preset)

    print(f"profile_preset={repo / 'presets/profile/profile-r3b35c-compat.v2b.json'}")
    print(f"tuning_revision={current_tuning_revision or 'unknown'}")

    if args.expect_tuning_revision:
        if current_tuning_revision != args.expect_tuning_revision:
            raise RuntimeError(
                "unexpected_tuning_revision:"
                f"expected={args.expect_tuning_revision}:"
                f"actual={current_tuning_revision or 'unknown'}"
            )

    conn = sqlite3.connect(db)
    conn.row_factory = sqlite3.Row

    groups = grouped_songs(conn)
    planned: list[dict[str, Any]] = []
    skipped_active: list[dict[str, Any]] = []
    skipped_source: list[dict[str, Any]] = []

    for group in groups:
        song_ids = [int(x) for x in group["song_ids"]]
        active = active_profile_jobs(conn, song_ids)
        if active:
            skipped_active.append(
                {
                    **group,
                    "active_jobs": active,
                }
            )
            continue

        source = source_available(conn, song_ids)
        if source is None:
            skipped_source.append(group)
            continue

        planned.append({**group, "source": source})

    print(f"catalog_audio_groups={len(groups)}")
    print(f"planned={len(planned)}")
    print(f"skipped_active={len(skipped_active)}")
    print(f"skipped_missing_source={len(skipped_source)}")

    for item in planned:
        print(
            "PLAN "
            f"song={item['song_id']} "
            f"title={item['title']!r} "
            f"artist={item['artist']!r}"
        )

    for item in skipped_active:
        ids = ",".join(str(job["id"]) for job in item["active_jobs"])
        print(
            "SKIP_ACTIVE "
            f"song={item['song_id']} jobs={ids} "
            f"title={item['title']!r}"
        )

    for item in skipped_source:
        print(
            "SKIP_SOURCE "
            f"song={item['song_id']} "
            f"title={item['title']!r}"
        )

    if not args.execute:
        print("EZSTUDIO_PROFILE_RUN_ALL_DRY_RUN_OK")
        print("No job was created. Re-run with --execute to enqueue.")
        return 0

    selected = planned
    if args.limit > 0:
        selected = selected[: args.limit]

    queued = 0
    failed = 0

    for item in selected:
        try:
            post_profile(
                args.base_url,
                int(item["song_id"]),
                float(args.timeout),
            )
            queued += 1
            print(
                "QUEUED "
                f"{queued}/{len(selected)} "
                f"song={item['song_id']} "
                f"title={item['title']!r}"
            )
        except Exception as exc:
            failed += 1
            print(
                "FAILED "
                f"song={item['song_id']} "
                f"title={item['title']!r} "
                f"error={type(exc).__name__}:{exc}",
                file=sys.stderr,
            )

    print("EZSTUDIO_PROFILE_RUN_ALL_DONE")
    print(f"queued={queued}")
    print(f"failed={failed}")
    print(f"skipped_active={len(skipped_active)}")
    print(f"skipped_missing_source={len(skipped_source)}")

    return 0 if failed == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
