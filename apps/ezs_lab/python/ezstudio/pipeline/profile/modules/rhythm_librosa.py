from __future__ import annotations

import numpy as np

from module import ModuleContext


def run(context: ModuleContext) -> dict:
    import librosa

    onset_env = librosa.onset.onset_strength(y=context.y, sr=context.sr)
    tempo_value, beat_frames = librosa.beat.beat_track(
        onset_envelope=onset_env,
        sr=context.sr,
        units="frames",
    )
    tempo = (
        float(np.asarray(tempo_value).reshape(-1)[0])
        if np.size(tempo_value)
        else 0.0
    )
    normalized = {
        "bpm": round(tempo, 3),
        "confidence": None,
        "source": "librosa.beat",
        "beat_count": int(len(beat_frames)),
    }
    return {
        "raw": {
            "tempo": tempo,
            "beat_frames": np.asarray(beat_frames, dtype=int).tolist(),
        },
        "normalized": normalized,
        "engine": {"version": getattr(librosa, "__version__", None)},
    }
