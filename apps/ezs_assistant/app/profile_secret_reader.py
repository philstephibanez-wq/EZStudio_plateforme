"""Platform-specific PROFILE token reader. No plaintext token in Git or env."""
from __future__ import annotations

import os
import stat
from pathlib import Path

SECRET_PATH_ENV = "EZSTUDIOLAB_ASSISTANT_TOKEN_FILE"


def read_profile_token(*, platform=None) -> str:
    system = os.name if platform is None else platform
    if system == "nt":
        # This script is imported both as app.profile_secret_reader by tests
        # and as profile_secret_reader by the legacy subprocess.
        from web_status import DEFAULT_TOKEN_FILE, _read_dpapi_token
        return _read_dpapi_token(DEFAULT_TOKEN_FILE)
    if system != "posix":
        return ""
    secret_name = os.environ.get(SECRET_PATH_ENV, "")
    if not secret_name:
        return ""
    try:
        secret_path = Path(secret_name)
        if not secret_path.is_absolute():
            return ""
        info = secret_path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o022:
            return ""
        if not 1 <= info.st_size <= 4096:
            return ""
        value = secret_path.read_text(encoding="utf-8").strip()
    except (OSError, UnicodeError, ValueError):
        return ""
    if not value or len(value) > 2048 or any(c in value for c in ("\0", "\n", "\r")):
        return ""
    return value
