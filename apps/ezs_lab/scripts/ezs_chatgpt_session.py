"""Persistent ChatGPT authorization for the local PROFILE worker on Windows."""
import base64
import json
import os
from pathlib import Path
import subprocess
import time
import urllib.error

import ezs_chatgpt_oauth as oauth


def protect(data, operation):
    script = (
        "$ErrorActionPreference = 'Stop'; Add-Type -AssemblyName System.Security;"
        "$bytes = [Convert]::FromBase64String([Console]::In.ReadToEnd());"
        f"$result = [Security.Cryptography.ProtectedData]::{operation}"
        "($bytes, $null, [Security.Cryptography.DataProtectionScope]::CurrentUser);"
        "[Console]::Write([Convert]::ToBase64String($result))"
    )
    powershell = (Path(os.environ["SystemRoot"]) / "System32" /
                  "WindowsPowerShell" / "v1.0" / "powershell.exe")
    try:
        result = subprocess.run(
            [str(powershell), "-NoProfile", "-NonInteractive", "-Command", script],
            input=base64.b64encode(data), capture_output=True, check=True,
            timeout=15, creationflags=subprocess.CREATE_NO_WINDOW,
        )
        return base64.b64decode(result.stdout, validate=True)
    except (OSError, subprocess.SubprocessError, ValueError):
        raise oauth.Failure("Protection Windows de la session ChatGPT indisponible.") from None


def save_session(path, registration, tokens, issued_at):
    if (tokens.get("token_type", "").lower() != "bearer"
            or not tokens.get("access_token") or not tokens.get("refresh_token")
            or not isinstance(tokens.get("expires_in"), int) or tokens["expires_in"] <= 0):
        raise oauth.Failure("Réponse OAuth ChatGPT incomplète.")
    record = {
        "registration": registration,
        "access_token": tokens["access_token"],
        "refresh_token": tokens["refresh_token"],
        "expires_at": issued_at + tokens["expires_in"],
    }
    encrypted = protect(json.dumps(record).encode("utf-8"), "Protect")
    temporary = path.with_suffix(".tmp")
    try:
        with temporary.open("wb") as handle:
            handle.write(encrypted)
            handle.flush()
            os.fsync(handle.fileno())
        temporary.replace(path)
    finally:
        temporary.unlink(missing_ok=True)
    return record["access_token"]


def access_token(registration_path, registration):
    path = registration_path.with_name("profile-session.dpapi")
    if path.exists():
        record = json.loads(protect(path.read_bytes(), "Unprotect"))
        if record["registration"] != registration:
            raise oauth.Failure("La session ChatGPT correspond à une autre inscription LAB.")
        if time.time() < record["expires_at"] - 30:
            return record["access_token"]
        issued_at = time.time()
        try:
            tokens = oauth.get_json(
                oauth.AUTH + "/api/accounts/oauth/token",
                {"grant_type": "refresh_token", "client_id": registration["client_id"],
                 "refresh_token": record["refresh_token"], "resource": oauth.API},
                form=True,
            )
        except urllib.error.HTTPError as error:
            with error:
                if error.code not in (400, 401):
                    raise
                if json.load(error).get("error") != "invalid_grant":
                    raise
            # A rejected early refresh can leave the current access token valid.
            if time.time() < record["expires_at"]:
                return record["access_token"]
        else:
            return save_session(path, registration, tokens, issued_at)

    tokens, registration = oauth.authorize(registration, retain_tokens=True)
    issued_at = time.time()
    oauth.save(registration_path, registration)
    return save_session(path, registration, tokens, issued_at)
