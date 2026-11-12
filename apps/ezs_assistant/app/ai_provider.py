from __future__ import annotations

import json
import os
import re
from dataclasses import dataclass
from pathlib import Path
import urllib.error
import urllib.request
import time
from profile_trace import emit as trace_emit, snap as trace_snap
from typing import Any
from declarative_review import load_review_preset

DEFAULT_SHARED_MODEL_ROOT = r"H:\EZSModels"
DEFAULT_OPENAI_MODEL = "gpt-6-luna"


@dataclass(frozen=True)
class AIProviderConfig:
    provider: str = "off"
    model: str = DEFAULT_OPENAI_MODEL
    web_search: bool = False
    timeout_seconds: float = 60.0
    shared_model_root: str = DEFAULT_SHARED_MODEL_ROOT
    local_model_path: str | None = None
    local_cache_root: str | None = None
    local_endpoint: str = "http://127.0.0.1:8720"


class AIReviewProvider:
    provider_name = "off"

    def status(self) -> dict[str, Any]:
        return {"provider": self.provider_name, "enabled": False, "model": None}

    def review_profile(self, row: dict[str, Any], research: dict[str, Any]) -> dict[str, Any]:
        return {
            "schema": "EZStudioLab_assistant.ai-profile-review.v1",
            "status": "disabled",
            "provider": self.provider_name,
            "model": None,
            "confidence": 0.0,
            "summary": None,
            "dimensions": {},
            "sources": [],
        }


class DisabledAIProvider(AIReviewProvider):
    provider_name = "off"


class DisabledAIProviderWithReason(DisabledAIProvider):
    def __init__(self, reason: str, *, provider: str = "off") -> None:
        self.reason = reason
        self.provider_name = provider

    def status(self) -> dict[str, Any]:
        return {
            "provider": self.provider_name,
            "enabled": False,
            "model": None,
            "reason": self.reason,
        }

    def review_profile(self, row: dict[str, Any], research: dict[str, Any]) -> dict[str, Any]:
        payload = super().review_profile(row, research)
        payload["status"] = "unavailable"
        payload["provider"] = self.provider_name
        payload["reason"] = self.reason
        return payload


# PROFILE_LOCAL_CONTEXT_BUDGET_R1
# Deterministic reduction of LAB evidence. No model-generated claims or invented sources.
def _local_profile_compact(value, *, depth=0, max_depth=4, list_limit=8, text_limit=450):
    if depth > max_depth:
        return None
    if isinstance(value, str):
        return value[:text_limit] + (" [truncated]" if len(value) > text_limit else "")
    if isinstance(value, (int, float, bool)) or value is None:
        return value
    if isinstance(value, list):
        return [_local_profile_compact(v, depth=depth + 1, max_depth=max_depth,
                 list_limit=list_limit, text_limit=text_limit) for v in value[:list_limit]]
    if isinstance(value, dict):
        return {str(k): _local_profile_compact(v, depth=depth + 1, max_depth=max_depth,
                list_limit=list_limit, text_limit=text_limit)
                for k, v in list(value.items())[:30]
                if not str(k).startswith("_")}
    return str(value)[:text_limit]


