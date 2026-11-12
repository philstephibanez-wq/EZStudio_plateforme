from __future__ import annotations

import argparse
import json
import os
from pathlib import Path



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

    checkpoint = Path(args.checkpoint)
    os.environ["TORCH_HOME"] = str(Path(args.torch_home).resolve())

    import torch
    import torchaudio
    _install_direct_checkpoint_resolver(checkpoint)
    import hear21passt
    from hear21passt.base import load_model

    if not torch.cuda.is_available():
        raise RuntimeError("passt_isolated_cuda_required")

    raw = Path(args.audio_f32).read_bytes()
    audio = torch.frombuffer(bytearray(raw), dtype=torch.float32).clone()

    model = load_model(mode="logits").to("cuda")
    model.eval()

    chunks = _chunks(audio, 32000)
    batch_size = max(1, int(os.getenv("EZSTUDIO_PASST_BATCH_SIZE", "2")))
    rows = []
    with torch.inference_mode():
        for start in range(0, len(chunks), batch_size):
            batch = torch.stack(
                chunks[start:start + batch_size],
                dim=0,
            ).to("cuda", non_blocking=True)
            logits = model(batch)
            probs = torch.sigmoid(logits).detach().float().cpu()
            rows.extend(list(probs.unbind(0)))

    if not rows:
        raise RuntimeError("passt_no_chunks")

    scores = torch.stack(rows, dim=0).mean(dim=0)

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
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
