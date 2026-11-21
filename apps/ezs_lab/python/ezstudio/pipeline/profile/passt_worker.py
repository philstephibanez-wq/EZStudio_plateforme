from __future__ import annotations

import argparse
import json
import os
import time
from datetime import datetime, timezone
from pathlib import Path




def _l1b_mark(phase: str, *, torch_module=None) -> None:
    """Best-effort local child-process phase telemetry; no scientific payload."""
    try:
        root = Path(os.getenv("EZSTUDIO_OBSERVABILITY_ROOT", str(Path(__file__).resolve().parents[4] / "var" / "logs" / "observability")))
        session = os.getenv("EZSTUDIO_PROFILE_OBSERVABILITY_SESSION", "profile")
        if not session.startswith("run-") or not session[4:].isdigit():
            return
        path = root / "profile" / "phase-events" / session / "phases.jsonl"
        data = {"schema":"ezstudio.observability.profile-phase.v1", "module":"passt_child", "phase":phase,
                "utc":datetime.now(timezone.utc).isoformat(), "monotonic_ns":time.perf_counter_ns(), "pid":os.getpid()}
        if torch_module is not None and torch_module.cuda.is_available():
            idx = torch_module.cuda.current_device()
            data["cuda_device_index"] = int(idx)
            data["cuda_device_name"] = str(torch_module.cuda.get_device_name(idx))
            data["cuda_allocated_mib"] = round(torch_module.cuda.memory_allocated(idx) / (1024*1024), 2)
        path.parent.mkdir(parents=True, exist_ok=True)
        line = (json.dumps(data, ensure_ascii=False, separators=(",", ":")) + "\n").encode("utf-8")
        fd = os.open(str(path), os.O_CREAT | os.O_APPEND | os.O_WRONLY, 0o600)
        try:
            os.write(fd, line)
        finally:
            os.close(fd)
    except Exception:
        pass


def _install_direct_checkpoint_resolver(checkpoint: Path) -> None:
    if not checkpoint.is_file():
        raise RuntimeError(f"passt_checkpoint_missing:{checkpoint}")

    expected_name = checkpoint.name

    def patch_module(module) -> None:
        original = getattr(module, "download_cached_file", None)
        if original is None:
            return

        def direct_download_cached_file(
            url,
            check_hash=True,
            progress=False,
        ):
            if Path(url).name == expected_name:
                return str(checkpoint)
            return original(
                url,
                check_hash=check_hash,
                progress=progress,
            )

        module.download_cached_file = direct_download_cached_file

    try:
        import timm.models._hub as timm_private_hub
    except ModuleNotFoundError:
        timm_private_hub = None

    import timm.models.hub as timm_hub

    if timm_private_hub is not None:
        patch_module(timm_private_hub)
    patch_module(timm_hub)


def _chunks(audio, sr: int, seconds: float = 10.0, max_chunks: int = 8):
    import torch

    length = max(1, int(round(seconds * sr)))
    total = int(audio.numel())
    if total <= length:
        padded = torch.zeros(length, dtype=torch.float32)
        padded[:total] = audio
        return [padded]

    max_start = total - length
    if max_chunks <= 1:
        starts = [0]
    else:
        starts = [
            int(round(i * max_start / float(max_chunks - 1)))
            for i in range(max_chunks)
        ]
    return [audio[start:start + length] for start in starts]


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--audio-f32", required=True)
    parser.add_argument("--checkpoint", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--torch-home", required=True)
    args = parser.parse_args()

    _l1b_mark("start")
    checkpoint = Path(args.checkpoint)
    os.environ["TORCH_HOME"] = str(Path(args.torch_home).resolve())

    import torch
    import torchaudio
    _install_direct_checkpoint_resolver(checkpoint)
    import hear21passt
    from hear21passt.base import load_model
    _l1b_mark("imports_done")

    _l1b_mark("cuda_check_start")
    if not torch.cuda.is_available():
        raise RuntimeError("passt_isolated_cuda_required")

    _l1b_mark("cuda_verified", torch_module=torch)
    raw = Path(args.audio_f32).read_bytes()
    audio = torch.frombuffer(bytearray(raw), dtype=torch.float32).clone()

    _l1b_mark("audio_loaded")
    _l1b_mark("model_start")
    model = load_model(mode="logits").to("cuda")
    _l1b_mark("model_done", torch_module=torch)
    model.eval()

    chunks = _chunks(audio, 32000)
    batch_size = max(1, int(os.getenv("EZSTUDIO_PASST_BATCH_SIZE", "2")))
    rows = []
    _l1b_mark("inference_start", torch_module=torch)
    with torch.inference_mode():
        for start in range(0, len(chunks), batch_size):
            batch = torch.stack(
                chunks[start:start + batch_size],
                dim=0,
            ).to("cuda", non_blocking=True)
            logits = model(batch)
            probs = torch.sigmoid(logits).detach().float().cpu()
            rows.extend(list(probs.unbind(0)))

    _l1b_mark("inference_done", torch_module=torch)
    if not rows:
        raise RuntimeError("passt_no_chunks")

    scores = torch.stack(rows, dim=0).mean(dim=0)

    _l1b_mark("aggregation_done")
    result = {
        "scores": [float(value) for value in scores.tolist()],
        "per_chunk_scores": [
            [float(value) for value in row.tolist()]
            for row in rows
        ],
        "chunk_count": len(rows),
        "chunk_seconds": 10.0,
        "torch": torch.__version__,
        "torchaudio": torchaudio.__version__,
        "hear21passt": getattr(hear21passt, "__version__", None),
        "cuda": torch.version.cuda,
        "device_name": torch.cuda.get_device_name(0),
    }

    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    tmp = output.with_suffix(".tmp")
    tmp.write_text(
        json.dumps(result, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    tmp.replace(output)
    _l1b_mark("result_written", torch_module=torch)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