def _local_profile_evidence(row, research):
    """PROFILE_EVIDENCE_R3B: an evidence-aware, bounded view of actual LAB paths.

    This is a view for the AI reviewer, not a replacement for scientific output.
    Unverified labels and score ranks are not independent reference evidence.
    """
    def obj(value):
        return value if isinstance(value, dict) else {}

    def short(value, limit=75):
        return value[:limit] if isinstance(value, str) else value if isinstance(value, (bool, int, float)) else None

    def pick(value, keys):
        data = obj(value)
        return {key: short(data[key]) for key in keys if key in data and short(data[key]) is not None}

    def labels(items, limit=3):
        return [pick(item, ('label', 'score', 'score_type', 'decision', 'support', 'source_score', 'rank_consensus'))
                for item in (items if isinstance(items, list) else [])[:limit] if isinstance(item, dict)]

    diag = obj(row.get('diagnostics'))
    profile = obj(diag.get('profile_view'))
    tagging = obj(diag.get('tagging'))
    genre = obj(profile.get('genre_hierarchy'))
    instrumentation = obj(profile.get('instrumentation'))
    vocals = obj(profile.get('vocal_profile'))
    dims = obj(vocals.get('dimensions'))
    musical = {
        'genre': {
            'status': short(genre.get('status')),
            'primary': pick(genre.get('primary'), ('genre_family', 'genre', 'subgenre', 'source_label', 'source_score', 'source_score_type')),
            'candidates': [pick(x, ('genre', 'genre_family', 'source_score')) for x in genre.get('candidates', [])[:3] if isinstance(x, dict)],
            'tagging': labels(tagging.get('genre'), 3),
        },
        'instrumentation': {
            'status': short(instrumentation.get('status')),
            'candidates': [pick(x, ('label', 'support', 'source_count', 'decision', 'rank_consensus'))
                           for x in instrumentation.get('candidates', [])[:5] if isinstance(x, dict)],
            'tagging': labels(tagging.get('instrumentation'), 4),
        },
        'vocals': {
            'status': short(vocals.get('status')),
            'dimensions': {
                key: {
                    **pick(dims.get(key), ('decision', 'engine_support', 'calibrated')),
                    'evidence': [pick(x, ('module', 'label', 'score', 'score_type', 'role'))
                                 for x in obj(dims.get(key)).get('evidence', [])[:2] if isinstance(x, dict)],
                }
                for key in ('lead_vocal', 'backing_vocals', 'vocal_harmonies', 'choir')
                if key in dims
            },
            'tagging': labels(tagging.get('voice'), 3),
        },
    }
    research = obj(research)
    # Only title/artist-relevant references may be supplied as external evidence.
    # Failed or irrelevant lookups are not proof about the recording.
    verified_sources = [
        source for source in research.get('sources', [])
        if isinstance(source, dict)
        and source.get('available') is True
        and source.get('identity_relevant') is True
    ] if isinstance(research.get('sources'), list) else []
    research = {
        **research,
        'sources': verified_sources,
        'relevant_source_count': len(verified_sources),
    }
    # Traceable recording-level credits are distinct from inferred instruments.
    credits = []
    for source in verified_sources:
        if source.get('source') != 'MusicBrainz':
            continue
        for item in source.get('recording_credits', [])[:12]:
            if not isinstance(item, dict) or item.get('scope') != 'recording':
                continue
            credits.append({
                'musician': str(item.get('musician') or '')[:90],
                'role': str(item.get('role') or '')[:50],
                'instruments': [str(v)[:65] for v in item.get('instruments', [])[:5]],
                'scope': 'recording',
                'source_url': source.get('url'),
            })
    # Typed genre assertions: only genres on the identity-matched recording,
    # never artist/release enrichment or free-form user tags.
    genre_facts = []
    for source in verified_sources:
        if source.get('source') != 'MusicBrainz':
            continue
        url = source.get('url')
        if not isinstance(url, str) or not url.startswith('https://musicbrainz.org/recording/'):
            continue
        for label in source.get('recording_genres', [])[:12]:
            if isinstance(label, str) and label.strip():
                genre_facts.append({'source':'MusicBrainz','scope':'recording',
                                    'source_url':url,'genre':label.strip()[:80]})
    # Wikipedia is available as an identity-matched quotation source, not as a
    # machine-verifiable dimension assertion. Preserve bounded text for review.
    wikipedia_quotes = []
    for source in verified_sources:
        if source.get('source') != 'Wikipedia':
            continue
        url = source.get('url')
        extract = source.get('extract')
        if isinstance(url, str) and url.startswith('https://en.wikipedia.org/wiki/') and isinstance(extract, str):
            wikipedia_quotes.append({
                'source': 'Wikipedia', 'source_url': url,
                'scope': 'identity_matched_page', 'extract': extract[:2500],
            })
    # Explicitly keep reference metadata if present, but never raw website bodies.
    refs = {'recording_credits': credits[:12], 'recording_genres': genre_facts[:12], 'wikipedia_quotes': wikipedia_quotes[:2]}
    for key in ('identity', 'genres', 'genre_labels', 'instruments', 'instrumentation', 'vocals', 'matches', 'references', 'sources'):
        value = research.get(key)
        if isinstance(value, list):
            refs[key] = [_local_profile_compact(x, max_depth=2, list_limit=2, text_limit=90)
                         for x in value[:2]]
        elif isinstance(value, dict):
            refs[key] = _local_profile_compact(value, max_depth=2, list_limit=2, text_limit=90)
    return {
        'identity': {key: short(row.get(key), 100) for key in ('title', 'artist', 'run_id')},
        'profile_view': musical,
        'public_research': refs,
        'evidence_notice': 'LAB predictions are not independent evidence. Score a dimension only when trustworthy external references specifically support that dimension. Judge each dimension independently; missing support in one does not invalidate the others.',
    }


def _local_profile_budget(evidence, system_prompt, *, max_chars=6500):
    # PROFILE_LOCAL_CONTEXT_BUDGET_R2: progressively bound only the copy sent to AI.
    # Prefer identity and structured music features over expansive diagnostic records.
    # A character/UTF-8 bound is conservative, not a tokenizer-based guarantee.
    def pack(value):
        return json.dumps(value, ensure_ascii=False, separators=(",", ":"))

    def fits(value):
        raw = pack(value)
        return (len(raw) + len(system_prompt) <= max_chars and
                len(raw.encode("utf-8")) + len(system_prompt.encode("utf-8")) <= max_chars)

    if fits(evidence):
        return pack(evidence)

    identity = evidence.get("identity") or {}
    profile = evidence.get("profile_view") or {}
    research = evidence.get("public_research") or {}
    if not isinstance(profile, dict):
        profile = {}
    if not isinstance(research, dict):
        research = {}

    # Filtering by *meaningful field names*, not unbounded insertion order.
    # Preserve data already in the LAB payload; never fabricate a missing value.
    profile_priority = (
        "genre", "genres", "genre_labels", "instrumentation", "instruments",
        "lead_vocal", "lead_vocals", "backing_vocals", "choir", "vocals",
        "tempo", "bpm", "meter", "key", "rhythm", "semantic", "clap",
    )
    research_priority = (
        "recording_credits", "recording_genres", "wikipedia_quotes", "identity", "genres", "genre_labels", "instruments", "instrumentation",
        "vocals", "matches", "references", "sources", "warnings",
    )
    selected_profile = {key: profile[key] for key in profile_priority if key in profile}
    selected_research = {key: research[key] for key in research_priority if key in research}
    for depth, elements, text in ((3, 4, 180), (2, 3, 120), (2, 2, 80), (1, 1, 55)):
        compact_profile = _local_profile_compact(
            selected_profile, max_depth=depth, list_limit=elements, text_limit=text
        )
        compact_research = _local_profile_compact(
            selected_research, max_depth=depth, list_limit=elements, text_limit=text
        )
        # Recording-scoped facts are the only independently verifiable scoring
        # inputs. Generic depth compaction replaces their nested fields with
        # None, which silently turns available evidence into abstentions.
        # Preserve complete, bounded source facts from the already-filtered
        # research; never synthesize or promote a missing source.
        for typed_key in ("recording_genres", "recording_credits"):
            facts = selected_research.get(typed_key)
            if isinstance(facts, list) and facts:
                compact_research[typed_key] = [
                    dict(item) for item in facts[:elements] if isinstance(item, dict)
                ]
        # The reduced representation must not silently drop the scientific
        # dimension containers. Preserve missing-data status per dimension.
        preserved = {
            name: bool(compact_profile.get(name))
            for name in ("genre", "instrumentation", "vocals")
        }
        omitted_profile = sorted(set(profile) - set(compact_profile))
        omitted_research = sorted(set(research) - set(compact_research))
        candidate = {
            "identity": _local_profile_compact(identity, max_depth=2, list_limit=3, text_limit=120),
            "profile_view": compact_profile,
            "public_research": compact_research,
            "context_reduction": {
                "active": True,
                "included_profile_sections": [k for k, present in preserved.items() if present],
                "omitted_profile_sections": omitted_profile,
                "omitted_research_sections": omitted_research,
                "item_limit": elements,
                "text_limit": text,
                "note": "Some values may be shortened within listed sections. Judge each dimension separately; do not infer an omission in another dimension. Never treat LAB predictions as public evidence.",
            },
        }
        if fits(candidate):
            return pack(candidate)

    # Fail closed rather than silently removing all evidence.
    raise ValueError("context_budget_exceeded")



