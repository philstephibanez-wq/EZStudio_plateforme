"""Persistent, non-authoritative record of Assistant PROFILE documentary conclusions.

This module does not change LAB verdicts, presets or scientific history.  It
only records source-backed conclusions after LAB has acknowledged a revision.
"""
from __future__ import annotations

from contextlib import closing
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import sqlite3
from urllib.parse import urlsplit

SCHEMA_MARKER = 'ezs-assistant-expertise.v1'
VALID_VERDICTS = frozenset({'bon', 'faux'})
HEX64 = re.compile(r'^[0-9a-fA-F]{64}$')


def database_path() -> Path:
    # app/ -> ezs_assistant/ -> apps/ -> EZStudio_plateforme/
    return Path(__file__).resolve().parents[3] / 'data' / 'databases' / 'EZS_Assistant.sqlite'


def _utc() -> str:
    return datetime.now(timezone.utc).isoformat(timespec='seconds')


def _canonical_song_key(title: str, artist: str) -> str:
    # Exact same key contract as expertise_store.py R1; do not fork identities.
    import unicodedata
    pair = (unicodedata.normalize('NFKC', artist.strip()).casefold() + '\0' +
            unicodedata.normalize('NFKC', title.strip()).casefold())
    return hashlib.sha256(pair.encode('utf-8')).hexdigest()


def _text(value: object, maximum: int = 500) -> str:
    return str(value).strip()[:maximum] if isinstance(value, str) else ''


def _source_url(source: dict) -> str | None:
    url = _text(source.get('url'), 2048)
    try:
        parsed = urlsplit(url)
        if parsed.scheme != 'https' or not parsed.hostname or parsed.username or parsed.password:
            return None
        host = parsed.hostname.lower()
        if host in {'localhost', '127.0.0.1', '::1'} or host.endswith('.local'):
            return None
        return url
    except ValueError:
        return None


def _validated(conn: sqlite3.Connection) -> None:
    if conn.execute('PRAGMA user_version').fetchone()[0] != 1:
        raise RuntimeError('EXPERTISE_DB_SCHEMA_VERSION_INVALID')
    result = conn.execute("SELECT value FROM store_metadata WHERE key='schema'").fetchone()
    if not result or result[0] != SCHEMA_MARKER:
        raise RuntimeError('EXPERTISE_DB_OWNER_INVALID')
    if conn.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
        raise RuntimeError('EXPERTISE_DB_INTEGRITY_FAILED')
    if conn.execute('PRAGMA foreign_key_check').fetchone():
        raise RuntimeError('EXPERTISE_DB_FOREIGN_KEY_FAILURE')


def _audio_sha256(identity: dict, row: dict) -> str | None:
    # Only an explicit full-file SHA256 is accepted. No title-based inference.
    for key in ('audio_sha256', 'source_audio_sha256'):
        value = row.get(key)
        if isinstance(value, str) and HEX64.fullmatch(value):
            return value.lower()
        value = identity.get(key)
        if isinstance(value, str) and HEX64.fullmatch(value):
            return value.lower()
    return None


def find_reference_for_audio(audio_sha256: str, item_key: str, *, db: Path | None = None) -> dict | None:
    """Return only explicit strongly expertised facts bound to this exact audio hash.

    Never use the title alone to skip web research or to award BON/FAUX.
    """
    if not isinstance(audio_sha256, str) or not HEX64.fullmatch(audio_sha256):
        return None
    if not isinstance(item_key, str) or not item_key.strip():
        return None
    path = Path(db) if db is not None else database_path()
    if not path.is_file():
        return None
    with closing(sqlite3.connect(path.resolve().as_uri() + '?mode=ro', uri=True, timeout=5)) as conn:
        _validated(conn)
        row = conn.execute('''
            SELECT e.id, e.reference_value, e.state, e.scope
              FROM expertise_revisions e
              JOIN audio_versions a ON a.id=e.audio_version_id
             WHERE a.audio_sha256=? AND e.item_key=?
               AND e.state IN ('STRONG','AUTHOR_VALIDATED')
               AND NOT EXISTS (SELECT 1 FROM expertise_revisions newer
                               WHERE newer.supersedes_revision_id=e.id)
             ORDER BY e.id DESC LIMIT 1
        ''', (audio_sha256.lower(), item_key.strip())).fetchone()
        if row is None:
            return None
        return {'revision_id': row[0], 'reference_value': row[1], 'state': row[2], 'scope': row[3]}


