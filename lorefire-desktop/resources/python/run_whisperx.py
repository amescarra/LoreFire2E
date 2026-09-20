#!/usr/bin/env python3
"""
run_whisperx.py — WhisperX transcription + speaker diarization runner.

Usage:
  python run_whisperx.py \
    --audio /path/to/audio.webm \
    --output /path/to/output.json \
    --model base \
    --languages en,es \
    --diarize \
    --hf-token <HuggingFace token for diarization>

Output JSON schema:
{
  "segments": [
    {
      "start": 0.0,
      "end": 2.4,
      "text": "Hello everyone.",
      "language": "en",
      "speaker": "SPEAKER_00"   # only present when --diarize
    },
    ...
  ],
  "word_segments": [...],    # optional, only if alignment succeeds
  "language": "en"
}

Exit codes:
  0  — success
  1  — argument / file error
  2  — transcription error
  3  — diarization error
"""

import argparse
import json
import os
import sys

_SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
if _SCRIPT_DIR not in sys.path:
    sys.path.insert(0, _SCRIPT_DIR)

from lorefire_tmp import ensure_ffmpeg_on_path  # noqa: E402
from whisperx_languages import (  # noqa: E402
    clamp_detected_language,
    coerce_multilingual_model,
    parse_languages,
)

# Set cache directories to proper OS-native paths before any ML library imports.
# On Windows the default expansion uses mixed separators which breaks cache lookups.
_cache_base = os.path.join(os.path.expanduser('~'), '.cache')
os.environ.setdefault('HF_HOME',    os.path.join(_cache_base, 'huggingface'))
os.environ.setdefault('TORCH_HOME', os.path.join(_cache_base, 'torch'))
# torch 2.6+ defaults torch.load(weights_only=True). pyannote 3.x / WhisperX VAD
# checkpoints need the escape hatch. Harmless on torch 2.5 (Windows ARM CPU).
os.environ.setdefault('TORCH_FORCE_NO_WEIGHTS_ONLY_LOAD', '1')

# imageio-ffmpeg bundles ffmpeg with a platform-specific name (e.g.
# ffmpeg-win64-v6.1.exe), not "ffmpeg.exe", so adding its directory to PATH
# is not enough. Reuse one alias under LOREFIRE_TMP / XDG cache — do not
# mkdtemp(prefix="lorefire_ffmpeg_") per slice (those leftovers filled /tmp).
ensure_ffmpeg_on_path()

# python-build-standalone does not hook into the Windows certificate store,
# so urllib (used by torch.hub and huggingface_hub) fails SSL verification.
# Explicitly point SSL to certifi's CA bundle to fix HTTPS in packaged builds.
try:
    import certifi
    os.environ.setdefault('SSL_CERT_FILE',      certifi.where())
    os.environ.setdefault('REQUESTS_CA_BUNDLE', certifi.where())
    os.environ.setdefault('CURL_CA_BUNDLE',     certifi.where())
except ImportError:
    pass

# Network connectivity check — surfaces the real error instead of torch.hub's
# generic "no internet connection" message.
import urllib.request as _urllib_request
try:
    _urllib_request.urlopen('https://huggingface.co', timeout=10)
    print("[whisperx-debug] Network: OK", file=sys.stderr)
except Exception as _net_err:
    print(f"[whisperx-debug] Network: FAILED — {type(_net_err).__name__}: {_net_err}", file=sys.stderr)


def detect_device() -> tuple[str, str, str]:
    """Return (ct2_device, torch_device, compute_type).

    CUDA-first: NVIDIA (linux-5090 / any CUDA torch) wins over MPS/CPU.
    ct2_device   — device string for ctranslate2 / faster-whisper (transcription).
                   ctranslate2 only accepts 'cpu' or 'cuda'; MPS is not supported.
    torch_device — device string for pure-PyTorch steps (alignment, diarization).
                   On Apple Silicon this is 'mps', giving a significant speedup.
    compute_type — quantisation hint for ctranslate2 (ignored by PyTorch steps).
    """
    try:
        import torch
        if torch.cuda.is_available():
            return "cuda", "cuda", "float16"
        if torch.backends.mps.is_available():
            # ctranslate2 cannot use MPS — keep transcription on CPU.
            # Alignment (torchaudio wav2vec2) and diarization (pyannote) are
            # pure PyTorch and benefit greatly from MPS on Apple Silicon.
            return "cpu", "mps", "int8"
    except Exception:
        pass
    return "cpu", "cpu", "int8"