def _verified_wikipedia_song_genre(claim: dict, public: dict, identity: dict) -> bool:
    """Admit only an exact, explicitly attributed song-genre sentence.

    This checks documentary provenance, not acoustic correctness. Vague page
    matches, artist biographies and inferred genres remain non-scoring.
    """
    if claim.get('source') != 'Wikipedia' or claim.get('scope') != 'song_statement':
        return False
    url, quote, genre = claim.get('source_url'), claim.get('quote'), claim.get('genre')
    title, artist = identity.get('title'), identity.get('artist')
    if not all(isinstance(v, str) and v.strip() for v in (url, quote, genre, title, artist)):
        return False
    if len(quote) > 320 or '\\n' in quote or '\\r' in quote:
        return False
    if genre.strip().casefold() not in {'pop', 'rock', 'folk', 'blues', 'jazz', 'country', 'disco', 'chanson'}:
        return False
    # Exact sentence, not a model paraphrase or a substring of an article
    # about a different version / artist.
    sentence = quote.strip()
    if not re.fullmatch(
        r'(?i)' + re.escape(title.strip()) +
        r'\s+is\s+an?\s+' + re.escape(genre.strip()) +
        r'\s+song\s+by\s+' + re.escape(artist.strip()) + r'[.!]?',
        sentence
    ):
        return False
    pages = public.get('wikipedia_quotes')
    if not isinstance(pages, list):
        return False
    return any(
        isinstance(page, dict) and page.get('source') == 'Wikipedia'
        and page.get('scope') == 'identity_matched_page'
        and page.get('source_url') == url
        and isinstance(page.get('extract'), str)
        and sentence in page['extract']
        for page in pages
    )


def _verified_dimension_evidence(dimensions: dict, sent_evidence: dict) -> dict:
    """Accept only recording-scoped public credits selected by the identity-matched researcher.

    A model's own claims, LAB modules, and broad MusicBrainz tags cannot
    authenticate themselves. Other external research awaits its own typed verifier.
    """
    public = sent_evidence.get('public_research') if isinstance(sent_evidence, dict) else {}
    public = public if isinstance(public, dict) else {}
    allowed = public.get('recording_credits')
    allowed = allowed if isinstance(allowed, list) else []
    known_genres = public.get('recording_genres')
    known_genres = known_genres if isinstance(known_genres, list) else []
    identity = sent_evidence.get('identity') if isinstance(sent_evidence, dict) else {}
    identity = identity if isinstance(identity, dict) else {}
    result = {}
    for name, item in dimensions.items():
        if not isinstance(item, dict):
            continue
        original = item.get('evidence')
        entries = original if isinstance(original, list) else []
        accepted = []
        for claim in entries:
            if not isinstance(claim, dict):
                continue
            if name == 'genre' and _verified_wikipedia_song_genre(claim, public, identity):
                accepted.append(dict(claim))
                continue
            if claim.get('scope') != 'recording':
                continue
            if claim.get('source') != 'MusicBrainz':
                continue
            url = claim.get('source_url')
            role = str(claim.get('role') or '').strip().casefold()
            instrument = str(claim.get('instrument') or '').strip().casefold()
            if not isinstance(url, str) or not url.startswith('https://musicbrainz.org/'):
                continue
            if name == 'genre':
                genre = str(claim.get('genre') or '').strip().casefold()
                if genre and any(
                    isinstance(ref, dict) and ref.get('scope') == 'recording'
                    and ref.get('source') == 'MusicBrainz'
                    and ref.get('source_url') == url
                    and str(ref.get('genre') or '').strip().casefold() == genre
                    for ref in known_genres
                ):
                    accepted.append(dict(claim))
                continue
            for credit in allowed:
                if not isinstance(credit, dict) or credit.get('scope') != 'recording':
                    continue
                if credit.get('source_url') != url:
                    continue
                credited_role = str(credit.get('role') or '').strip().casefold()
                credited_instruments = [str(v).strip().casefold() for v in credit.get('instruments', []) if isinstance(v, str)]
                if name == 'instrumentation' and instrument and instrument in credited_instruments:
                    accepted.append(dict(claim)); break
                # MusicBrainz may represent vocal specialization in relation
                # attributes (instruments), while the relation role is "vocal".
                # A generic "vocal" credit does NOT establish lead vocals.
                credited_terms = {credited_role, *credited_instruments}
                if name == 'lead_vocal' and role == credited_role and (
                    'lead vocal' in credited_terms or 'lead vocals' in credited_terms
                ):
                    accepted.append(dict(claim)); break
                if name == 'backing_vocals' and role == credited_role and credited_terms.intersection(
                    {'backing vocal', 'backing vocals', 'background vocal', 'background vocals'}
                ):
                    accepted.append(dict(claim)); break
                if name == 'choir' and role == credited_role and credited_terms.intersection(
                    {'choir', 'chorus', 'choral vocals', 'choir vocals'}
                ):
                    accepted.append(dict(claim)); break
        normalized = dict(item)
        if normalized.get('decision') in ('match','mismatch') and not accepted:
            normalized['decision'] = 'not_scored'
            normalized['confidence'] = None
            normalized['reason'] = 'no_verified_external_dimension_evidence'
        normalized['evidence'] = accepted
        result[name] = normalized
    return result


