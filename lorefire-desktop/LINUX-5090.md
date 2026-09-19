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

Prefer **Ubuntu archive** PHP (`php-cli` and the `php-*` extension metapackages). This repo’s Composer lock needs **PHP 8.4+**. Check the codename first:

```bash
. /etc/os-release
echo "$VERSION_CODENAME"    # resolute, noble, jammy, …
```

### Resolute (26.04) and any release whose archive PHP is 8.4+

Ubuntu Resolute’s `php-cli` is **8.5**. Do **not** add `ppa:ondrej/php` — that PPA has **no Release file** for `resolute` (`404` on `apt update`).

```bash
sudo apt update
sudo apt install -y \
  git curl wget ca-certificates build-essential pkg-config \
  php-cli php-xml php-mbstring php-sqlite3 php-curl php-zip php-bcmath \
  composer \
  nodejs npm \
  python3 python3-venv python3-pip python3-dev \
  python3.12 python3.12-venv python3.12-dev \
  ffmpeg \
  sqlite3 \
  ubuntu-drivers-common
```

### If you already added ondrej on Resolute (broken list)

`apt update` will 404 on `ppa.launchpadcontent.net/ondrej/php/.../resolute/Release`. Remove the source, then use archive PHP as above:

```bash
sudo add-apt-repository --remove ppa:ondrej/php
# Leftover files if the remove did not clear them:
sudo rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list \
           /etc/apt/sources.list.d/ondrej-ubuntu-php-*.sources
sudo apt update
```

### Noble (24.04) and Jammy (22.04) only

Archive `php-cli` is older than 8.4 (8.3 on noble, 8.1 on jammy). [ondrej/php](https://launchpad.net/~ondrej/+archive/ubuntu/php) still publishes those series:

```bash
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt install -y php8.4-cli php8.4-xml php8.4-mbstring php8.4-sqlite3 php8.4-curl php8.4-zip php8.4-bcmath
sudo update-alternatives --set php /usr/bin/php8.4
```

Do not use that PPA on Resolute or any other series that has no Release file.

Confirm:

```bash
php -v          # 8.4+ (8.5 is fine on Resolute)
node -v         # 20+
python3.12 --version   # preferred for WhisperX (Resolute's python3 is 3.14)
composer -V
```

Resolute’s default `python3` is **3.14**. WhisperX / ctranslate2 wheels are unreliable there. `setup.sh` prefers `python3.12` or `python3.11` when they are on PATH. Install `python3.12` / `python3.12-venv` as above. If a 3.14 venv already exists, delete it before re-running setup:

```bash
rm -rf lorefire-desktop/resources/python/venv
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

The script runs `composer install`, `.env`, **creates the SQLite files and migrates**, then `npm install` / `npm run build`, the WhisperX venv (`python:setup --gpu` when CUDA is present), and `ollama pull` when Ollama is installed. Migrations run **before** WhisperX so `AppSetting` writes do not hit a missing `database.sqlite`.

Manual equivalent:

```bash
cd lorefire/lorefire-desktop
composer install
cp .env.example .env
php artisan key:generate
mkdir -p database
touch database/database.sqlite          # CLI artisan / python:setup (Linux default)
touch database/nativephp.sqlite         # NativePHP window (same as Windows ARM)
php artisan migrate --force
php artisan native:migrate --force
npm install
npm run build
bash resources/python/download_runtime.sh   # optional
php artisan python:setup --gpu              # or --cpu if nvidia-smi is missing
php artisan native:serve
```

Leave `DB_DATABASE` unset in `.env` on this path. Laravel then uses `database/database.sqlite` for CLI. Do not point `.env` at `nativephp.sqlite` here — that is the NativePHP / Windows ARM serve file.

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

| When | Linux default | Windows ARM / NativePHP serve |
|---|---|---|
| `php artisan python:setup`, `migrate`, other CLI | `lorefire-desktop/database/database.sqlite` | still that file if you run artisan outside Electron |
| `php artisan native:serve` (dev window) | `lorefire-desktop/database/nativephp.sqlite` | same (`nativephp.sqlite`) |
| Packaged app | `~/.config/lorefire/` (prod) or `~/.config/lorefire-dev/` (dev) | OS app-data dir |

`linux-5090-setup.sh` `touch`es both repo sqlite files and migrates them **before** WhisperX setup. A missing `database.sqlite` makes `AppSetting::set()` fail during `python:setup`. The packaged-app path is not the macOS `~/Library/Application Support` location in the main README.