def default_batch_size(ct2_device: str) -> int:
    """Use more VRAM on a 32GB 5090; keep 16 on CPU / small GPUs."""
    if ct2_device != "cuda":
        return 16
    try:
        import torch
        vram_gb = torch.cuda.get_device_properties(0).total_memory / (1024 ** 3)
        if vram_gb >= 28:
            return 32
        if vram_gb >= 16:
            return 24
    except Exception:
        pass
    return 16


SAMPLE_RATE = 16000


def vad_windows(pipeline, audio) -> list[tuple[float, float]]:
    """Speech windows in seconds. Falls back to 30s hops if VAD internals change."""
    duration = len(audio) / float(SAMPLE_RATE)
    try:
        import torch
        from whisperx.vad import merge_chunks

        wav = torch.from_numpy(audio).float()
        if wav.ndim == 1:
            wav = wav.unsqueeze(0)
        raw = pipeline.vad_model({"waveform": wav, "sample_rate": SAMPLE_RATE})
        merged = merge_chunks(raw, 30, onset=0.5, offset=0.363)
        windows = []
        for item in merged:
            start = float(item.get("start", 0))
            end = float(item.get("end", start))
            if end > start:
                windows.append((start, end))
        if windows:
            return windows
    except Exception as exc:
        print(f"[whisperx] VAD split failed ({exc}); using 30s hops.", file=sys.stderr)

    hop = 30.0
    windows = []
    t = 0.0
    while t < duration:
        windows.append((t, min(duration, t + hop)))
        t += hop
    return windows or [(0.0, max(duration, 0.01))]


def detect_language_distribution(pipeline, audio_chunk) -> tuple[str | None, dict[str, float]]:
    """Top language plus a lang→prob map for allowlist clamping."""
    try:
        from whisperx.audio import N_SAMPLES, log_mel_spectrogram
    except Exception:
        N_SAMPLES = 30 * SAMPLE_RATE
        log_mel_spectrogram = None

    try:
        feat_kwargs = getattr(pipeline.model, "feat_kwargs", None) or {}
        n_mels = feat_kwargs.get("feature_size") or 80
        if log_mel_spectrogram is None:
            raise RuntimeError("log_mel_spectrogram unavailable")
        padding = 0 if audio_chunk.shape[0] >= N_SAMPLES else N_SAMPLES - audio_chunk.shape[0]
        segment = log_mel_spectrogram(
            audio_chunk[:N_SAMPLES],
            n_mels=n_mels,
            padding=padding,
        )
        encoder_output = pipeline.model.encode(segment)
        results = pipeline.model.model.detect_language(encoder_output)
        ranked = results[0]
        probs: dict[str, float] = {}
        top = None
        top_p = -1.0
        for token, p in ranked:
            lang = token[2:-2] if isinstance(token, str) and len(token) >= 4 else str(token)
            probs[lang] = float(p)
            if float(p) > top_p:
                top = lang
                top_p = float(p)
        return top, probs
    except Exception:
        try:
            lang = pipeline.detect_language(audio_chunk)
            if lang:
                return lang, {str(lang): 1.0}
            return None, {}
        except Exception as exc:
            print(f"[whisperx] language detect failed ({exc})", file=sys.stderr)
            return None, {}


def transcribe_allowlisted(pipeline, audio, allowlist: list[str], batch_size: int) -> list[dict]:
    """Detect per VAD window, clamp to allowlist, transcribe (never translate)."""
    segments: list[dict] = []
    previous = allowlist[0]
    windows = vad_windows(pipeline, audio)
    single = len(allowlist) == 1

    for start_s, end_s in windows:
        start_i = max(0, int(start_s * SAMPLE_RATE))
        end_i = min(len(audio), int(end_s * SAMPLE_RATE))
        chunk = audio[start_i:end_i]
        if chunk.shape[0] < int(0.15 * SAMPLE_RATE):
            continue

        if single:
            lang = allowlist[0]
            detected = lang
        else:
            detected, probs = detect_language_distribution(pipeline, chunk)
            lang = clamp_detected_language(detected, probs, allowlist, previous)
            print(
                f"[whisperx] Detected language: {detected or 'unknown'} → {lang}",
                file=sys.stderr,
            )
        previous = lang

        try:
            result = pipeline.transcribe(
                chunk,
                batch_size=batch_size,
                language=lang,
                task="transcribe",
            )
        except TypeError:
            result = pipeline.transcribe(chunk, batch_size=batch_size, language=lang)

        for seg in result.get("segments", []):
            text = (seg.get("text") or "").strip()
            if not text:
                continue
            segments.append({
                "start": start_s + float(seg.get("start", 0)),
                "end": start_s + float(seg.get("end", 0)),
                "text": text,
                "language": lang,
            })

    return segments


