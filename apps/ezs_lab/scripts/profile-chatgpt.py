"""Expertise PROFILE locale via le forfait ChatGPT autorisé."""
import json
import os
from pathlib import Path
import subprocess
import sys
import uuid

import ezs_chatgpt_oauth as oauth


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
                raise oauth.Failure("Flux ChatGPT interrompu avant la fin.")
    text = "\n".join(done[key] if key in done else "".join(deltas[key])
                     for key in sorted(done.keys() | deltas.keys()))
    if not text.strip():
        raise oauth.Failure("ChatGPT a terminé sans renvoyer de texte.")
    return text


from ezs_chatgpt_session import access_token


def stage(name):
    print(f"EZS_STAGE {name}", file=sys.stderr, flush=True)


def run(db, song, run_id):
    stage("export")
    export = subprocess.run(
        [sys.executable, "-X", "utf8", "-B", str(Path(__file__).with_name("export-profile-expertise.py")),
         "--database", db, "--song", song, "--run", run_id],
        capture_output=True, check=True, timeout=45,
    )
    header, row, _ = [json.loads(line) for line in export.stdout.splitlines()]
    if row.get("status") != "ready" or row.get("run_id") != int(run_id):
        raise oauth.Failure("Le run sélectionné est indisponible pour l’expertise.")
    titre = row["identity"]["title"]
    artiste = row["identity"]["artist"]
    template = header["prompt"]["content"]
    if "{titre}" not in template or "{artiste}" not in template:
        raise oauth.Failure("Le prompt actif doit contenir {titre} et {artiste}.")
    import re
    values = {"titre": titre, "artiste": artiste}
    prompt = re.sub(r"\{(titre|artiste)\}", lambda match: values[match[1]], template)
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
        handle.close()
        raise oauth.Failure("Une expertise ChatGPT est déjà en cours sur ce poste.")
    try:
        registration = json.loads(path.read_text(encoding="utf-8")) if path.exists() else {
            "host": "urn:uuid:" + str(uuid.uuid4()),
        }
        if not path.exists():
            oauth.save(path, registration)
        stage("oauth")
        token = access_token(path, registration)
        models = oauth.get_json(oauth.API + "/models", token=token)["models"]
        model = next((m["slug"] for m in models if m.get("display_name") == "GPT-6.2-Sol"
                      and m.get("visibility") == "list"), None)
        if model is None:
            raise oauth.Failure("GPT-6.2-Sol n’est pas disponible pour ce compte.")
        identity = row["identity"]
        stage("search")
        text = response(token, model, prompt)
        return {"run": int(run_id), "prompt_revision": header["prompt"]["id"], "jsonl": text}
    finally:
        handle.close()


if __name__ == "__main__":
    try:
        print(json.dumps(run(*sys.argv[1:]), ensure_ascii=False))
    except oauth.Failure as error:
        message = str(error)
        print(json.dumps({"error": message if message else "Expertise interrompue."}))
        sys.exit(1)
    except Exception:
        print(json.dumps({"error": "Export PROFILE ou connexion ChatGPT indisponible."}))
        sys.exit(1)