def record_verified_assertions(run_id: int, lab_revision_id: int,
                               identity: dict, row: dict, verification: dict,
                               *, db: Path | None = None) -> dict:
    """Append source-backed provisional facts, idempotently per LAB revision.

    Deliberately no STRONG claim: one Assistant verifier result cannot prove
    the independence of the sources or the exact audio recording.
    """
    if type(run_id) is not int or run_id <= 0 or type(lab_revision_id) is not int or lab_revision_id <= 0:
        raise ValueError('INVALID_LAB_RUN_OR_REVISION')
    path = Path(db) if db is not None else database_path()
    if not path.is_file():
        raise RuntimeError('EXPERTISE_DB_MISSING_NO_AUTO_CREATE')
    title = _text(identity.get('title'), 500)
    artist = _text(identity.get('artist'), 500)
    if not title or not artist:
        return {'status': 'IDENTITY_INCOMPLETE', 'inserted': 0, 'skipped': 0, 'reused': 0}
    assertions = verification.get('assertions') if isinstance(verification, dict) else None
    if not isinstance(assertions, list):
        raise ValueError('FINAL_VERIFICATION_ASSERTIONS_INVALID')
    audio = _audio_sha256(identity, row)
    inserted = reused = skipped = 0
    with closing(sqlite3.connect(path.resolve().as_uri() + '?mode=rw', uri=True, timeout=10)) as conn:
        conn.execute('PRAGMA foreign_keys=ON')
        _validated(conn)
        with conn:
            key = _canonical_song_key(title, artist)
            conn.execute('INSERT OR IGNORE INTO songs(canonical_key,title,artist,created_at) VALUES (?,?,?,?)',
                         (key, title, artist, _utc()))
            song_id = conn.execute('SELECT id FROM songs WHERE canonical_key=?', (key,)).fetchone()[0]
            audio_id = None
            if audio:
                existing = conn.execute('SELECT id,song_id FROM audio_versions WHERE audio_sha256=?', (audio,)).fetchone()
                if existing and existing[1] != song_id:
                    raise ValueError('AUDIO_HASH_ALREADY_LINKED_TO_DIFFERENT_SONG')
                if not existing:
                    conn.execute('INSERT INTO audio_versions(song_id,audio_sha256,recording_label,created_at) VALUES (?,?,?,?)',
                                 (song_id, audio, 'explicit_full_file_sha256', _utc()))
                audio_id = conn.execute('SELECT id FROM audio_versions WHERE audio_sha256=?', (audio,)).fetchone()[0]
            for item in assertions:
                if not isinstance(item, dict) or item.get('verdict') not in VALID_VERDICTS:
                    skipped += 1
                    continue
                item_key = _text(item.get('key'), 120)
                ref = _text(item.get('reference_value'), 500)
                predicted = _text(item.get('value'), 500)
                if not item_key or not ref or not predicted:
                    skipped += 1
                    continue
                source_rows = []
                seen = set()
                for source in item.get('sources') or []:
                    if not isinstance(source, dict):
                        continue
                    url = _source_url(source)
                    if not url or url in seen:
                        continue
                    seen.add(url)
                    source_rows.append((url, _text(source.get('title'), 255) or urlsplit(url).hostname,
                                        urlsplit(url).hostname.lower(),
                                        _text(source.get('evidence_type'), 100) or 'documentary',
                                        ref, 'UNKNOWN', _utc()))
                if not source_rows:
                    skipped += 1
                    continue
                # Prefix is independent of variable documentary prose; replays of
                # the same LAB revision are idempotent, never silently overwritten.
                prefix = f'LAB_RUN={run_id};LAB_REV={lab_revision_id};ITEM={item_key};'
                existing = conn.execute('''
                    SELECT id, reference_value FROM expertise_revisions
                     WHERE song_id=? AND item_key=? AND rationale LIKE ?
                     ORDER BY id DESC LIMIT 1
                ''', (song_id, item_key, prefix + '%')).fetchone()
                if existing:
                    if existing[1] != ref:
                        raise RuntimeError('EXPERTISE_REPLAY_CONFLICT')
                    reused += 1
                    continue
                reason = _text(item.get('reason'), 1000)
                rationale = prefix + json.dumps({'verdict': item['verdict'], 'prediction': predicted,
                                                  'reason': reason}, ensure_ascii=False, sort_keys=True)
                result = conn.execute('''
                    INSERT INTO expertise_revisions
                      (song_id,audio_version_id,item_key,reference_value,state,scope,rationale,author,supersedes_revision_id,created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ''', (song_id, audio_id, item_key, ref, 'PROVISIONAL',
                      'audio_sha256_supplied_unverified' if audio else 'recording_not_verified',
                      rationale, 'assistant', None, _utc()))
                rid = result.lastrowid
                conn.executemany('''
                    INSERT INTO expertise_sources
                     (revision_id,url,publisher,independence_group,source_type,observed_value,recording_match,consulted_at)
                    VALUES (?,?,?,?,?,?,?,?)
                ''', [(rid, *sr) for sr in source_rows])
                # Do not fabricate scientific preset or preset revision values.
                inserted += 1
    return {'status': 'RECORDED_PROVISIONAL', 'inserted': inserted, 'reused': reused,
            'skipped': skipped, 'audio_sha256_present': audio is not None}