def _profile_ai_coverage(dimensions: dict) -> dict:
    """Advisory-only coverage; never infer musical evidence from prose."""
    names = ('genre', 'instrumentation', 'lead_vocal', 'backing_vocals', 'choir')
    details = {}
    scored = 0
    for name in names:
        raw = dimensions.get(name)
        value = dict(raw) if isinstance(raw, dict) else {}
        decision = value.get('decision')
        if decision not in ('match', 'mismatch', 'uncertain', 'not_scored'):
            decision = 'not_scored'
        conf = value.get('confidence')
        if isinstance(conf, bool) or not isinstance(conf, (int, float)) or not 0.0 <= float(conf) <= 1.0:
            conf = None
        evidence = value.get('evidence')
        has_evidence = (isinstance(evidence, str) and bool(evidence.strip())) or (
            isinstance(evidence, list) and any(bool(x) for x in evidence)
        )
        # match/mismatch requires both a confidence and an actual evidence item.
        valid = decision in ('match', 'mismatch') and conf is not None and has_evidence
        reason = value.get('reason') or value.get('rationale')
        if not valid:
            if decision in ('match', 'mismatch'):
                reason = 'insufficient_dimension_evidence_or_confidence'
            elif not reason:
                reason = 'missing_evidence_in_profile_context' if not value else 'dimension_not_scored'
            decision = 'not_scored'
            conf = None
        else:
            scored += 1
        # Musical judgement remains independent of documentary validation.
        advisory = value.get('advisory')
        if not isinstance(advisory, str) or not advisory.strip():
            advisory = None
        certainty = value.get('certainty')
        if certainty not in ('faible', 'moyenne', 'forte', 'indéterminée'):
            certainty = 'indéterminée'
        recommendation = value.get('recommendation')
        if not isinstance(recommendation, str) or not recommendation.strip():
            recommendation = None
        details[name] = {
            'advisory': advisory.strip()[:600] if advisory else None,
            'certainty': certainty,
            'recommendation': recommendation.strip()[:350] if recommendation else None,
            'decision': decision, 'confidence': float(conf) if conf is not None else None,
            'evidence': evidence if has_evidence else [],
            'rationale': value.get('rationale') or '',
            'reason': str(reason)[:300] if not valid else None,
        }
    return {
        'schema': 'ezs.profile-ai-coverage.v1',
        'dimensions': details,
        'scored_count': scored,
        'total_count': len(names),
        'coverage': round(scored / len(names), 3),
        'complete': scored == len(names),
        'external_validation': False,
    }

