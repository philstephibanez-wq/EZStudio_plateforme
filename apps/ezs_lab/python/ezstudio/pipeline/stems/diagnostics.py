from __future__ import annotations
import argparse, json
from pathlib import Path
import librosa
import numpy as np

STEMS=("lead_vocals","backing_vocals","drums","bass","guitar","piano","other")

def metrics(path: Path)->dict:
    y,sr=librosa.load(path,sr=None,mono=True)
    return {
        "file":path.name,
        "sample_rate":int(sr),
        "duration_seconds":float(len(y)/sr) if sr else 0.0,
        "rms":float(np.sqrt(np.mean(np.square(y),dtype=np.float64))) if len(y) else 0.0,
        "peak":float(np.max(np.abs(y))) if len(y) else 0.0,
        "silence_ratio":float(np.mean(np.abs(y)<1e-4)) if len(y) else 1.0,
    }

def main()->int:
    p=argparse.ArgumentParser(); p.add_argument("--storage-root",required=True); a=p.parse_args()
    root=Path(a.storage_root); current=json.loads((root/"current.json").read_text(encoding="utf-8"))
    run=str(current["run"]); run_dir=root/"runs"/run
    tracks={}
    for name in STEMS:
        path=run_dir/f"{name}.wav"
        if not path.is_file(): raise RuntimeError(f"missing_stem:{name}")
        tracks[name]=metrics(path)
    payload={"schema":"ezstudio.stems.diagnostics","schema_version":1,"run":run,"tracks":tracks}
    (run_dir/"diagnostics.json").write_text(json.dumps(payload,ensure_ascii=False,indent=2)+"\n",encoding="utf-8")
    print("EZSTUDIO_STEMS_DIAGNOSTICS_OK"); return 0

if __name__=="__main__": raise SystemExit(main())
