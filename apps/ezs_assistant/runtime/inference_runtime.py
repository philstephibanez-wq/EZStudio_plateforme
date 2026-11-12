"""Fail-closed declarative inference client. Never accesses LAB scientific internals."""
import hashlib
import json
from pathlib import Path
from urllib.parse import urlsplit
from urllib.request import Request, urlopen


class InferenceConfigurationError(ValueError):
    pass


def load_preset(path):
    raw = Path(path).read_bytes()
    sha256 = hashlib.sha256(raw).hexdigest()
    try:
        cfg = json.loads(raw.decode('utf-8-sig'))
    except (ValueError, UnicodeError) as exc:
        raise InferenceConfigurationError('invalid UTF-8 JSON preset') from exc
    if not isinstance(cfg, dict) or cfg.get('schema') != 'ezstudio-assistant.inference.v1':
        raise InferenceConfigurationError('unsupported inference schema')
    if cfg.get('provider') != 'openai-compatible':
        raise InferenceConfigurationError('unsupported provider')
    endpoint = cfg.get('endpoint')
    parsed = urlsplit(endpoint) if isinstance(endpoint, str) else None
    if not parsed or parsed.scheme != 'http' or parsed.hostname not in ('127.0.0.1', 'localhost') or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in ('', '/') or parsed.port is None:
        raise InferenceConfigurationError('endpoint must be explicit loopback HTTP URL with port and no path')
    model = cfg.get('model')
    if not isinstance(model, str) or not model.strip() or len(model) > 256:
        raise InferenceConfigurationError('invalid model')
    params = cfg.get('parameters')
    if not isinstance(params, dict) or set(params) != {'temperature', 'max_tokens'} or type(params['temperature']) not in (int, float) or not (0 <= params['temperature'] <= 2) or type(params['max_tokens']) is not int or not (1 <= params['max_tokens'] <= 4096):
        raise InferenceConfigurationError('invalid parameters')
    return cfg, sha256


def infer(preset_path, messages, *, opener=urlopen):
    cfg, sha = load_preset(preset_path)
    if not isinstance(messages, list) or not messages or any(not isinstance(m, dict) or set(m) != {'role', 'content'} or m['role'] not in ('system', 'user', 'assistant') or not isinstance(m['content'], str) for m in messages):
        raise ValueError('invalid messages')
    payload = {'model': cfg['model'], 'messages': messages, 'stream': False, **cfg['parameters']}
    request = Request(cfg['endpoint'].rstrip('/') + '/v1/chat/completions', data=json.dumps(payload, ensure_ascii=False).encode('utf-8'), headers={'Content-Type': 'application/json; charset=utf-8'}, method='POST')
    try:
        with opener(request, timeout=cfg.get('timeout_seconds', 90)) as response:
            result = json.load(response)
        content = result['choices'][0]['message']['content']
        if not isinstance(content, str) or not content.strip():
            raise ValueError('empty content')
    except (OSError, ValueError, KeyError, IndexError, TypeError) as exc:
        raise RuntimeError('inference unavailable or malformed; no musical verdict produced') from exc
    return {'text': content, 'preset_sha256': sha, 'provider': cfg['provider'], 'model': cfg['model']}
