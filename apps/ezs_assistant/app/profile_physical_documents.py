"""Discovery-only physical sleeve/booklet evidence (no image interpretation).

Cover Art Archive indexes scans per release; an image existence is never a
musician credit or proof for an individual recording.
"""
from __future__ import annotations
import re
from urllib.parse import urlparse

UUID = re.compile(r"^[0-9a-fA-F]{8}-(?:[0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}$")
DOCUMENT_TYPES = {"back", "booklet", "liner", "medium", "spine", "tray", "obi",
                  "inner", "other", "sticker"}


def _safe_url(value):
    if not isinstance(value, str):
        return None
    u = urlparse(value)
    if u.scheme != "https" or u.username or u.password or not u.hostname:
        return None
    if u.hostname.lower() not in {"coverartarchive.org", "archive.org",
                                   "www.archive.org", "ia801000.us.archive.org"}:
        # Metadata occasionally carries different archive.org CDN subdomains.
        if not u.hostname.lower().endswith(".archive.org"):
            return None
    return value


def discover_release_documents(http, release_ids):
    """Return reviewable document links, never extracted musical assertions.

    Bounded to one eligible edition and one network call. Never downloads images.
    No cover-art document is allowed to assert track-specific personnel until
    a later, independently tested extraction proves the credit and its scope.
    """
    result = []
    seen = set()
    for raw in release_ids:
        release_id = str(raw or "").strip()
        if not UUID.fullmatch(release_id) or release_id in seen:
            continue
        seen.add(release_id)
        url = "https://coverartarchive.org/release/" + release_id
        if http.request_count >= http.budget.max_requests_per_item:
            break
        try:
            data = http.get_json(url, retries=0)
        except Exception:
            continue
        if not isinstance(data, dict):
            continue
        for image in (data.get("images") or [])[:24]:
            if not isinstance(image, dict) or image.get("approved") is not True:
                continue
            types = [str(t).casefold() for t in image.get("types") or []]
            if not (set(types) & DOCUMENT_TYPES or image.get("back") is True):
                continue
            document_url = _safe_url(image.get("image"))
            if not document_url:
                continue
            result.append({
                "release_id": release_id, "document_url": document_url,
                "types": types, "scope": "release_unverified",
                "credit_text": None, "track_attribution": None,
                "evidence_eligible": False,
            })
        break
    return result[:16]

# Candidate extraction is separate from evidence validation. Unverified OCR must
# never be promoted directly to BON/FAUX in profile_final_verifier.py.
def extract_printed_credit_candidates(text, track_title):
    """Extract candidate performer:instrument lines only inside an explicit track section.

    The scan must itself include the exact track heading. Album-wide personnel
    credits are not recording-specific and are intentionally disregarded.
    """
    import unicodedata
    def norm(value):
        value = unicodedata.normalize("NFKD", str(value or "").casefold())
        value = "".join(c for c in value if not unicodedata.combining(c))
        return re.sub(r"[^a-z0-9]+", " ", value).strip()

    title = norm(track_title)
    if len(title) < 5:
        return []
    lines = [x.strip() for x in str(text or "").splitlines()][:300]
    headings = [i for i, line in enumerate(lines) if norm(line) == title]
    if len(headings) != 1:
        return []
    start = headings[0] + 1
    candidates = []
    # A short block is not a license to inherit credits from the next song.
    for raw in lines[start:start + 12]:
        if not raw:
            break
        match = re.fullmatch(r"([A-Za-zÀ-ÿ.' -]{3,70})\s*[-:–]\s*([A-Za-zÀ-ÿ, /() -]{3,90})", raw)
        if match is None:
            break
        role = match.group(2).strip()
        if not re.search(r"(?i)guitar|guitare|bass|basse|piano|keyboard|clavier|drum|batterie|"
                         r"vocal|chant|voice|voix|organ|orgue|violin|violon|sax|flute|flûte", role):
            break
        candidates.append({"musician": match.group(1).strip(), "role_text": role,
                           "raw_line": raw, "scope": "track_candidate_unverified",
                           "evidence_eligible": False})
    return candidates[:12]