class LocalAIProvider(AIReviewProvider):
    provider_name = "local"

    def __init__(self, config: AIProviderConfig) -> None:
        self.config = config
        self.canonical_model_path = str(config.local_model_path or "").strip()
        if not self.canonical_model_path:
            raise RuntimeError("local_ai_model_path_required")
        if not _is_model_under_root(config.shared_model_root, self.canonical_model_path):
            raise RuntimeError("local_ai_model_must_be_under_shared_model_root")
        cache_root = str(config.local_cache_root or "").strip() or str(Path(__file__).resolve().parents[1] / "runtime" / "models-cache")
        self.cache_root = cache_root
        relative = _model_relative_path(config.shared_model_root, self.canonical_model_path)
        self.model_path = self.canonical_model_path
        self.endpoint = str(config.local_endpoint or "http://127.0.0.1:8720").rstrip("/")

    def _health(self) -> tuple[bool, str | None]:
        req = urllib.request.Request(self.endpoint + "/health", headers={"Accept":"application/json"}, method="GET")
        try:
            with urllib.request.urlopen(req, timeout=min(3.0, max(1.0, float(self.config.timeout_seconds)))) as response:
                body = response.read(65536)
            payload = json.loads(body.decode("utf-8"))
            if isinstance(payload, dict):
                status = str(payload.get("status") or "").casefold()
                if status in {"ok","ready"} or payload.get("ok") is True:
                    return True, None
            return False, "local_ai_health_not_ready"
        except Exception as exc:
            return False, f"{type(exc).__name__}:{exc}"[:300]

    def status(self) -> dict[str, Any]:
        ready, reason = self._health()
        return {"provider":"local","enabled":ready,"model":self.model_path,"canonical_model":self.canonical_model_path,"cache_root":self.cache_root,"endpoint":self.endpoint,"shared_model_root":str(self.config.shared_model_root),"reason":reason}

    @staticmethod
    def _decode_local_json(content: str) -> dict[str, Any]:
        text = str(content or "").strip()
        if not text:
            raise ValueError("local_ai_content_missing")

        try:
            value = json.loads(text)
            if isinstance(value, dict):
                return value
        except json.JSONDecodeError:
            pass

        fenced = re.fullmatch(
            r"```(?:json)?\s*(\{.*\})\s*```",
            text,
            flags=re.IGNORECASE | re.DOTALL,
        )
        if fenced:
            value = json.loads(fenced.group(1))
            if isinstance(value, dict):
                return value

        start = text.find("{")
        if start >= 0:
            value, _end = json.JSONDecoder().raw_decode(text[start:])
            if isinstance(value, dict):
                return value

        raise ValueError("local_ai_json_object_required")

    @staticmethod
    def _normalize_local_dimensions(value: Any, normalization: dict | None = None) -> dict[str, Any]:
        """Normalize advisory AI output, never infer a musical match from an unknown label."""
        canonical = ('genre', 'instrumentation', 'lead_vocal', 'backing_vocals', 'choir')
        # Compatible default for direct validators; runtime always supplies MAIN preset.
        config = normalization if normalization is not None else {
            "aliases": {"genres":"genre","music_genre":"genre","musical_genre":"genre","instruments":"instrumentation","instrument":"instrumentation","lead_vocals":"lead_vocal","lead_voice":"lead_vocal","main_vocal":"lead_vocal","main_vocals":"lead_vocal","background_vocals":"backing_vocals","background_vocal":"backing_vocals","backing_vocal":"backing_vocals","backing_voice":"backing_vocals","choirs":"choir","choral_vocals":"choir"},
            "uncertainty": ["possible","possibly","inconclusive","inconclusif","indeterminate","indéterminé","ambiguous","maybe","unclear"],
            "vocal_group": "vocals",
        }
        aliases = config["aliases"]
        uncertainty = set(config["uncertainty"])
        vocal_group = config["vocal_group"]
        def unknown(reason: str) -> dict[str, Any]:
            return {'decision':'not_scored', 'confidence':None, 'evidence':[],
                    'rationale':'', 'reason':reason}
        result = {name: unknown('dimension_missing_from_model_output') for name in canonical}
        if isinstance(value, dict):
            items = list(value.items())
        elif isinstance(value, list):
            items = []
            for entry in value:
                if not isinstance(entry, dict):
                    continue
                label = entry.get('name', entry.get('dimension'))
                if isinstance(label, str):
                    items.append((label, {k:v for k,v in entry.items() if k not in ('name','dimension')}))
                else:
                    keys = [k for k in entry if k not in ('decision','confidence','evidence','rationale','reason')]
                    if len(keys)==1:
                        k=keys[0]
                        v=entry[k]
                        items.append((k, v if isinstance(v,dict) else {'decision':v}))
        else:
            return result
        # Qwen may group separate named vocal dimensions under "vocals".
        # Expand only explicit subkeys; never interpret a generic vocals claim
        # as evidence for lead, backing or choir.
        expanded = []
        for raw_name, datum in items:
            if isinstance(raw_name, str) and raw_name.strip().casefold() == vocal_group and isinstance(datum, dict):
                for child, child_value in datum.items():
                    child_key = str(child).strip().casefold().replace('-', '_').replace(' ', '_')
                    if aliases.get(child_key, child_key) in ('lead_vocal', 'backing_vocals', 'choir'):
                        expanded.append((child, child_value))
            else:
                expanded.append((raw_name, datum))
        items = expanded
        seen = set()
        for raw_name, datum in items:
            if not isinstance(raw_name, str):
                continue
            key = raw_name.strip().casefold().replace('-', '_').replace(' ', '_')
            name = aliases.get(key, key)
            # The vague 'vocals' group cannot safely be assigned to lead, backing or choir.
            if name not in canonical:
                continue
            if name in seen:
                result[name] = unknown('duplicate_dimension_in_model_output')
                continue
            seen.add(name)
            if isinstance(datum, str):
                datum = {'decision': datum}
            if not isinstance(datum, dict):
                result[name] = unknown('invalid_dimension_value')
                continue
            raw_decision=datum.get('decision', 'not_scored')
            if not isinstance(raw_decision, str):
                result[name] = unknown('invalid_decision_type')
                continue
            decision=raw_decision.strip().casefold().replace('-', '_').replace(' ', '_')
            if decision in uncertainty:
                decision='uncertain'
            if decision not in ('match','mismatch','uncertain','not_scored'):
                result[name] = unknown('unrecognized_ai_decision:' + decision[:70])
                continue
            result[name] = {
                'decision':decision, 'confidence':datum.get('confidence'),
                'evidence':datum.get('evidence'), 'rationale':datum.get('rationale'),
                'reason':datum.get('reason'),
                'advisory': datum.get('advisory'),
                'certainty': datum.get('certainty'),
                'recommendation': datum.get('recommendation'),
            }
        return result

    @staticmethod
    def _validate_local_result(value: Any, *, model: str, sent_evidence: dict | None = None, normalization: dict | None = None) -> dict[str, Any]:
        if not isinstance(value, dict): raise ValueError("ai_profile_review_json_object_required")
        confidence = value.get("confidence")
        if confidence is not None:
            if isinstance(confidence, bool) or not isinstance(confidence, (int, float)):
                raise ValueError("ai_profile_review_confidence_required")
            confidence = float(confidence)
            if not 0.0 <= confidence <= 1.0:
                raise ValueError("ai_profile_review_confidence_out_of_range")
        # Qwen can return the named dimensions at JSON root instead of nesting
        # them in "dimensions". Reuse the exact same normalization and
        # independent-evidence validator; do not infer or fabricate claims.
        raw_dimensions = value.get("dimensions")
        if raw_dimensions is None:
            canonical_keys = {'genre', 'instrumentation', 'lead_vocal', 'backing_vocals', 'choir'}
            accepted_root_keys = canonical_keys | (set(normalization['aliases']) | {normalization['vocal_group']} if normalization else {
                'genre', 'genres', 'music_genre', 'musical_genre', 'instrumentation', 'instruments', 'instrument',
                'lead_vocal', 'lead_vocals', 'lead_voice', 'main_vocal', 'main_vocals', 'vocals', 'backing_vocals',
                'backing_vocal', 'backing_voice', 'background_vocals', 'background_vocal', 'choir', 'choirs', 'choral_vocals',
            })
            raw_dimensions = {
                key: item for key, item in value.items()
                if key in accepted_root_keys
            }
        dimensions = LocalAIProvider._normalize_local_dimensions(raw_dimensions, normalization=normalization)
        dimensions = _verified_dimension_evidence(dimensions, sent_evidence or {})
        coverage = _profile_ai_coverage(dimensions)
        dimensions = coverage["dimensions"]
        # Global confidence is optional; scored dimensions require their own confidence.
        summary = value.get("summary")
        if summary is not None and not isinstance(summary, str): raise ValueError("ai_profile_review_summary_string_required")
        # Model prose is not independent evidence. Without any substantiated
        # dimension, do not publish free-form hypotheses as findings.
        if coverage["scored_count"] == 0:
            # Preserve the model's observation as an explicitly unverified
            # hypothesis, never as musical proof or preset validation.
            hypothesis = summary.strip()[:700] if isinstance(summary, str) else ""
            summary = (
                "Validation externe : 0/5 dimension vérifiée. "
                "Aucune conclusion musicale confirmée ; aucune promotion de preset."
            )
            if hypothesis:
                summary += " Hypothèse Qwen NON VÉRIFIÉE : " + hypothesis
            confidence = None
        elif isinstance(summary, str) and summary.strip():
            summary = (
                "Commentaire exploratoire de l’IA (non validé indépendamment) : "
                + summary.strip()
            )
        sources = value.get("sources") if isinstance(value.get("sources"), list) else []
        return {"schema":"EZStudioLab_assistant.ai-profile-review.v1","status":"completed","provider":"local","model":model,"confidence":(round(confidence,3) if confidence is not None else None),"summary":summary.strip() if isinstance(summary,str) else None,"dimensions":dimensions,"coverage":coverage,"sources":[x for x in sources if isinstance(x,dict)][:20]}

    def review_profile(self, row: dict[str, Any], research: dict[str, Any]) -> dict[str, Any]:
        health_ns=time.perf_counter_ns()
        ready, reason = self._health()
        trace_emit("qwen.health",{"ready":ready,"reason":reason},start_ns=health_ns)
        if not ready:
            return {"schema":"EZStudioLab_assistant.ai-profile-review.v1","status":"unavailable","provider":"local","model":self.model_path,"confidence":0.0,"summary":None,"dimensions":{},"sources":[],"reason":reason or "local_ai_server_unavailable"}
        profile_view = (((row.get("diagnostics") or {}).get("profile_view")) if isinstance(row.get("diagnostics"),dict) else {}) or {}
        ev_ns=time.perf_counter_ns()
        evidence = _local_profile_evidence(row, research)
        trace_emit("qwen.evidence.build",start_ns=ev_ns)
        trace_snap("qwen.evidence",evidence)
        preset, preset_hash = load_review_preset("profile")
        trace_snap("qwen.preset",{"id":preset.get("id"),"sha256":preset_hash,"phase":preset.get("phase"),"settings":{k:v for k,v in preset.items() if k!="prompt"}})
        # Phase-specific generation constraints belong to the MAIN preset.
        system_prompt = preset["prompt"] + "\n" + preset["output_contract"]
        try:
            budget_ns=time.perf_counter_ns()
            evidence_json = _local_profile_budget(evidence, system_prompt, max_chars=preset["context_max_chars"])
            trace_emit("qwen.context_budget",{"original_chars":len(json.dumps(evidence,ensure_ascii=False)),"sent_chars":len(evidence_json),"max_chars":preset["context_max_chars"]},start_ns=budget_ns)
            trace_snap("qwen.evidence.sent",json.loads(evidence_json))
        except ValueError as exc:
            return {"schema":"EZStudioLab_assistant.ai-profile-review.v1","status":"error","provider":"local","model":self.model_path,"confidence":0.0,"summary":None,"dimensions":{},"sources":[],"reason":str(exc)}
        body = {"model":"local","messages":[{"role":"system","content":system_prompt},{"role":"user","content":evidence_json}],"temperature":preset["temperature"],"max_tokens":preset["max_tokens"],"stream":False,"response_format":{"type":"json_object"}}
        req = urllib.request.Request(self.endpoint + "/v1/chat/completions", data=json.dumps(body,ensure_ascii=False).encode("utf-8"), headers={"Content-Type":"application/json","Accept":"application/json"}, method="POST")
        trace_snap("qwen.http.request",{"url":self.endpoint + "/v1/chat/completions","headers":dict(req.header_items()),"body":body})
        request_ns=time.perf_counter_ns()
        try:
            with urllib.request.urlopen(req, timeout=max(10.0,float(self.config.timeout_seconds))) as response:
                raw_response=response.read(2_000_000).decode("utf-8")
                trace_snap("qwen.http.response",{"status":response.status,"headers":dict(response.headers),"body_raw":raw_response})
                trace_emit("qwen.http.duration",start_ns=request_ns)
                parse_ns=time.perf_counter_ns()
                envelope = json.loads(raw_response)
                trace_emit("qwen.envelope.parse",start_ns=parse_ns)
            choices = envelope.get("choices") if isinstance(envelope,dict) else None
            if not isinstance(choices,list) or not choices: raise ValueError("local_ai_choices_missing")
            finish_reason = choices[0].get("finish_reason")
            if finish_reason == "length":
                raise ValueError("local_ai_output_truncated")
            message = choices[0].get("message") if isinstance(choices[0],dict) else None
            content = message.get("content") if isinstance(message,dict) else None
            if not isinstance(content,str) or not content.strip(): raise ValueError("local_ai_content_missing")
            parse_ns=time.perf_counter_ns()
            value = self._decode_local_json(content)
            trace_emit("qwen.content.parse",start_ns=parse_ns)
            trace_snap("qwen.content.decoded",value)
            validate_ns=time.perf_counter_ns()
            result = self._validate_local_result(value, model=self.model_path, sent_evidence=json.loads(evidence_json), normalization=preset['dimension_normalization'])
            trace_emit("qwen.validate.duration",start_ns=validate_ns)
            trace_snap("qwen.validated",result)
            result["review_preset"] = {"id": preset["id"], "phase": preset["phase"], "sha256": preset_hash}
            return result
        except urllib.error.HTTPError as exc:
            trace_emit("qwen.http.error",{"status":exc.code,"body":exc.read().decode("utf-8","replace")},start_ns=request_ns)
            return {"schema":"EZStudioLab_assistant.ai-profile-review.v1","status":"error","provider":"local","model":self.model_path,"confidence":0.0,"summary":None,"dimensions":{},"sources":[],"reason":("local_ai_http_400_context_possible" if int(getattr(exc,'code',0)) == 400 else f"local_ai_http_{int(getattr(exc,'code',0))}")}
        except Exception as exc:
            trace_emit("qwen.error",{"type":type(exc).__name__,"message":str(exc)},start_ns=request_ns)
            return {"schema":"EZStudioLab_assistant.ai-profile-review.v1","status":"error","provider":"local","model":self.model_path,"confidence":0.0,"summary":None,"dimensions":{},"sources":[],"reason":f"{type(exc).__name__}:{exc}"[:500]}