def align_by_language(whisperx, segments: list[dict], audio, torch_device: str) -> list[dict]:
    """Align each language group with its wav2vec model; keep raw text on failure."""
    if not segments:
        return segments

    aligned: list[dict] = []
    cache: dict[str, tuple] = {}
    i = 0
    while i < len(segments):
        lang = segments[i].get("language") or "en"
        j = i + 1
        while j < len(segments) and (segments[j].get("language") or "en") == lang:
            j += 1
        group = segments[i:j]
        try:
            if lang not in cache:
                print(f"[whisperx] Aligning ({lang})…", file=sys.stderr)
                cache[lang] = whisperx.load_align_model(language_code=lang, device=torch_device)
            align_model, metadata = cache[lang]
            result = whisperx.align(
                [{k: s[k] for k in ("start", "end", "text") if k in s} for s in group],
                align_model,
                metadata,
                audio,
                torch_device,
                return_char_alignments=False,
            )
            out_segs = result.get("segments", group)
            for src, dst in zip(group, out_segs):
                merged = dict(src)
                merged.update({
                    "start": dst.get("start", src["start"]),
                    "end": dst.get("end", src["end"]),
                    "text": (dst.get("text") or src["text"]).strip(),
                })
                if "words" in dst:
                    merged["words"] = dst["words"]
                aligned.append(merged)
            print(f"[whisperx] Alignment complete ({lang}).", file=sys.stderr)
        except Exception as exc:
            print(
                f"[whisperx] WARNING: Alignment failed for {lang} ({exc}), using raw segments.",
                file=sys.stderr,
            )
            aligned.extend(group)
        i = j

    return aligned


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="WhisperX transcription runner")
    parser.add_argument("--audio",    required=True,  help="Path to input audio file")
    parser.add_argument("--output",   required=True,  help="Path to write JSON output")
    parser.add_argument("--model",    default="base",  help="Whisper model size (tiny/base/small/medium/large-v2/large-v3). *.en names are coerced.")
    parser.add_argument("--languages", default="en,es", dest="languages",
                        help="Comma-separated allowlist (default en,es). Do not pass a single --language when more than one is allowed.")
    parser.add_argument("--language", default=None,   help=argparse.SUPPRESS)  # legacy; folded into allowlist
    parser.add_argument("--diarize",  action="store_true", help="Enable speaker diarization")
    parser.add_argument("--hf-token", default=None,   dest="hf_token",
                        help="HuggingFace token (required for diarization)")
    parser.add_argument("--device",   default="auto",  help="Device: auto, cpu, cuda, or mps")
    parser.add_argument("--batch-size", type=int, default=None, dest="batch_size",
                        help="Batch size for transcription (default: 32 on 32GB CUDA, else 16)")
    parser.add_argument("--compute-type", default=None, dest="compute_type",
                        help="Compute type: int8, float16, float32 (default: float16 on CUDA, int8 on CPU)")
    parser.add_argument("--vad-method", default="silero", dest="vad_method",
                        help="VAD backend: silero (default) or pyannote")
    parser.add_argument("--min-speakers", type=int, default=None, dest="min_speakers")
    parser.add_argument("--max-speakers", type=int, default=None, dest="max_speakers")
    return parser.parse_args()


