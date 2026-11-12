from __future__ import annotations

import os
import sys
from pathlib import Path


def dependency_roots() -> list[Path]:
    return [
        Path(
            os.getenv(
                "EZSTUDIO_PROFILE_R3B13_DEP_ROOT",
                r"H:\EZStudio_plateforme\apps\ezs_lab\var\runtime\deps\profile-r3b13",
            )
        ),
        Path(
            os.getenv(
                "EZSTUDIO_PROFILE_DEP_ROOT",
                r"H:\EZStudio_plateforme\apps\ezs_lab\var\runtime\deps\profile-r3b12",
            )
        ),
        Path(
            os.getenv(
                "EZSTUDIO_PROFILE_R3B10_DEP_ROOT",
                r"H:\EZStudio_plateforme\apps\ezs_lab\var\runtime\deps\profile-r3b10",
            )
        ),
    ]


def panns_labels_csv() -> Path:
    return Path(
        os.getenv(
            "PANNS_LABELS_CSV",
            r"H:\EZStudio_plateforme\apps\ezs_lab\var\runtime\deps\panns-data\class_labels_indices.csv",
        )
    )


def prepare_profile_imports() -> list[Path]:
    os.environ.setdefault("PANNS_LABELS_CSV", str(panns_labels_csv()))
    roots = dependency_roots()
    for root in roots:
        if root.is_dir() and str(root) not in sys.path:
            # Append: validated machine CUDA torch/librosa stay authoritative.
            sys.path.append(str(root))
    return roots