class OpenAIResponsesProvider(AIReviewProvider):
    provider_name = "openai"

    def __init__(
        self,
        config: AIProviderConfig,
        *,
        api_key: str | None = None,
        endpoint: str = "https://api.openai.com/v1/responses",
    ) -> None:
        self.config = config
        self.api_key = (api_key or os.getenv("OPENAI_API_KEY") or "").strip()
        self.endpoint = endpoint

    def status(self) -> dict[str, Any]:
        return {
            "provider": "openai",
            "enabled": bool(self.api_key),
            "model": self.config.model,
            "web_search": bool(self.config.web_search),
            "api_key_configured": bool(self.api_key),
        }

    @staticmethod
    def _extract_output_text(payload: dict[str, Any]) -> str:
        direct = payload.get("output_text")
        if isinstance(direct, str) and direct.strip():
            return direct.strip()
        parts: list[str] = []
        for item in payload.get("output") or []:
            if not isinstance(item, dict):
                continue
            for content in item.get("content") or []:
                if not isinstance(content, dict):
                    continue
                if content.get("type") == "output_text":
                    value = content.get("text")
                    if isinstance(value, str) and value.strip():
                        parts.append(value.strip())
        return "\n".join(parts).strip()

    @staticmethod
    def _validate_result(
        value: Any,
        *,
        model: str,
        response_id: str | None,
        usage: Any,
    ) -> dict[str, Any]:
        if not isinstance(value, dict):
            raise ValueError("ai_profile_review_json_object_required")
        confidence = value.get("confidence", 0.0)
        if not isinstance(confidence, (int, float)):
            raise ValueError("ai_profile_review_confidence_required")
        confidence = float(confidence)
        if not 0.0 <= confidence <= 1.0:
            raise ValueError("ai_profile_review_confidence_out_of_range")
        dimensions = value.get("dimensions")
        if not isinstance(dimensions, dict):
            raise ValueError("ai_profile_review_dimensions_object_required")
        summary = value.get("summary")
        if summary is not None and not isinstance(summary, str):
            raise ValueError("ai_profile_review_summary_string_required")
        sources = value.get("sources")
        if not isinstance(sources, list):
            sources = []
        return {
            "schema": "EZStudioLab_assistant.ai-profile-review.v1",
            "status": "completed",
            "provider": "openai",
            "model": model,
            "confidence": round(confidence, 3),
            "summary": summary.strip() if isinstance(summary, str) else None,
            "dimensions": dimensions,
            "sources": [x for x in sources if isinstance(x, dict)][:20],
            "response_id": response_id,
            "usage": usage if isinstance(usage, dict) else {},
        }

    def review_profile(self, row: dict[str, Any], research: dict[str, Any]) -> dict[str, Any]:
        if not self.api_key:
            return {
                "schema": "EZStudioLab_assistant.ai-profile-review.v1",
                "status": "unavailable",
                "provider": "openai",
                "model": self.config.model,
                "confidence": 0.0,
                "summary": None,
                "dimensions": {},
                "sources": [],
                "reason": "openai_api_key_missing",
            }

        profile_view = (
            ((row.get("diagnostics") or {}).get("profile_view"))
            if isinstance(row.get("diagnostics"), dict)
            else {}
        ) or {}
        evidence = {
            "identity": {
                "title": row.get("title"),
                "artist": row.get("artist"),
                "run_id": row.get("run_id"),
            },
            "profile_view": profile_view,
            "public_research": research,
        }
        instructions = (
            "You are the PROFILE review engine for EZStudioLab Assistant. "
            "Assess the LAB PROFILE analysis against the supplied public evidence. "
            "Never modify or promote a scientific preset. Distinguish evidence from inference. "
            "Return ONLY one valid JSON object with keys: summary (string), confidence (0..1), "
            "dimensions (object), sources (array). dimensions should contain genre, instrumentation, "
            "lead_vocal, backing_vocals and choir when evidence permits. Each dimension should use "
            "decision, confidence, evidence and rationale. Use decision values match, mismatch, "
            "uncertain, or not_scored. Do not invent facts or sources."
        )
        request_payload: dict[str, Any] = {
            "model": self.config.model,
            "instructions": instructions,
            "input": json.dumps(evidence, ensure_ascii=False, separators=(",", ":")),
        }
        if self.config.web_search:
            request_payload["tools"] = [{"type": "web_search"}]

        req = urllib.request.Request(
            self.endpoint,
            data=json.dumps(request_payload, ensure_ascii=False).encode("utf-8"),
            headers={
                "Authorization": f"Bearer {self.api_key}",
                "Content-Type": "application/json",
                "Accept": "application/json",
            },
            method="POST",
        )
        try:
            with urllib.request.urlopen(
                req,
                timeout=max(10.0, float(self.config.timeout_seconds)),
            ) as response:
                body = response.read(2_000_000)
            envelope = json.loads(body.decode("utf-8"))
            if not isinstance(envelope, dict):
                raise ValueError("openai_response_object_required")
            text = self._extract_output_text(envelope)
            if not text:
                raise ValueError("openai_response_output_text_missing")
            value = json.loads(text)
            return self._validate_result(
                value,
                model=self.config.model,
                response_id=str(envelope.get("id")) if envelope.get("id") is not None else None,
                usage=envelope.get("usage"),
            )
        except urllib.error.HTTPError as exc:
            detail = ""
            try:
                detail = exc.read(4096).decode("utf-8", errors="replace")
            except Exception:
                pass
            return {
                "schema": "EZStudioLab_assistant.ai-profile-review.v1",
                "status": "error",
                "provider": "openai",
                "model": self.config.model,
                "confidence": 0.0,
                "summary": None,
                "dimensions": {},
                "sources": [],
                "reason": f"openai_http_{int(getattr(exc, 'code', 0))}",
                "detail": detail[:1000],
            }
        except Exception as exc:
            return {
                "schema": "EZStudioLab_assistant.ai-profile-review.v1",
                "status": "error",
                "provider": "openai",
                "model": self.config.model,
                "confidence": 0.0,
                "summary": None,
                "dimensions": {},
                "sources": [],
                "reason": f"{type(exc).__name__}:{exc}"[:500],
            }


