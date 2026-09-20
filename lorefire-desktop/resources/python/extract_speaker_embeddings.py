#!/usr/bin/env python3
"""
extract_speaker_embeddings.py — campaign-persistent voiceprints.

Reads a session recording (and optional WhisperX transcript with SPEAKER_N
labels) and writes one embedding per speaker. Uses the same Hugging Face
token / pyannote stack as run_whisperx.py diarization.

Windows ARM never depends on this script succeeding. Exit 0 with an empty
speakers map when the embedding model is unavailable so Laravel can skip
auto-labeling instead of failing transcription.

Usage:
  python extract_speaker_embeddings.py \
    --audio /path/to/audio.webm \
    --transcript /path/to/transcript.json \
    --output /path/to/embeddings.json \
    --hf-token <HuggingFace token>
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess
import sys
import tempfile
from collections import defaultdict

_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
if _SCRIPT_DIR not in sys.path:
    sys.path.insert(0, _SCRIPT_DIR)

from lorefire_tmp import cleanup_path, enroll_dir, sweep_stale  # noqa: E402

_cache_base = os.path.join(os.path.expanduser("~"), ".cache")
os.environ.setdefault("HF_HOME", os.path.join(_cache_base, "huggingface"))
os.environ.setdefault("TORCH_HOME", os.path.join(_cache_base, "torch"))
os.environ.setdefault("TORCH_FORCE_NO_WEIGHTS_ONLY_LOAD", "1")

try:
    import certifi

    os.environ.setdefault("SSL_CERT_FILE", certifi.where())
    os.environ.setdefault("REQUESTS_CA_BUNDLE", certifi.where())
    os.environ.setdefault("CURL_CA_BUNDLE", certifi.where())
except ImportError:
    pass


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Extract per-speaker embeddings")
    parser.add_argument("--audio", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--transcript", default=None)
    parser.add_argument("--clips-dir", default=None, dest="clips_dir")
    parser.add_argument("--hf-token", default=None, dest="hf_token")
    parser.add_argument("--min-seconds", type=float, default=0.75, dest="min_seconds")
    return parser.parse_args()


def speaker_ranges(transcript_path: str | None, min_seconds: float) -> dict[str, list[tuple[float, float]]]:
    if not transcript_path or not os.path.isfile(transcript_path):
        return {"enrollment": [(0.0, 0.0)]}

    with open(transcript_path, encoding="utf-8") as handle:
        data = json.load(handle)

    ranges: dict[str, list[tuple[float, float]]] = defaultdict(list)
    for seg in data.get("segments") or []:
        label = seg.get("speaker") or seg.get("speaker_label")
        if not label:
            continue
        start = float(seg.get("start") or 0)
        end = float(seg.get("end") or start)
        if end - start < min_seconds:
            continue
        ranges[str(label)].append((start, end))

    return dict(ranges) if ranges else {"enrollment": [(0.0, 0.0)]}


def write_empty(output: str, reason: str) -> int:
    print(f"[voiceprint] {reason}", file=sys.stderr)
    payload = {"model": None, "speakers": {}, "error": reason}
    output_dir = os.path.dirname(output)
    if output_dir and not os.path.isdir(output_dir):
        os.makedirs(output_dir, exist_ok=True)
    with open(output, "w", encoding="utf-8") as handle:
        json.dump(payload, handle)
    return 0


def ffmpeg_exe() -> str:
    try:
        import imageio_ffmpeg

        return imageio_ffmpeg.get_ffmpeg_exe()
    except Exception:
        return "ffmpeg"


def prepare_wav(audio_path: str) -> tuple[str, str | None]:
    """Decode MediaRecorder webm/ogg (and extensionless PHP temp files) to 16 kHz mono WAV.

    pyannote/torchaudio often cannot load Chromium webm/opus or files with no
    extension. WhisperX already uses ffmpeg for the same reason.
    """
    dest_fd, dest = tempfile.mkstemp(prefix="lorefire-enroll-", suffix=".wav", dir=enroll_dir())
    os.close(dest_fd)
    ffmpeg = ffmpeg_exe()
    cmd = [
        ffmpeg, "-y",
        "-i", audio_path,
        "-ac", "1", "-ar", "16000",
        dest,
    ]
    result = subprocess.run(cmd, capture_output=True)
    if result.returncode != 0 or not os.path.isfile(dest) or os.path.getsize(dest) < 64:
        err = (result.stderr or b"").decode("utf-8", "replace").strip()
        try:
            os.unlink(dest)
        except OSError:
            pass
        snippet = err[-400:] if err else "empty ffmpeg output"
        raise RuntimeError(f"ffmpeg could not decode enrollment audio ({snippet})")
    return dest, dest


def load_inference(token: str | None):
    try:
        from pyannote.audio import Inference
    except Exception as exc:
        raise RuntimeError(f"pyannote.audio Inference unavailable ({exc})") from exc

    models = (
        "pyannote/wespeaker-voxceleb-resnet34-LM",
        "pyannote/embedding",
    )
    last_error = None
    for name in models:
        try:
            kwargs = {"window": "whole"}
            if token:
                try:
                    inference = Inference(name, use_auth_token=token, **kwargs)
                except TypeError:
                    inference = Inference(name, token=token, **kwargs)
            else:
                inference = Inference(name, **kwargs)
            return inference, name
        except Exception as exc:
            last_error = exc
            print(f"[voiceprint] model {name} failed: {exc}", file=sys.stderr)
    raise RuntimeError(f"No embedding model loaded ({last_error})")


def crop_embedding(inference, audio_path: str, start: float, end: float):
    import numpy as np

    if end <= start:
        embedding = inference(audio_path)
    else:
        try:
            from pyannote.core import Segment

            embedding = inference.crop(audio_path, Segment(start, end))
        except Exception:
            embedding = inference(audio_path)
    vector = np.asarray(embedding, dtype=float).reshape(-1)
    return vector


def maybe_write_clip(audio_path: str, dest: str, start: float, end: float) -> None:
    if end <= start:
        return
    try:
        import imageio_ffmpeg
        import subprocess

        ffmpeg = imageio_ffmpeg.get_ffmpeg_exe()
        cmd = [
            ffmpeg, "-y",
            "-ss", f"{start:.3f}",
            "-to", f"{end:.3f}",
            "-i", audio_path,
            "-ac", "1", "-ar", "16000",
            dest,
        ]
        subprocess.run(cmd, capture_output=True, check=False)
    except Exception as exc:
        print(f"[voiceprint] clip write failed: {exc}", file=sys.stderr)


def main() -> int:
    sweep_stale()
    args = parse_args()
    if not os.path.isfile(args.audio):
        return write_empty(args.output, f"audio not found: {args.audio}")

    try:
        import numpy as np
    except Exception as exc:
        return write_empty(args.output, f"numpy unavailable ({exc})")

    wav_path = args.audio
    cleanup_wav = None
    convert_error = None
    try:
        wav_path, cleanup_wav = prepare_wav(args.audio)
    except Exception as exc:
        convert_error = str(exc)
        print(f"[voiceprint] wav convert failed, trying original file: {exc}", file=sys.stderr)
        wav_path = args.audio

    try:
        try:
            inference, model_name = load_inference(args.hf_token)
        except Exception as exc:
            return write_empty(args.output, str(exc))

        ranges = speaker_ranges(args.transcript, args.min_seconds)
        speakers: dict[str, dict] = {}
        errors: list[str] = []

        if args.clips_dir:
            os.makedirs(args.clips_dir, exist_ok=True)

        for label, windows in ranges.items():
            vectors = []
            used_windows = []
            if label == "enrollment" and windows == [(0.0, 0.0)]:
                try:
                    vectors.append(crop_embedding(inference, wav_path, 0.0, 0.0))
                    used_windows.append((0.0, 0.0))
                except Exception as exc:
                    errors.append(f"enrollment embed failed: {exc}")
                    print(f"[voiceprint] enrollment embed failed: {exc}", file=sys.stderr)
            else:
                for start, end in windows:
                    try:
                        vectors.append(crop_embedding(inference, wav_path, start, end))
                        used_windows.append((start, end))
                    except Exception as exc:
                        errors.append(f"crop {label} {start}-{end} failed: {exc}")
                        print(f"[voiceprint] crop {label} {start}-{end} failed: {exc}", file=sys.stderr)

            if not vectors:
                continue

            stacked = np.vstack(vectors)
            mean = stacked.mean(axis=0)
            norm = float(np.linalg.norm(mean))
            if norm > 0:
                mean = mean / norm

            clip_path = None
            if args.clips_dir and used_windows:
                longest = max(used_windows, key=lambda pair: pair[1] - pair[0])
                clip_path = os.path.join(args.clips_dir, f"{label}.wav")
                maybe_write_clip(args.audio, clip_path, longest[0], longest[1])
                if not os.path.isfile(clip_path):
                    clip_path = None

            speakers[label] = {
                "embedding": [float(x) for x in mean.tolist()],
                "duration": float(sum(end - start for start, end in used_windows)),
                "clip": clip_path,
            }
    finally:
        cleanup_path(cleanup_wav)

    if not speakers:
        parts = [p for p in [convert_error, *errors] if p]
        reason = "; ".join(parts) if parts else (
            "No speaker embedding was produced from this recording. "
            "The take may be silent, too short, or pyannote could not load the audio."
        )
        return write_empty(args.output, reason)

    output_dir = os.path.dirname(args.output)
    if output_dir and not os.path.isdir(output_dir):
        os.makedirs(output_dir, exist_ok=True)

    with open(args.output, "w", encoding="utf-8") as handle:
        json.dump({"model": model_name, "speakers": speakers}, handle)

    print(f"[voiceprint] wrote {len(speakers)} speaker embeddings.", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
