# Linux x86_64 + RTX 5090 (CUDA)

This is the **linux-5090** path: Ubuntu x86_64, ASUS TUF RTX 5090 32GB (Blackwell), Ryzen 7 9850X3D. It is separate from the Windows ARM / Surface Laptop 7 Snapdragon path in [WINDOWS-ARM.md](WINDOWS-ARM.md). Do not run the ARM scripts on this box, and do not run this script on ARM Windows.

Lorefire stays **local**. Transcription is WhisperX in a Python venv. The LLM is **Ollama** (already in Settings). No second stack or cloud API is required.

## What this path does

| Piece | linux-5090 (this box) | ARM / Windows Snapdragon |
|---|---|---|
| Install script | `scripts/linux-5090-setup.sh` | `scripts/native-serve.ps1` |
| Docs | this file | `WINDOWS-ARM.md` |
| WhisperX torch | CUDA 12.8 (`cu128`) when `nvidia-smi` works | CPU wheels (`setup.ps1`) |
| First-run GPU | CUDA-first, CPU only if the driver is missing | CPU |
| Default WhisperX | `large-v3`, float16, batch 32 | `base`, int8 |
| Default Ollama | `qwen2.5:32b`, `num_ctx` 8192 | `llama3` (unchanged) |

## 1. Apt packages (do this first)

```bash
sudo apt update
sudo apt install -y \
  git curl wget ca-certificates build-essential pkg-config \
  php-cli php-xml php-mbstring php-sqlite3 php-curl php-zip php-bcmath \
  composer \
  nodejs npm \
  python3 python3-venv python3-pip python3-dev \
  ffmpeg \
  sqlite3 \
  ubuntu-drivers-common
```

Ubuntu 24.04 ships PHP 8.3, which is fine (Lorefire needs PHP 8.2+). Confirm:

```bash
php -v          # 8.2+
node -v         # 20+
python3 --version
composer -V
```

If `composer` is missing from apt:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

## 2. NVIDIA driver + CUDA + cuDNN

Blackwell (RTX 5090) needs **driver 570+** and PyTorch **CUDA 12.8** wheels. CUDA 11.8 wheels will not run on this GPU.

### Driver

```bash
ubuntu-drivers devices
sudo ubuntu-drivers autoinstall
# or pin: sudo apt install -y nvidia-driver-570
sudo reboot
```

After reboot:

```bash
nvidia-smi
# Must list the ASUS / RTX 5090 and driver 570 or newer.
```

### CUDA toolkit 12.8 (optional but recommended)

PyTorch `cu128` wheels bundle the user-mode CUDA runtime. The **driver** is the hard requirement. The toolkit is useful for `nvcc` and matching cuDNN.

Follow NVIDIA’s Ubuntu 12.8 network install (add their repo, then):

```bash
sudo apt install -y cuda-toolkit-12-8
# cuDNN is included in current CUDA 12.8 network metapackages.
# If you use a standalone cuDNN package, install the 12.x variant that matches 12.8.
echo 'export PATH=/usr/local/cuda/bin:$PATH' >> ~/.bashrc
source ~/.bashrc
nvcc --version
```

### Probe

```bash
bash lorefire-desktop/scripts/linux-5090-detect.sh
# exit 0 = CUDA-first path; exit 1 = CPU fallback
```

## 3. Ollama (local LLM)

```bash
curl -fsSL https://ollama.com/install.sh | sh
ollama serve          # systemd usually starts this after install
ollama pull qwen2.5:32b
```

`qwen2.5:32b` (Q4, ~20 GB) fits 32 GB VRAM with WhisperX `large-v3` + pyannote still on the 5090. Do **not** pull `llama3.1:70b` Q4 (~40 GB) if you also want live transcription — it will OOM.

| Want | Model | Approx VRAM | Notes |
|---|---|---|---|
| Default (this box) | `qwen2.5:32b` | ~20 GB | Leaves ~10 GB for WhisperX + KV |
| More headroom | `qwen2.5:14b` or `llama3.1:8b` | 8–10 GB | Fine, less Oracle quality |
| Max model, no Whisper | `llama3.1:70b` | ~40 GB | Unload Ollama before transcribing |

Lorefire talks to `http://localhost:11434`. Context / batch (`num_ctx` 8192, `num_batch` 512, all layers on GPU) are applied automatically on this path.

## 4. Clone and install Lorefire 2E

```bash
git clone https://github.com/amescarra/lorefire.git
cd lorefire
git fetch origin linux-5090
git checkout linux-5090

bash lorefire-desktop/scripts/linux-5090-setup.sh
```

The script runs `composer install`, `.env`, `npm install`, migrations, `npm run build`, the WhisperX venv (`python:setup --gpu` when CUDA is present), and `ollama pull` when Ollama is installed.

Manual equivalent:

```bash
cd lorefire/lorefire-desktop
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan native:migrate --force
npm run build
bash resources/python/download_runtime.sh   # optional
php artisan python:setup --gpu              # or --cpu if nvidia-smi is missing
php artisan native:serve
```

`python:setup` on Linux x86_64 auto-enables `--gpu` when `nvidia-smi` works. `--cpu` forces the old CPU wheels.

## 5. First run

```bash
cd lorefire/lorefire-desktop
php artisan native:serve
```

Onboarding / Settings:

1. WhisperX model: **large-v3** (seeded when CUDA is detected and the setting is empty).
2. LLM provider: **Ollama**, model `qwen2.5:32b`, URL `http://localhost:11434`.
3. HuggingFace token: required for **speaker diarization**. Accept both licenses on the same account:
   - https://huggingface.co/pyannote/speaker-diarization-3.1
   - https://huggingface.co/pyannote/segmentation-3.0

## 6. AD&D 2E session audio

All audio stays on this machine.

1. Open a campaign → create or open a **Session**.
2. **Mic:** Session Recording → **Start Recording**, then **Stop**. WhisperX transcribes locally on the 5090.
3. **File import:** **Import Audio File** (`webm`, `wav`, `mp3`, `m4a`, `flac`, `ogg`, `mp4`). Same WhisperX job.
4. After the transcript is done, bardic summary / Oracle / sheet extraction use Ollama.

CLI check (optional):

```bash
cd lorefire/lorefire-desktop
resources/python/venv/bin/python resources/python/run_whisperx.py \
  --audio /path/to/session.wav \
  --output /tmp/lorefire-transcript.json \
  --model large-v3 \
  --device cuda \
  --compute-type float16 \
  --batch-size 32 \
  --diarize --hf-token "$HF_TOKEN"
```

## 7. 32 GB VRAM notes

- WhisperX `large-v3` float16 + batch 32 is a few GB. pyannote diarization adds ~1–2 GB.
- Ollama `qwen2.5:32b` Q4 uses ~20 GB plus KV cache at 8k context.
- Together this stays under 32 GB. If you load ComfyUI FLUX at the same time, unload Ollama first (`ollama stop qwen2.5:32b`).
- `run_whisperx.py --device auto` already prefers CUDA when `torch.cuda.is_available()`.

## 8. Force CPU / other platforms

```bash
php artisan python:setup --cpu
# or
bash resources/python/setup.sh --cpu
```

Windows ARM remains `powershell -File scripts/native-serve.ps1` and `setup.ps1` (CPU torch 2.5.1). Those files are not used here.

## Storage

SQLite on Linux lives under `~/.config/lorefire/` (production) or `~/.config/lorefire-dev/` (dev), not the macOS `~/Library/Application Support` path in the main README.