def diagnose_ocr_text(text, track_title, candidates):
    """Metadata-only review; never persist raw OCR content or infer personnel."""
    import unicodedata
    def normalize(value):
        value=unicodedata.normalize("NFKD",str(value or "").casefold())
        return re.sub(r"[^a-z0-9]+"," ","".join(c for c in value if not unicodedata.combining(c))).strip()
    lines=[line.strip() for line in str(text or "").splitlines() if line.strip()]
    title=normalize(track_title)
    exact=sum(normalize(line)==title for line in lines) if title else 0
    partial=sum(title in normalize(line) and normalize(line)!=title for line in lines) if len(title)>=5 else 0
    if not lines:
        reason="ocr_empty_text"
    elif exact==0:
        reason="exact_track_heading_absent"
    elif exact>1:
        reason="ambiguous_track_heading"
    elif candidates:
        reason="track_scoped_candidates_unverified"
    else:
        reason="no_supported_credit_lines_after_heading"
    return {"characters":len(str(text or "")), "nonempty_lines":len(lines),
            "exact_track_headings":exact, "partial_track_mentions":partial,
            "candidate_count":len(candidates), "rejection_reason":reason,
            "raw_text_persisted":False, "evidence_eligible":False}


def inspect_scans_with_local_ocr(documents, track_title, *, max_scans=2):
    """Optional bounded OCR. Never caches scans; never asserts musical facts.

    Requires installed Tesseract and EZS_PROFILE_BOOKLET_OCR=1. Downloads
    transient files to temp and deletes them on both success and failure.
    """
    import os
    import subprocess
    from profile_tesseract_runtime import resolve_tesseract, booklet_ocr_enabled
    import tempfile
    import urllib.request

    if not booklet_ocr_enabled():
        return {"status": "disabled", "scans": []}
    runtime = resolve_tesseract()
    if runtime["status"] != "ready":
        return {"status": runtime["status"], "scans": []}
    executable = runtime["executable"]
    scanned = []
    for doc in list(documents or [])[:max(0, min(2, max_scans))]:
        url = _safe_url(doc.get("document_url")) if isinstance(doc, dict) else None
        if not url:
            continue
        record = {"document_url": url, "release_id": doc.get("release_id"),
                  "status": "unreadable", "credit_candidates": [],
                  "evidence_eligible": False}
        try:
            request = urllib.request.Request(url, headers={"User-Agent": "EZStudioLab_assistant/1.6 (physical credit reading)"})
            with urllib.request.urlopen(request, timeout=10) as response:
                final_url = _safe_url(response.geturl())
                content_type = str(response.headers.get("Content-Type") or "").lower()
                if not final_url or not content_type.startswith(("image/jpeg", "image/png", "image/tiff")):
                    raise ValueError("non_image_or_untrusted_redirect")
                if int(response.headers.get("Content-Length") or 0) > 5_000_000:
                    raise ValueError("oversized_scan")
                data = response.read(5_000_001)
                if len(data) > 5_000_000:
                    raise ValueError("oversized_scan")
            with tempfile.TemporaryDirectory(prefix="ezs_booklet_") as folder:
                scan_path = os.path.join(folder, "scan")
                with open(scan_path, "wb") as output:
                    output.write(data)
                completed = subprocess.run(
                    [executable, scan_path, "stdout", "-l", "eng+fra", "--tessdata-dir", runtime["tessdata_dir"]],
                    capture_output=True, timeout=18, check=False)
                if completed.returncode != 0:
                    raise ValueError("ocr_failed")
                text = completed.stdout.decode("utf-8", "replace")
            record["credit_candidates"] = extract_printed_credit_candidates(text, track_title)
            record["status"] = "candidates_unverified" if record["credit_candidates"] else "no_track_scoped_credits"
            record["ocr_diagnostic"] = diagnose_ocr_text(text, track_title, record["credit_candidates"])
        except (OSError, ValueError, subprocess.TimeoutExpired) as exc:
            record["status"] = type(exc).__name__
        scanned.append(record)
    return {"status": "completed", "scans": scanned}
