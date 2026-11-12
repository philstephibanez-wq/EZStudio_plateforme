"""On-demand end-to-end probe of the declarative inference runtime.

No verdicts, no Web research, no LAB data, no service lifecycle actions.
"""
import argparse
import hashlib
import json
import sys
from pathlib import Path

try:
    from runtime.inference_runtime import infer
except ModuleNotFoundError:
    # Supports direct execution from the repository root on Windows.
    sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
    from runtime.inference_runtime import infer

DEFAULT_PRESET = Path(__file__).resolve().parents[1] / "config" / "inference.main.json"
SYSTEM_PROMPT = ("Tu es un moteur de formulation, sans accès à des preuves externes. "
                 "Ne déclare jamais qu'une assertion musicale est vérifiée sans source.")
USER_PROMPT = ("Réponds exactement en une phrase française : "
               "« Une assertion musicale sans preuve indépendante reste non vérifiée. »")


def probe(preset=DEFAULT_PRESET, *, client=infer):
    result = client(str(preset), [
        {"role": "system", "content": SYSTEM_PROMPT},
        {"role": "user", "content": USER_PROMPT},
    ])
    if not isinstance(result, dict) or not isinstance(result.get("text"), str) or not result["text"].strip():
        raise RuntimeError("invalid inference probe result")
    if not isinstance(result.get("preset_sha256"), str) or len(result["preset_sha256"]) != 64:
        raise RuntimeError("missing preset trace")
    return {
        "status": "INFERENCE_OK",
        "provider": result["provider"],
        "model": result["model"],
        "preset_sha256": result["preset_sha256"],
        "response": result["text"],
        "musical_verdict": None,
        "independent_sources_checked": 0,
    }


def main(argv=None):
    parser = argparse.ArgumentParser(description="Single on-demand inference request using the declarative preset")
    parser.add_argument("--preset", type=Path, default=DEFAULT_PRESET)
    args = parser.parse_args(argv)
    try:
        result = probe(args.preset)
    except (OSError, ValueError, RuntimeError, KeyError) as exc:
        print("INFERENCE_PROBE_FAILED: " + type(exc).__name__ + ": " + str(exc), file=sys.stderr)
        return 2
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
