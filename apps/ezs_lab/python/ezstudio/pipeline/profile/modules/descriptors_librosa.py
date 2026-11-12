from __future__ import annotations

import numpy as np

from module import ModuleContext


def run(context: ModuleContext) -> dict:
    import librosa

    rms = librosa.feature.rms(y=context.y)[0]
    centroid = librosa.feature.spectral_centroid(y=context.y, sr=context.sr)[0]
    rolloff = librosa.feature.spectral_rolloff(
        y=context.y,
        sr=context.sr,
        roll_percent=0.85,
    )[0]
    zcr = librosa.feature.zero_crossing_rate(context.y)[0]

    normalized = {
        "dynamics": {
            "rms_mean": round(float(np.mean(rms)), 8),
            "rms_peak": round(float(np.max(rms)), 8),
        },
        "spectral": {
            "centroid_hz_mean": round(float(np.mean(centroid)), 3),
            "rolloff_85_hz_mean": round(float(np.mean(rolloff)), 3),
            "zero_crossing_rate_mean": round(float(np.mean(zcr)), 8),
        },
    }
    return {
        "raw": {
            "rms_frames": int(len(rms)),
            "spectral_frames": int(len(centroid)),
        },
        "normalized": normalized,
        "engine": {"version": getattr(librosa, "__version__", None)},
    }
