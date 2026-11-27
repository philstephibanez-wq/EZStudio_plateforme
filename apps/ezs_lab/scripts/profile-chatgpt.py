"""Expertise PROFILE locale via le forfait ChatGPT autorisé."""
import json
import os
from pathlib import Path
import subprocess
import sys
import uuid

import ezs_chatgpt_oauth as oauth


# EZS_PROFILE_FIFO_R2_DIAG — finite diagnostic vocabulary; never log raw payloads.
_EZS_STAGE = "unknown"
_EZS_DIAG_EMITTED = False
_EZS_REMOTE_CODES = frozenset(('empty_text', 'rate_limit_exceeded', 'insufficient_quota', 'usage_limit_reached', 'quota_exceeded', 'authentication_error', 'invalid_api_key', 'invalid_token', 'token_expired', 'permission_denied', 'access_denied', 'forbidden', 'model_not_found', 'server_error', 'internal_error', 'invalid_request_error', 'context_length_exceeded', 'content_filter', 'max_output_tokens', 'json_decode'))


def diagnostic(code):
    global _EZS_DIAG_EMITTED
    _EZS_DIAG_EMITTED = True
    print(f"EZS_DIAG {_EZS_STAGE} {code}", file=sys.stderr, flush=True)


# EZS_PROFILE_FIFO_R2_DIAG_B
# Local opt-in only: normal PHP worker diagnostics remain unchanged.
def _ezs_remote_detail(event):
    if os.environ.get("EZS_PROFILE_DIAG_LOCAL") != "1":
        return
    import re
    def clean(value):
        if not isinstance(value, str):
            return None
        value = re.sub(r"[\x00-\x1f\x7f]", " ", value)
        value = re.sub(r"(?i)\b(?:https?://|file://)\S+", "[URL]", value)
        value = re.sub(r"(?i)\b(?:bearer|basic)\s+\S+", "[AUTH]", value)
        value = re.sub(r"(?i)(?:access[_ -]?token|refresh[_ -]?token|api[_ -]?key|authorization|cookie|secret|password)\s*[:=]\s*[^\s,;]+", "[SECRET]", value)
        value = re.sub(r"[A-Za-z0-9_./+\-=]{24,}", "[REDACTED]", value)
        value = re.sub(r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}", "[EMAIL]", value)
        return value[:600]
    response_data = event.get("response")
    response_data = response_data if isinstance(response_data, dict) else {}
    for label, node in (("event.error", event.get("error")),
                        ("response.error", response_data.get("error")),
                        ("response.incomplete_details", response_data.get("incomplete_details")),
                        ("event", event)):
        if not isinstance(node, dict):
            continue
        detail = {k: clean(node[k]) for k in ("type", "code", "param", "reason", "message") if isinstance(node.get(k), str)}
        if detail:
            print("EZS_REMOTE_DETAIL " + json.dumps({"node": label, **detail}, ensure_ascii=True), file=sys.stderr, flush=True)


def event_diagnostic(event, fallback):
    _ezs_remote_detail(event)
    response_data = event.get("response")
    response_data = response_data if isinstance(response_data, dict) else {}
    for node in (event, event.get("error"), response_data.get("error"), response_data.get("incomplete_details")):
        if isinstance(node, dict):
            for field in ("code", "reason", "type"):
                value = node.get(field)
                if isinstance(value, str) and value in _EZS_REMOTE_CODES:
                    diagnostic(value)
                    return
    diagnostic(fallback)


def exception_diagnostic(error):
    if _EZS_DIAG_EMITTED:
        return
    import re
    import urllib.error
    seen = set()
    current = error
    while current is not None and id(current) not in seen:
        seen.add(id(current))
        if isinstance(current, urllib.error.HTTPError) and 400 <= current.code <= 599:
            diagnostic(f"http_{current.code}")
            return
        # Some transport adapters wrap HTTPError without retaining its type.
        match = re.search(r"\bHTTP(?: Error)?[ :]+([45][0-9]{2})\b", str(current))
        if match:
            diagnostic("http_" + match.group(1))
            return
        current = current.__cause__ or current.__context__
    if isinstance(error, json.JSONDecodeError):
        code = "json_decode"
    elif isinstance(error, (TimeoutError, subprocess.TimeoutExpired)):
        code = "timeout"
    elif isinstance(error, subprocess.CalledProcessError):
        code = "export_subprocess"
    elif isinstance(error, OSError):
        code = "os_error"
    elif isinstance(error, KeyError):
        code = "key_error"
    elif isinstance(error, ValueError):
        code = "value_error"
    elif isinstance(error, oauth.Failure):
        code = "oauth_failure"
    else:
        code = "unexpected"
    diagnostic(code)


