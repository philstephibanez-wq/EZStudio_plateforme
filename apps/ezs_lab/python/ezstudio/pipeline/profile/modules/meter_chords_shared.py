from __future__ import annotations

import numpy as np

from meter_shared import estimate_meter_from_chords_engine
from module import ModuleContext
import os


def _cache_harmonic(context: ModuleContext, harmonic: np.ndarray) -> None:
    if context.y.dtype == np.float32 and harmonic.shape == context.y.shape:
        harmonic.flags.writeable = False
        context._meter_harmonic = harmonic
        context._meter_harmonic_sr = int(context.sr)


def run(context: ModuleContext) -> dict:
    result = estimate_meter_from_chords_engine(
        np.asarray(context.y, dtype=np.float32),
        int(context.sr),
        confidence_threshold=0.50,
        harmonic_sink=(
            (lambda harmonic: _cache_harmonic(context, harmonic))
            if os.getenv("EZSTUDIO_PROFILE_HPSS_REUSE") == "1"
            else None
        ),
    )
    confidence = float(result.get("confidence") or 0.0)
    return {
        "raw": result,
        "normalized": result,
        "calibrated": {
            "status": "source_specific_threshold",
            "method": "chords_metric_r9_confidence_threshold_0.50",
            "confidence": round(confidence, 4),
        },
        "model": {
            "id": "beat-this-final0",
            "path": result.get("beat_this_checkpoint"),
        },
        "device": "cuda:0",
    }
