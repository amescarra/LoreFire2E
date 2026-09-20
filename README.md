# Lorefire 2E

<p align="center">
  <img width="128" height="128" alt="Lorefire" src="lorefire-desktop/public/logo.png" />
</p>

> A local-first chronicle for **Advanced Dungeons & Dragons 2nd Edition** campaigns. Record sessions, auto-transcribe with AI, generate bardic summaries, track characters, and build your campaign archive — all on your machine, no cloud required.

This repository is a **separate AD&D 2E edition** of [Lorefire](https://github.com/JustinWoodring/lorefire) by Justin Woodring. It is not a 5e app with a rules toggle.

Built with [NativePHP](https://nativephp.com), [Laravel 12](https://laravel.com), [React](https://react.dev), and [Inertia.js](https://inertiajs.com).

> **Tested on Apple Silicon.** Runs on any sufficiently powerful machine — Macs tend to excel at local AI workloads.

---

## ✨ Features

| | Feature | Description |
|---|---|---|
| 📚 | **Campaigns** | Manage multiple campaigns with party portraits and full session history |
| 🧙 | **Characters** | AD&D 2E sheets — THAC0, descending AC, 2E saves, weapon/non-weapon proficiencies, Vancian memorization |
| 🎙️ | **Session Recording** | Record audio at the table and transcribe locally with WhisperX |
| 📖 | **Bardic Summaries** | AI-generated epic prose recaps of your sessions |
| ⚔️ | **Encounter Tracker** | Auto-detect encounters; resolve attacks with THAC0 vs descending AC and d10 initiative |
| 🧝 | **NPC Roster** | Track campaign NPCs with attitudes, locations, and AI-generated portraits |
| 🔮 | **Oracle** | AI chat assistant that answers in 2E terms, with full access to your campaign data |
| 🎨 | **Art Generation** | Generate character portraits and scene art via ComfyUI, z.ai, or DALL-E |
| 📄 | **PDF Export** | Export campaign or session data to PDF |
| 🔍 | **Detail Extraction** | LLM-powered extraction of HP, gold, XP, and NPC appearances from transcripts |

---

## 📦 Installation (Release Build)

1. Download the latest `.dmg` from the [Releases](https://github.com/amescarra/lorefire/releases) page
2. Open the DMG and drag **Lorefire.app** to `/Applications`
3. Because the app is not notarized, macOS Gatekeeper will block it on first launch. Remove the quarantine flag:

```bash
xattr -rd com.apple.quarantine /Applications/Lorefire.app
```

4. Open the app normally — the onboarding wizard will guide you through the rest.

---

## 🚀 Getting Started (Development)

### Prerequisites

- macOS (tested), Windows and Linux (untested)
- [Node.js](https://nodejs.org) 20+
- [PHP](https://php.net) 8.2+
- [Composer](https://getcomposer.org)

**Windows on ARM:** keep ARM64 Node (do not install x64 Node). `native:serve` uses your system ARM64 PHP (`winget install PHP.PHP.8.4`). NativePHP php-bin has no `win/arm64` zip — see [lorefire-desktop/WINDOWS-ARM.md](lorefire-desktop/WINDOWS-ARM.md). Packaged ARM installers are still blocked upstream.

**Ubuntu x86_64 + RTX 5090 (CUDA):** separate path. Do not use the ARM scripts. See [lorefire-desktop/LINUX-5090.md](lorefire-desktop/LINUX-5090.md) and `bash lorefire-desktop/scripts/linux-5090-setup.sh`. One-click window: `bash lorefire-desktop/scripts/linux-5090-launch.sh` (optional `--install-desktop` menu shortcut; icon is `lorefire-desktop/public/icon.png`). WhisperX uses CUDA 12.8 when `nvidia-smi` works; CPU only if the NVIDIA driver is missing. Resolute PHP 8.5 needs `nativephp/php-bin` **1.2.0+** (`php-8.5.zip`); 1.1.1 only ships linux/x64 8.3+8.4.

### Setup

```bash
# Clone the repo
git clone https://github.com/amescarra/lorefire.git
cd lorefire/lorefire-desktop

# Install PHP dependencies
composer install

# Install Node dependencies
npm install

# Copy and configure environment
cp .env.example .env
php artisan key:generate

# Run database migrations
# NativePHP opens database/nativephp.sqlite. `php artisan migrate` uses
# database/database.sqlite unless DB_DATABASE is set, so also run:
php artisan native:migrate --force
# After this change, `php artisan migrate` (non-testing) also migrates
# nativephp.sqlite when that file already exists.

# Build frontend assets
npm run build

# Start the app
php artisan native:serve
```

On Windows ARM64, run that same command with ARM Node + ARM PHP on PATH. Optional check wrapper: `powershell -File scripts/native-serve.ps1`.

On Ubuntu x86_64 (linux-5090), the same command is wrapped by `bash scripts/linux-5090-launch.sh` (cds into `lorefire-desktop`, documents `chrome-sandbox` / `ELECTRON_DISABLE_SANDBOX=1`). `bash scripts/linux-5090-launch.sh --install-desktop` installs a **Lorefire 2E** menu entry whose `Icon=` is `public/icon.png`.

> **Note:** Make sure no other Vite process is already running on port 5173 before starting `native:serve`. You can check with `lsof -i :5173`.

---

## ⚙️ Configuration

All settings are configured inside the app via **Settings** (after onboarding). Nothing requires editing `.env` manually.

### 🎙️ Transcription (WhisperX)

Lorefire 2E bundles [WhisperX](https://github.com/m-bain/whisperX) in a local Python virtual environment. Audio is **never sent off your device**.

On first launch, the app automatically installs the Python venv in the background. You can monitor progress in the onboarding wizard or in Settings.

| Setting | Description |
|---|---|
| **Model size** | `tiny` → `large-v3` (multilingual). `base` is recommended. English-only `*.en` names are coerced to the multilingual twin. |
| **Languages** | Allowlist default **English + Spanish**. Detection is per spoken stretch and clamped to that list — mixed sessions keep both; a false French detect does not stay French. |
| **HuggingFace Token** | Required for **speaker diarization** (identifying who said what). Free at [huggingface.co/settings/tokens](https://huggingface.co/settings/tokens). You must also accept **both** pyannote model licenses while logged into the same account: [pyannote/speaker-diarization-3.1](https://huggingface.co/pyannote/speaker-diarization-3.1) and [pyannote/segmentation-3.0](https://huggingface.co/pyannote/segmentation-3.0). |

> **Large-v3 note:** Needs 8 GB+ RAM and will be noticeably slow on CPU. On Apple Silicon with enough unified memory it works well.

### 🤖 LLM Provider (Summaries, Oracle, Art Prompts)

Pick one provider in Settings. API keys are stored locally in SQLite and never leave your machine except to call the chosen provider.

| Provider | Notes |
|---|---|
| **z.ai** *(recommended)* | GLM models via the z.ai Coding Plan. Supports `glm-4.6`, `glm-4.7`, `glm-4-flash`, and others. Get a key at [z.ai](https://z.ai). |
| **OpenAI** | Uses `gpt-4o-mini` by default. Requires an [OpenAI API key](https://platform.openai.com/api-keys). |
| **Anthropic** | Uses `claude-3-haiku`. Requires an [Anthropic API key](https://console.anthropic.com). |
| **Ollama** | Any locally running model (e.g. `llama3`, `mistral`). Requires [Ollama](https://ollama.com) running on `localhost:11434`. No API key needed. |
| **None** | Falls back to template-based summaries. All other features still work. |

### 🎨 Image Generation

Configure in Settings → **Image Generation**.

| Provider | Notes |
|---|---|
| **ComfyUI** *(local)* | Connects to your local [ComfyUI](https://github.com/comfyanonymous/ComfyUI) instance. Uses whatever checkpoint is currently loaded. Default URL: `http://localhost:8188`. See [ComfyUI setup](#-comfyui-setup) below. |
| **z.ai** | Uses your z.ai API key (configured above). Recommended model: `cogview-4-flash`. |
| **OpenAI DALL-E** | Uses your OpenAI API key. Recommended model: `dall-e-3`. |
| **None** | Disables image generation entirely. |

Two art styles are available: **Lifelike** (realistic fantasy painting) and **Comic** (graphic novel illustration). The default can be set globally in Settings and overridden per campaign.

---

## 🖼️ ComfyUI Setup

ComfyUI is optional — it lets you generate portraits and scene art locally using Stable Diffusion / FLUX models.

### 1. Install ComfyUI

Follow the official install guide: [https://github.com/comfyanonymous/ComfyUI](https://github.com/comfyanonymous/ComfyUI)

```bash
git clone https://github.com/comfyanonymous/ComfyUI.git
cd ComfyUI
pip install -r requirements.txt
```

### 2. Install ComfyUI-Manager (required)

Lorefire's workflows use custom nodes. [ComfyUI-Manager](https://github.com/ltdrdata/ComfyUI-Manager) lets you install them in one click.

```bash
cd ComfyUI/custom_nodes
git clone https://github.com/ltdrdata/ComfyUI-Manager.git
```

Restart ComfyUI, then open the Manager (button in the top-right of the UI) and click **Install Missing Custom Nodes**.

### 3. Required custom nodes

Install these via ComfyUI-Manager → **Install Custom Nodes**:

| Node | Purpose |
|---|---|
| [ComfyUI-Manager](https://github.com/ltdrdata/ComfyUI-Manager) | Node manager (already installed above) |
| [rgthree-comfy](https://github.com/rgthree/rgthree-comfy) | Power Lora Loader used in FLUX workflows |
| [ComfyUI_IPAdapter_plus](https://github.com/cubiq/ComfyUI_IPAdapter_plus) | IP-Adapter for portrait consistency (optional but recommended) |

After installing, restart ComfyUI and use Manager → **Install Missing Custom Nodes** to resolve any remaining dependencies.

### 4. Download a checkpoint model

Place checkpoint files in `ComfyUI/models/checkpoints/`. Recommended models:

| Model | Notes |
|---|---|
| [FLUX.1-dev](https://huggingface.co/black-forest-labs/FLUX.1-dev) | Best quality; requires HuggingFace login. Download `flux1-dev.safetensors`. |
| [FLUX.1-schnell](https://huggingface.co/black-forest-labs/FLUX.1-schnell) | Faster, fewer steps needed. |
| Any SDXL checkpoint | e.g. [RealVisXL](https://civitai.com/models/139562). Place in `checkpoints/`. |

For FLUX models you also need the text encoders and VAE — place them in:
- `ComfyUI/models/clip/` — `clip_l.safetensors`, `t5xxl_fp16.safetensors`
- `ComfyUI/models/vae/` — `ae.safetensors`

These can all be downloaded from the [FLUX.1-dev HuggingFace repo](https://huggingface.co/black-forest-labs/FLUX.1-dev/tree/main).

### 5. Start ComfyUI

```bash
cd ComfyUI
python main.py
# Runs on http://localhost:8188 by default
# For GPU-poor machines: python main.py --lowvram
```

### 6. Configure Lorefire

In **Settings → Image Generation**, set the provider to `ComfyUI` and confirm the base URL (`http://localhost:8188`).

Lorefire submits generation jobs via the ComfyUI API. The active checkpoint in ComfyUI is used automatically — swap the model in ComfyUI's checkpoint loader to change style.

---

## 🗂️ Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12 |
| Desktop runtime | NativePHP / Electron 1.3 |
| Frontend | React 19 + TypeScript |
| Routing / SSR bridge | Inertia.js v2 |
| Styling | Tailwind CSS v4 |
| Build tool | Vite 7 |
| Database | SQLite (local, via NativePHP) |
| Transcription | WhisperX (local Python venv) |
| Queue | Laravel database queue |

---

## 📝 Notes

- **All data is local.** The SQLite database lives in `~/Library/Application Support/lorefire/` (production) or `~/Library/Application Support/lorefire-dev/` (dev).
- **No account required.** Nothing is synced to a server. Your campaigns stay on your machine.
- **Other hardware:** Tested on Apple Silicon. Windows ARM is a supported CPU path ([WINDOWS-ARM.md](lorefire-desktop/WINDOWS-ARM.md)). Ubuntu x86_64 + RTX 5090 is a supported CUDA path ([LINUX-5090.md](lorefire-desktop/LINUX-5090.md)). WhisperX is significantly slower without a dedicated neural engine or GPU.
- **Transcription is CPU/ANE-bound.** On Apple Silicon, WhisperX uses the `mps` backend. Large audio files may take a few minutes even on `base` model.
- **Rules text.** This project implements original UI wording and mechanical tables only. It does not include TSR/WotC copyrighted rulebook prose.

---

## 📄 License

MIT — see [LICENSE](./LICENSE)

Original Lorefire © 2026 Justin Woodring. This 2E fork retains that copyright and the MIT license.
