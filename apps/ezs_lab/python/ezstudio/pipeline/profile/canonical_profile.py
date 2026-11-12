from __future__ import annotations

from typing import Any


# Deterministic editorial hierarchy layered over the existing CLAP genre
# candidates. It never changes model scores or the CLAP taxonomy/softmax.
_GENRE_MAP: dict[str, tuple[str, str, str | None]] = {
    "pop": ("pop", "pop", None),
    "rock": ("rock", "rock", None),
    "soft rock": ("rock", "rock", "soft rock"),
    "folk": ("folk_roots", "folk", None),
    "singer-songwriter": ("folk_roots", "singer-songwriter", None),
    "country": ("folk_roots", "country", None),
    "blues": ("blues_soul", "blues", None),
    "soul": ("blues_soul", "soul", None),
    "funk": ("blues_soul", "funk", None),
    "jazz": ("jazz", "jazz", None),
    "classical": ("classical", "classical", None),
    "orchestral": ("classical", "orchestral", None),
    "electronic": ("electronic", "electronic", None),
    "disco": ("dance", "disco", None),
    "reggae": ("world_roots", "reggae", None),
    "latin": ("world_roots", "latin", None),
    "world music": ("world_roots", "world music", None),
    "chanson française": ("chanson", "chanson française", None),
    "cabaret": ("chanson", "cabaret", None),
    "ballad": ("popular_song", "ballad", None),
}


def _as_rows(value: Any) -> list[dict[str, Any]]:
    return [row for row in (value or []) if isinstance(row, dict)]


def _genre_rows(semantic: dict[str, Any]) -> list[dict[str, Any]]:
    genre = semantic.get("genre")
    if not isinstance(genre, dict):
        return []

    per_engine = genre.get("per_engine")
    if not isinstance(per_engine, dict):
        return []

    preferred = per_engine.get("semantic.clap-open-vocabulary")
    if isinstance(preferred, list):
        return _as_rows(preferred)

    for rows in per_engine.values():
        if isinstance(rows, list) and rows:
            return _as_rows(rows)
    return []


def build_genre_hierarchy(semantic: dict[str, Any]) -> dict[str, Any]:
    candidates: list[dict[str, Any]] = []
    for rank, row in enumerate(_genre_rows(semantic), start=1):
        label = str(row.get("label") or "").strip()
        if not label:
            continue
        family, genre, subgenre = _GENRE_MAP.get(
            label.casefold(),
            ("unclassified", label, None),
        )
        candidates.append(
            {
                "genre_family": family,
                "genre": genre,
                "subgenre": subgenre,
                "source_label": label,
                "source_rank": rank,
                "source_score": row.get("score"),
                "source_score_type": row.get("score_type"),
            }
        )

    return {
        "schema": "ezstudio.profile.genre-hierarchy.v1",
        "status": "experimental",
        "calibrated": False,
        "method": "deterministic-taxonomy-projection-over-clap-ranking-v1",
        "primary": candidates[0] if candidates else None,
        "candidates": candidates,
        "abstain": not bool(candidates),
        "usable_for_publication": False,
        "note": (
            "Canonical genre_family/genre/subgenre projection. "
            "It preserves the original CLAP ranking and never turns "
            "relative taxonomy scores into calibrated probabilities."
        ),
    }


def _per_engine_rows(semantic: dict[str, Any], family: str) -> dict[str, list[dict[str, Any]]]:
    block = semantic.get(family)
    if not isinstance(block, dict):
        return {}
    value = block.get("per_engine")
    if not isinstance(value, dict):
        return {}
    return {
        str(module): _as_rows(rows)
        for module, rows in value.items()
        if isinstance(rows, list)
    }


def _audioset_agreements(semantic: dict[str, Any]) -> list[dict[str, Any]]:
    block = semantic.get("audioset")
    if not isinstance(block, dict):
        return []
    return _as_rows(block.get("agreements"))


def _audioset_accepted(semantic: dict[str, Any]) -> list[dict[str, Any]]:
    block = semantic.get("audioset")
    if not isinstance(block, dict):
        return []
    return _as_rows(block.get("accepted"))


def _clap_voice_evidence(
    semantic: dict[str, Any],
    labels: set[str],
) -> list[dict[str, Any]]:
    evidence: list[dict[str, Any]] = []
    engines = _per_engine_rows(semantic, "voice")
    for module, rows in engines.items():
        for rank, row in enumerate(rows, start=1):
            label = str(row.get("label") or "").strip()
            if label.casefold() not in labels:
                continue
            evidence.append(
                {
                    "module": module,
                    "family": "voice",
                    "label": label,
                    "rank": rank,
                    "score": row.get("score"),
                    "score_type": row.get("score_type"),
                    "role": "direct",
                }
            )
    return evidence


