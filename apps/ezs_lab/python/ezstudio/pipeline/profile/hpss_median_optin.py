"""Optional HPSS median-filter experiment; standard librosa remains the default.

Only supports 1-D float32/float64 mono data for now. Fail closed on
unsupported inputs; no silent scientific fallback when explicitly selected.
"""
from __future__ import annotations

import numpy as np


def _median_reflect_2d(values: np.ndarray, size: tuple[int, int]) -> np.ndarray:
    from scipy.signal import medfilt2d

    if values.ndim != 2 or values.dtype not in (np.float32, np.float64):
        raise ValueError("hpss_medfilt2d_requires_2d_float")
    if any(int(n) < 1 or int(n) % 2 != 1 for n in size):
        raise ValueError("hpss_medfilt2d_requires_odd_kernel")

    pad = tuple((int(n) // 2, int(n) // 2) for n in size)
    # SciPy ndimage reflect is half-sample symmetric (NumPy 'symmetric').
    padded = np.pad(values, pad, mode="symmetric")
    filtered = medfilt2d(padded, kernel_size=size)
    slices = tuple(slice(before, filtered.shape[i] - after if after else None)
                   for i, (before, after) in enumerate(pad))
    return filtered[slices]


def hpss_medfilt2d(y: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """Mirror librosa 0.11 effects.hpss defaults, substituting median only."""
    import librosa

    if y.ndim != 1 or y.dtype not in (np.float32, np.float64):
        raise ValueError("hpss_medfilt2d_requires_mono_float")
    stft = librosa.stft(y, n_fft=2048)
    magnitude, phase = librosa.magphase(stft)
    harm = np.empty_like(magnitude)
    perc = np.empty_like(magnitude)
    harm[:] = _median_reflect_2d(magnitude, (1, 31))
    perc[:] = _median_reflect_2d(magnitude, (31, 1))
    mask_harm = librosa.util.softmask(harm, perc, power=2.0, split_zeros=True)
    mask_perc = librosa.util.softmask(perc, harm, power=2.0, split_zeros=True)
    stft_harm = (magnitude * mask_harm) * phase
    stft_perc = (magnitude * mask_perc) * phase
    harmonic = librosa.istft(stft_harm, dtype=y.dtype, n_fft=2048, length=y.shape[-1])
    percussive = librosa.istft(stft_perc, dtype=y.dtype, n_fft=2048, length=y.shape[-1])
    return harmonic, percussive
