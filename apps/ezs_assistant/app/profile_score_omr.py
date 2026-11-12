"""Windows Audiveris OMR adapter for a caller-supplied local score image.

Nothing is retained: Audiveris input/output live in a TemporaryDirectory.
The extracted score data is unverified, never an audio-recording verdict.
"""
from __future__ import annotations
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET
import zipfile

ACCEPTED={".png",".jpg",".jpeg",".tif",".tiff",".pdf"}
def resolve_audiveris():
    configured=os.environ.get("EZS_AUDIVERIS_BAT")
    candidates=[configured] if configured else [
        shutil.which("Audiveris.bat"),
        r"C:\Program Files\Audiveris\bin\Audiveris.bat",
        r"C:\Program Files\Audiveris\bin\audiveris.bat"]
    for candidate in candidates:
        if candidate and Path(candidate).is_file():
            return {"status":"ready","executable":str(candidate)}
    return {"status":"executable_missing"}

def _xml_facts(raw):
    root=ET.fromstring(raw)
    def name(el):
        return el.tag.rsplit("}",1)[-1]
    facts={"composers":[],"part_names":[],"key_signatures":[],"time_signatures":[],
           "tempo_bpm":[],"work_titles":[],"status":"parsed_unverified",
           "scope":"score_not_recording"}
    for item in root.iter():
        kind=name(item)
        value=(item.text or "").strip()
        if kind=="work-title" and value and value not in facts["work_titles"]:
            facts["work_titles"].append(value[:160])
        elif kind=="creator" and value and item.get("type")=="composer":
            if value not in facts["composers"]:facts["composers"].append(value[:160])
        elif kind=="part-name" and value and value not in facts["part_names"]:
            facts["part_names"].append(value[:100])
        elif kind=="fifths" and value.lstrip("-").isdigit() and value not in facts["key_signatures"]:
            facts["key_signatures"].append(value)
        elif kind=="time":
            beats=next((x.text for x in item if name(x)=="beats"),None)
            beat_type=next((x.text for x in item if name(x)=="beat-type"),None)
            if beats and beat_type:
                signature=str(beats)+"/"+str(beat_type)
                if signature not in facts["time_signatures"]:facts["time_signatures"].append(signature)
        elif kind=="sound":
            tempo=item.get("tempo")
            if tempo:
                try:
                    number=float(tempo)
                    if 15<=number<=350 and number not in facts["tempo_bpm"]:facts["tempo_bpm"].append(number)
                except ValueError:pass
    return facts

def analyze_local_score(path,*,timeout_seconds=100):
    score=Path(path)
    if score.suffix.lower() not in ACCEPTED or not score.is_file():
        return {"status":"invalid_input"}
    if score.stat().st_size>8_000_000:
        return {"status":"too_large"}
    runtime=resolve_audiveris()
    if runtime["status"]!="ready":return runtime
    with tempfile.TemporaryDirectory(prefix="ezs_omr_") as folder:
        source=Path(folder)/("score"+score.suffix.lower())
        shutil.copyfile(score,source)
        output=Path(folder)/"result"
        output.mkdir()
        try:
            done=subprocess.run(
                [runtime["executable"],"-batch","-export","-output",str(output),"--",str(source)],
                capture_output=True,text=True,errors="replace",timeout=timeout_seconds,
                check=False,cwd=folder)
        except (OSError,subprocess.TimeoutExpired) as exc:
            return {"status":"execution_error","reason":type(exc).__name__}
        if done.returncode!=0:
            return {"status":"omr_failed","exit_code":done.returncode}
        candidates=list(output.rglob("*.mxl"))+list(output.rglob("*.musicxml"))
        if not candidates:return {"status":"no_musicxml"}
        facts=[]
        for file in candidates[:3]:
            try:
                if file.suffix==".mxl":
                    with zipfile.ZipFile(file) as archive:
                        names=[n for n in archive.namelist() if n.lower().endswith((".xml",".musicxml")) and not n.startswith("META-INF/")]
                        if not names:continue
                        info=archive.getinfo(names[0])
                        if info.file_size>2_000_000:continue
                        xml=archive.read(names[0])
                else:
                    if file.stat().st_size>2_000_000:continue
                    xml=file.read_bytes()
                facts.append(_xml_facts(xml))
            except (ValueError,OSError,ET.ParseError,zipfile.BadZipFile,KeyError):
                continue
        return {"status":"parsed_unverified" if facts else "unreadable_musicxml",
                "scores":facts,"source_type":"local_score_image",
                "evidence_eligible_for_lab":False}