def _audioset_evidence(
    semantic: dict[str, Any],
    direct_labels: set[str],
    related_labels: set[str] | None = None,
) -> list[dict[str, Any]]:
    related_labels = related_labels or set()
    evidence: list[dict[str, Any]] = []

    accepted_by_label = {
        str(row.get("label") or "").casefold(): row
        for row in _audioset_accepted(semantic)
    }

    for row in _audioset_agreements(semantic):
        label = str(row.get("label") or "").strip()
        folded = label.casefold()
        if folded not in direct_labels and folded not in related_labels:
            continue
        evidence.append(
            {
                "family": "audioset",
                "label": label,
                "support": row.get("support"),
                "confidence": row.get("confidence"),
                "decision": row.get("decision"),
                "calibrated": bool(row.get("calibrated", False)),
                "sources": row.get("source_evidence") or row.get("sources") or [],
                "role": "direct" if folded in direct_labels else "related",
                "accepted": folded in accepted_by_label,
            }
        )
    return evidence


def _dimension(
    name: str,
    evidence: list[dict[str, Any]],
    *,
    direct_engine_min: int = 2,
    allow_single_high_rank_clap: bool = False,
) -> dict[str, Any]:
    direct = [item for item in evidence if item.get("role") == "direct"]
    direct_modules: set[str] = set()
    accepted_audioset = False
    high_rank_clap = False

    for item in direct:
        module = str(item.get("module") or "")
        if module:
            direct_modules.add(module)
        if item.get("family") == "audioset":
            for source in item.get("sources") or []:
                if isinstance(source, dict) and source.get("module"):
                    direct_modules.add(str(source["module"]))
            accepted_audioset = accepted_audioset or bool(item.get("accepted"))
        if (
            item.get("family") == "voice"
            and isinstance(item.get("rank"), int)
            and int(item["rank"]) <= 3
        ):
            high_rank_clap = True

    if accepted_audioset or len(direct_modules) >= direct_engine_min:
        decision = "present"
    elif allow_single_high_rank_clap and high_rank_clap:
        decision = "possible"
    else:
        decision = "inconclusive"

    return {
        "name": name,
        "decision": decision,
        "calibrated": False,
        "engine_support": len(direct_modules),
        "evidence": evidence,
    }


def build_vocal_profile(semantic: dict[str, Any]) -> dict[str, Any]:
    lead = []
    lead += _clap_voice_evidence(
        semantic,
        {"male lead vocal", "female lead vocal"},
    )
    lead += _audioset_evidence(
        semantic,
        {"singing"},
        {"vocal music"},
    )

    backing = _clap_voice_evidence(
        semantic,
        {"backing vocals"},
    )
    backing += _audioset_evidence(
        semantic,
        {"background music"},
        set(),
    )

    harmonies = _clap_voice_evidence(
        semantic,
        {"vocal harmonies"},
    )

    choir = _clap_voice_evidence(
        semantic,
        {"choir"},
    )
    choir += _audioset_evidence(
        semantic,
        {"choir"},
        {"chant", "a capella", "vocal music"},
    )

    dimensions = {
        "lead_vocal": _dimension("lead_vocal", lead),
        "backing_vocals": _dimension(
            "backing_vocals",
            backing,
            allow_single_high_rank_clap=True,
        ),
        "vocal_harmonies": _dimension(
            "vocal_harmonies",
            harmonies,
            allow_single_high_rank_clap=True,
        ),
        # Deliberately strict: backing vocals, chant or generic vocal music
        # do not establish a choir. Only direct choir evidence can do that.
        "choir": _dimension("choir", choir),
    }

    return {
        "schema": "ezstudio.profile.vocal-profile.v1",
        "status": "experimental",
        "calibrated": False,
        "usable_for_stems": False,
        "dimensions": dimensions,
        "choir_decision": dimensions["choir"]["decision"],
        "backing_vocals_decision": dimensions["backing_vocals"]["decision"],
        "abstain": all(
            row["decision"] == "inconclusive"
            for row in dimensions.values()
        ),
        "note": (
            "Canonical vocal contract. Lead vocal, backing vocals, vocal "
            "harmonies and choir are distinct concepts. Related vocal labels "
            "are preserved as evidence but cannot by themselves confirm choir."
        ),
    }
