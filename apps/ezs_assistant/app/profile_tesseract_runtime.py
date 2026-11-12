"""Resolve OCR tessdata models locally on MAIN; never copy models."""
from __future__ import annotations
import os
import shutil
import subprocess
from pathlib import Path

DEFAULT_EXE = r"C:\Program Files\Tesseract-OCR\tesseract.exe"
DEFAULT_TESSDATA = r"H:\EZSModels\tesseract\tessdata_best"



def _ezs_local_tessdata_default():
    cfg_path = Path(__file__).resolve().parents[1] / 'runtime' / 'state' / 'local-model.json'
    try:
        import json
        cfg = json.loads(cfg_path.read_text(encoding='utf-8'))
        root = str(cfg.get('root') or '') if cfg.get('schema') == 'ezs.assistant.model-location.v1' else ''
        from pathlib import PureWindowsPath
        p = PureWindowsPath(root)
        if p.is_absolute() and not str(p).startswith(chr(92)*2) and '..' not in p.parts:
            return str(p / 'tesseract' / 'tessdata_best')
    except (OSError, ValueError, AttributeError):
        pass
    return DEFAULT_TESSDATA

def resolve_tesseract():
    """Fail closed when a required local OCR model is unavailable."""
    exe = os.environ.get("EZS_TESSERACT_EXE") or shutil.which("tesseract") or DEFAULT_EXE
    if not Path(exe).is_file():
        return {"status": "executable_missing"}
    data_path = os.environ.get("EZS_TESSDATA_DIR") or _ezs_local_tessdata_default()
    if data_path.startswith(chr(92) * 2) or data_path.startswith("//"):
        return {"status": "network_model_path_refused"}
    data = Path(data_path)
    if not (data / "eng.traineddata").is_file():
        return {"status": "english_model_unavailable", "tessdata_dir": data_path}
    if not (data / "fra.traineddata").is_file():
        return {"status": "french_model_unavailable", "tessdata_dir": data_path}
    env = os.environ.copy()
    # - -tessdata-dir explicitly sets model location in Tesseract 5.
    try:
        result = subprocess.run([str(exe), "--list-langs", "--tessdata-dir", str(data)],
                                capture_output=True, text=True, timeout=10, env=env)
    except (OSError, subprocess.TimeoutExpired):
        return {"status": "models_unreadable", "tessdata_dir": data_path}
    if result.returncode != 0 or not {"eng", "fra"}.issubset(set(result.stdout.splitlines())):
        return {"status": "models_unreadable", "tessdata_dir": data_path}
    return {"status": "ready", "executable": str(exe), "tessdata_dir": str(data)}


def booklet_ocr_enabled():
    """Read persistent Windows User setting even when Guardian inherited stale env.

    A Process-level value takes precedence. Missing or malformed values fail closed.
    """
    value = os.environ.get("EZS_PROFILE_BOOKLET_OCR")
    if value is None and os.name == "nt":
        try:
            import winreg
            with winreg.OpenKey(winreg.HKEY_CURRENT_USER, "Environment") as key:
                value, _kind = winreg.QueryValueEx(key, "EZS_PROFILE_BOOKLET_OCR")
        except (OSError, ImportError):
            value = None
    return str(value or "").strip() == "1"
