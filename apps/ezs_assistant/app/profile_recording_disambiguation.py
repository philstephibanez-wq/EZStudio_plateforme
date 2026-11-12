"""Strict MusicBrainz recording candidate disambiguation by catalog audio duration.

No musical inference, no preference for recordings with more credits.
"""
from __future__ import annotations
import math

def duration_from_catalog(row):
    """Only declared, non-scientific duration fields; refuse inconsistent metadata."""
    values=[]
    metrics=row.get("metrics") if isinstance(row.get("metrics"),dict) else {}
    for field in ("duration_seconds",):
        value=metrics.get(field)
        if isinstance(value,(int,float)) and not isinstance(value,bool) and math.isfinite(value):
            ms=int(round(value*1000))
            if 30_000 <= ms <= 3_600_000:
                values.append(ms)
    for field,scale in (("audio_duration_ms",1),("duration_ms",1),("audio_duration_seconds",1000),("duration_seconds",1000)):
        value=row.get(field)
        if isinstance(value,(int,float)) and not isinstance(value,bool) and math.isfinite(value):
            ms=int(round(value*scale))
            if 30_000 <= ms <= 3_600_000:
                values.append(ms)
    if not values or max(values)-min(values)>1000:
        return None
    return round(sum(values)/len(values))

def choose_candidate(eligible, duration_ms, *, tolerance_ms=1500, min_margin_ms=5000):
    """Choose only a uniquely close MB recording; otherwise abstain with diagnostics."""
    if len(eligible)==1:
        return {"status":"unique","candidate":eligible[0],"matching":1}
    if not isinstance(duration_ms,int):
        return {"status":"duration_unavailable","matching":len(eligible)}
    matches=[]
    for candidate in eligible:
        length=candidate[1].get("length")
        if isinstance(length,(int,float)) and not isinstance(length,bool) and math.isfinite(length) and 30_000<=length<=3_600_000:
            diff=abs(round(length)-duration_ms)
            matches.append((diff,candidate))
    matches.sort(key=lambda pair:pair[0])
    if not matches or matches[0][0]>tolerance_ms:
        return {"status":"no_duration_match","matching":len(matches)}
    # Missing durations in competing candidates are unresolved alternatives.
    if len(matches)!=len(eligible):
        return {"status":"incomplete_candidate_durations","matching":len(matches)}
    if len(matches)>1 and matches[1][0]-matches[0][0]<min_margin_ms:
        return {"status":"insufficient_duration_margin","matching":len(matches)}
    return {"status":"duration_match","candidate":matches[0][1],"difference_ms":matches[0][0],"matching":len(matches)}