def response(token, model, prompt):
    payload = {
        "model": model, "store": False, "stream": True,
        "tools": [{"type": "web_search"}],
        "input": [{"role": "user", "content": prompt}],
    }
    deltas = {}
    done = {}

    def collect(item, index):
        if item.get("type") != "message":
            return
        for position, part in enumerate(item.get("content", [])):
            if part.get("type") == "output_text" and isinstance(part.get("text"), str):
                if part["text"]:
                    done[index, position] = part["text"]

    def consume(event):
        kind = event["type"]
        if kind in {"error", "response.failed", "response.incomplete"}:
            event_diagnostic(event, {"error": "event_error", "response.failed": "event_failed", "response.incomplete": "event_incomplete"}[kind])
            raise oauth.Failure("Réponse ChatGPT interrompue.")
        key = (event.get("output_index", 0), event.get("content_index", 0))
        if kind == "response.output_text.delta":
            deltas.setdefault(key, []).append(event["delta"])
        elif kind == "response.output_text.done" and event.get("text"):
            done[key] = event["text"]
        elif kind == "response.output_item.done":
            collect(event["item"], key[0])
        elif kind == "response.completed":
            final = event["response"]
            if final.get("status") != "completed":
                event_diagnostic(event, "terminal_not_completed")
                raise oauth.Failure("Réponse ChatGPT interrompue.")
            # Prefer terminal text when present. Final snapshots may contain only tool results.
            for index, item in enumerate(final.get("output", [])):
                collect(item, index)
            return True
        return False

    with oauth.request(oauth.API + "/responses", payload, token=token) as stream:
        pending = []
        for line in stream:
            line = line.rstrip(b"\r\n")
            if line.startswith(b"data:"):
                pending.append(line[5:].lstrip(b" "))
                continue
            if line or not pending:
                continue
            data = b"\n".join(pending)
            pending.clear()
            if data != b"[DONE]" and consume(json.loads(data)):
                break
        else:
            if not pending or pending == [b"[DONE]"] or not consume(json.loads(b"\n".join(pending))):
                diagnostic("stream_incomplete")
                raise oauth.Failure("Flux ChatGPT interrompu avant la fin.")
    text = "\n".join(done[key] if key in done else "".join(deltas[key])
                     for key in sorted(done.keys() | deltas.keys()))
    if not text.strip():
        diagnostic("empty_text")
        raise oauth.Failure("ChatGPT a terminé sans renvoyer de texte.")
    return text


from ezs_chatgpt_session import access_token


def stage(name):
    global _EZS_STAGE
    _EZS_STAGE = name
    print(f"EZS_STAGE {name}", file=sys.stderr, flush=True)


def run(db, song, run_id, revision=None):
    stage("export")
    revision_args = ["--revision", revision] if revision is not None else []
    export = subprocess.run(
        [sys.executable, "-X", "utf8", "-B", str(Path(__file__).with_name("export-profile-expertise.py")),
         "--database", db, "--song", song, "--run", run_id, *revision_args],
        capture_output=True, check=True, timeout=45,
    )
    header, row, _ = [json.loads(line) for line in export.stdout.splitlines()]
    if row.get("status") != "ready" or row.get("run_id") != int(run_id):
        diagnostic("run_unavailable")
        raise oauth.Failure("Le run sélectionné est indisponible pour l’expertise.")
    if revision is not None and int(revision) != header["prompt"]["id"]:
        diagnostic("revision_mismatch")
        raise oauth.Failure("La révision du prompt a changé depuis l’admission.")
    titre = row["identity"]["title"]
    artiste = row["identity"]["artist"]
    template = header["prompt"]["content"]
    if "{titre}" not in template or "{artiste}" not in template:
        diagnostic("template_invalid")
        raise oauth.Failure("Le prompt actif doit contenir {titre} et {artiste}.")
    import re
    values = {"titre": titre, "artiste": artiste}
    prompt = re.sub(r"\{(titre|artiste)\}", lambda match: values[match[1]], template)
    stage("session_lock")
    directory = Path(os.environ["LOCALAPPDATA"]) / "EZStudioOAuthPOC"
    directory.mkdir(exist_ok=True)
    path = directory / "registration.json"
    lock = directory / "profile-session.lock"
    import msvcrt
    handle = lock.open("a+b")
    try:
        handle.seek(0)
        msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
    except OSError:
        diagnostic("lock_busy")
        handle.close()
        raise oauth.Failure("Une expertise ChatGPT est déjà en cours sur ce poste.")
    try:
        stage("registration")
        registration = json.loads(path.read_text(encoding="utf-8")) if path.exists() else {
            "host": "urn:uuid:" + str(uuid.uuid4()),
        }
        if not path.exists():
            oauth.save(path, registration)
        stage("oauth")
        token = access_token(path, registration)
        stage("models")
        models = oauth.get_json(oauth.API + "/models", token=token)["models"]
        stage("model_select")
        model = next((m["slug"] for m in models if m.get("display_name") == "GPT-6.2-Sol"
                      and m.get("visibility") == "list"), None)
        if model is None:
            diagnostic("model_unavailable")
            raise oauth.Failure("GPT-6.2-Sol n’est pas disponible pour ce compte.")
        identity = row["identity"]
        stage("search")
        text = response(token, model, prompt)
        stage("result")
        return {"run": int(run_id), "prompt_revision": header["prompt"]["id"], "jsonl": text}
    finally:
        handle.close()


if __name__ == "__main__":
    try:
        print(json.dumps(run(*sys.argv[1:]), ensure_ascii=False))
    except oauth.Failure as error:
        exception_diagnostic(error)
        message = str(error)
        print(json.dumps({"error": message if message else "Expertise interrompue."}))
        sys.exit(1)
    except Exception as error:
        exception_diagnostic(error)
        print(json.dumps({"error": "Export PROFILE ou connexion ChatGPT indisponible."}))
        sys.exit(1)