def _env_bool(name: str, default: bool = False) -> bool:
    raw = str(os.getenv(name, "")).strip().casefold()
    if not raw:
        return bool(default)
    return raw in {"1", "true", "yes", "on"}


def _model_relative_path(root: str, model: str):
    from pathlib import PureWindowsPath
    rt, mt = str(root).strip(), str(model).strip()
    if rt.startswith(chr(92) * 2) or mt.startswith(chr(92) * 2) or rt.startswith("//") or mt.startswith("//"):
        raise RuntimeError("local_ai_smb_forbidden")
    if not PureWindowsPath(rt).is_absolute() or not PureWindowsPath(mt).is_absolute():
        raise RuntimeError("local_ai_absolute_windows_path_required")
    if ".." in PureWindowsPath(mt).parts:
        raise RuntimeError("local_ai_parent_traversal_forbidden")
    if rt.startswith("\\\\") or "\\" in rt or ":" in rt:
        rp, mp = PureWindowsPath(rt), PureWindowsPath(mt)
    else:
        rp, mp = Path(rt), Path(mt)
    try:
        return mp.relative_to(rp)
    except Exception as exc:
        raise RuntimeError("local_ai_model_must_be_under_shared_model_root") from exc

def _is_model_under_root(root: str, model: str) -> bool:
    try:
        _model_relative_path(root, model); return True
    except Exception:
        return False