def main() -> int:
    args = parse_args()

    # ── Validate input ───────────────────────────────────────────────
    if not os.path.isfile(args.audio):
        print(f"ERROR: Audio file not found: {args.audio}", file=sys.stderr)
        return 1

    output_dir = os.path.dirname(args.output)
    if output_dir and not os.path.isdir(output_dir):
        os.makedirs(output_dir, exist_ok=True)

    # ── Resolve device ───────────────────────────────────────────────
    if args.device == "auto":
        ct2_device, torch_device, compute_type = detect_device()
        if args.compute_type:
            compute_type = args.compute_type
    else:
        ct2_device = args.device
        torch_device = args.device
        compute_type = args.compute_type or ("float16" if args.device == "cuda" else "int8")

    if args.batch_size is None:
        args.batch_size = default_batch_size(ct2_device)

    print(
        f"[whisperx] Using ct2_device={ct2_device} torch_device={torch_device} "
        f"compute_type={compute_type} batch_size={args.batch_size}",
        file=sys.stderr,
    )

    allowlist = parse_languages(args.languages)
    languages_explicit = any(
        a == "--languages" or a.startswith("--languages=") for a in sys.argv[1:]
    )
    if args.language and not languages_explicit:
        allowlist = parse_languages(args.language)
    model_name = coerce_multilingual_model(args.model)
    if model_name != args.model:
        print(f"[whisperx] Coerced English-only model '{args.model}' → '{model_name}'", file=sys.stderr)

    load_language = allowlist[0] if len(allowlist) == 1 else None

    # ── Import whisperx (lazy, so error messages are clear) ──────────
    try:
        import whisperx
    except ImportError:
        print("ERROR: whisperx is not installed. Run setup.sh first.", file=sys.stderr)
        return 2

    # ── Load model ───────────────────────────────────────────────────
    print(f"[whisperx] Loading model '{model_name}' on {ct2_device}…", file=sys.stderr)
    try:
        load_kwargs = dict(
            device=ct2_device,
            compute_type=compute_type,
            vad_method=args.vad_method,
        )
        if load_language:
            load_kwargs["language"] = load_language
        model = whisperx.load_model(model_name, **load_kwargs)
    except Exception as exc:
        print(f"ERROR: Failed to load model: {exc}", file=sys.stderr)
        return 2

    # ── Load audio ───────────────────────────────────────────────────
    print(f"[whisperx] Loading audio: {args.audio}", file=sys.stderr)
    try:
        audio = whisperx.load_audio(args.audio)
    except Exception as exc:
        print(f"ERROR: Failed to load audio: {exc}", file=sys.stderr)
        return 1

    # ── Transcribe (per-window detect, clamped to allowlist) ─────────
    print("[whisperx] Transcribing…", file=sys.stderr)
    try:
        segments = transcribe_allowlisted(model, audio, allowlist, args.batch_size)
    except Exception as exc:
        print(f"ERROR: Transcription failed: {exc}", file=sys.stderr)
        return 2

    langs_used = sorted({s.get("language") for s in segments if s.get("language")})
    detected_language = ",".join(langs_used) if langs_used else ",".join(allowlist)
    print(f"[whisperx] Detected language: {detected_language}", file=sys.stderr)

    # ── Align per language ───────────────────────────────────────────
    segments = align_by_language(whisperx, segments, audio, torch_device)

    result = {"segments": segments}

    # ── Diarize ──────────────────────────────────────────────────────
    if args.diarize:
        if not args.hf_token:
            print("WARNING: --diarize requested but no --hf-token provided. Skipping diarization.", file=sys.stderr)
        else:
            try:
                import whisperx.diarize as whisperx_diarize

                print("[whisperx] Running speaker diarization…", file=sys.stderr)
                diarize_model = whisperx_diarize.DiarizationPipeline(
                    use_auth_token=args.hf_token,
                    device=torch_device,
                )
                if diarize_model.model is None:
                    raise RuntimeError(
                        "pyannote pipeline failed to load (returned None). "
                        "Accept the model license at huggingface.co/pyannote/speaker-diarization-3.1 "
                        "and huggingface.co/pyannote/segmentation-3.0 with the account "
                        "that owns the provided HF token, then retry."
                    )
                diarize_kwargs: dict = {}
                if args.min_speakers is not None:
                    diarize_kwargs["min_speakers"] = args.min_speakers
                if args.max_speakers is not None:
                    diarize_kwargs["max_speakers"] = args.max_speakers

                diarize_segments = diarize_model(audio, **diarize_kwargs)
                result = whisperx.assign_word_speakers(diarize_segments, result)
                print("[whisperx] Diarization complete.", file=sys.stderr)
            except Exception as exc:
                print(f"[whisperx] WARNING: Diarization failed ({exc}), continuing without speaker labels.", file=sys.stderr)

    # ── Build output ─────────────────────────────────────────────────
    segments_out = []
    for seg in result.get("segments", []):
        entry: dict = {
            "start": round(seg.get("start", 0), 3),
            "end":   round(seg.get("end",   0), 3),
            "text":  seg.get("text", "").strip(),
        }
        if seg.get("language"):
            entry["language"] = seg["language"]
        if "speaker" in seg:
            entry["speaker"] = seg["speaker"]
        segments_out.append(entry)

    output_data = {
        "language": detected_language,
        "segments": segments_out,
    }

    # Include word-level segments if available
    if "word_segments" in result:
        output_data["word_segments"] = [
            {
                "word":    w.get("word", ""),
                "start":   round(w.get("start", 0), 3),
                "end":     round(w.get("end",   0), 3),
                "score":   round(w.get("score",  0), 4),
                "speaker": w.get("speaker"),
            }
            for w in result["word_segments"]
        ]

    # ── Write output ─────────────────────────────────────────────────
    print(f"[whisperx] Writing output to {args.output}…", file=sys.stderr)
    try:
        with open(args.output, "w", encoding="utf-8") as f:
            json.dump(output_data, f, ensure_ascii=False, indent=2)
    except Exception as exc:
        print(f"ERROR: Failed to write output: {exc}", file=sys.stderr)
        return 1

    segment_count = len(segments_out)
    print(f"[whisperx] Done. {segment_count} segments written.", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