def _ezs_local_model_config():
    # Persistent Assistant configuration, NOT a model/cache directory.
    from pathlib import PureWindowsPath
    settings_path = Path(__file__).resolve().parents[1] / 'runtime' / 'state' / 'local-model.json'
    try:
        cfg = json.loads(settings_path.read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return {}
    if not isinstance(cfg, dict) or cfg.get('schema') != 'ezs.assistant.model-location.v1':
        return {}
    root = str(cfg.get('root') or '')
    model = str(cfg.get('model') or '')
    rp, mp = PureWindowsPath(root), PureWindowsPath(model)
    if not rp.is_absolute() or not mp.is_absolute() or str(rp).startswith(chr(92)*2) or str(mp).startswith(chr(92)*2) or '..' in mp.parts:
        return {}
    try:
        if not mp.relative_to(rp).parts or mp.suffix.lower() != '.gguf':
            return {}
    except ValueError:
        return {}
    return {'root': root, 'model': model}

def _load_platform_dotenv() -> None:
    root = Path(__file__).resolve().parents[3]
    for name in (".env", ".env.local"):
        env_file = root / name
        if not env_file.is_file():
            continue
        for raw in env_file.read_text(encoding="utf-8", errors="ignore").splitlines():
            line = raw.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            k = k.strip()
            v = v.strip().strip('"').strip("'")
            if k and k not in os.environ:
                os.environ[k] = v

_load_platform_dotenv()
def config_from_env() -> AIProviderConfig:
    local_settings = _ezs_local_model_config()
    provider = str(os.getenv("EZSTUDIOLAB_AI_PROVIDER", "off")).strip().casefold()
    if provider not in {"off", "openai", "local"}:
        raise RuntimeError(f"unsupported_ai_provider:{provider}")
    model = str(os.getenv("EZSTUDIOLAB_OPENAI_MODEL", DEFAULT_OPENAI_MODEL)).strip() or DEFAULT_OPENAI_MODEL
    try:
        timeout = float(os.getenv("EZSTUDIOLAB_AI_TIMEOUT_SECONDS", "60"))
    except ValueError:
        timeout = 60.0
    return AIProviderConfig(
        provider=provider,
        model=model,
        web_search=_env_bool("EZSTUDIOLAB_OPENAI_WEB_SEARCH", False),
        timeout_seconds=max(10.0, min(180.0, timeout)),
        shared_model_root=(
            str(os.getenv("EZSTUDIO_SHARED_MODEL_ROOT", local_settings.get("root", DEFAULT_SHARED_MODEL_ROOT))).strip()
            or DEFAULT_SHARED_MODEL_ROOT
        ),
        local_model_path=(str(os.getenv("EZSTUDIOLAB_LOCAL_AI_MODEL", local_settings.get("model", ""))).strip() or None),
        local_cache_root=(str(os.getenv("EZSTUDIOLAB_LOCAL_AI_CACHE_ROOT", "")).strip() or None),
        local_endpoint=(
            str(os.getenv("EZSTUDIOLAB_LOCAL_AI_ENDPOINT", "http://127.0.0.1:8720")).strip()
            or "http://127.0.0.1:8720"
        ),
    )


def build_ai_provider_from_env() -> AIReviewProvider:
    config = config_from_env()
    if config.provider == "off":
        return DisabledAIProvider()
    if config.provider == "openai":
        return OpenAIResponsesProvider(config)
    if not config.local_model_path:
        return DisabledAIProviderWithReason("local_ai_model_path_required", provider="local")
    try:
        return LocalAIProvider(config)
    except Exception as exc:
        return DisabledAIProviderWithReason(f"{type(exc).__name__}:{exc}", provider="local")


def ai_provider_status() -> dict[str, Any]:
    provider = build_ai_provider_from_env()
    status = provider.status()
    status["schema"] = "EZStudioLab_assistant.ai-provider-status.v1"
    status["shared_model_root"] = config_from_env().shared_model_root
    return status
