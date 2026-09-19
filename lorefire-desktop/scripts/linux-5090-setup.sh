#!/usr/bin/env bash
# linux-5090-setup.sh — Install Lorefire 2E for Anthony's Ubuntu x86_64 + RTX 5090 box.
#
# Does NOT touch the Windows ARM / Snapdragon path. Run from a clone of this repo:
#   bash lorefire-desktop/scripts/linux-5090-setup.sh
#
# CUDA-first: uses GPU torch (cu128) when nvidia-smi works. Falls back to CPU
# wheels only when the NVIDIA driver is missing.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DESKTOP="$ROOT/lorefire-desktop"
DETECT="$ROOT/lorefire-desktop/scripts/linux-5090-detect.sh"

echo "==> Lorefire linux-5090 setup"
echo "    Repo    : $ROOT"
echo "    Desktop : $DESKTOP"
echo ""

if [ "$(uname -s)" != "Linux" ] || [ "$(uname -m)" != "x86_64" ]; then
  echo "ERROR: This script is only for Ubuntu x86_64 (linux-5090)."
  echo "  Windows ARM: see lorefire-desktop/WINDOWS-ARM.md"
  echo "  Other hosts: use the README development setup."
  exit 1
fi

ubuntu_codename() {
  if [ -r /etc/os-release ]; then
    # Isolate os-release vars from the rest of the script.
    # shellcheck disable=SC1091
    ( . /etc/os-release && printf '%s' "${VERSION_CODENAME:-}" )
  fi
}

ondrej_php_sources() {
  shopt -s nullglob
  local files=(/etc/apt/sources.list.d/*ondrej*php*)
  shopt -u nullglob
  if [ "${#files[@]}" -gt 0 ]; then
    printf '%s\n' "${files[@]}"
  fi
}

print_php_install_help() {
  local codename="$1"
  echo "  This repo's composer.lock needs PHP 8.4+."
  echo "  Prefer Ubuntu archive packages (Resolute ships php-cli 8.5):"
  echo "    sudo apt install -y php-cli php-xml php-mbstring php-sqlite3 php-curl php-zip php-bcmath"
  case "$codename" in
    jammy|noble)
      echo "  On $codename the archive PHP is older than 8.4. ondrej/php still publishes $codename:"
      echo "    sudo add-apt-repository -y ppa:ondrej/php && sudo apt update"
      echo "    sudo apt install -y php8.4-cli php8.4-xml php8.4-mbstring php8.4-sqlite3 php8.4-curl php8.4-zip php8.4-bcmath"
      echo "    sudo update-alternatives --set php /usr/bin/php8.4"
      ;;
    *)
      echo "  Do NOT add ppa:ondrej/php on ${codename:-this release} — no Release file (apt 404)."
      echo "  If a broken ondrej list is already present:"
      echo "    sudo add-apt-repository --remove ppa:ondrej/php"
      echo "    sudo rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list /etc/apt/sources.list.d/ondrej-ubuntu-php-*.sources"
      echo "    sudo apt update"
      ;;
  esac
}

CODE="$(ubuntu_codename)"
echo "    Ubuntu  : ${CODE:-unknown}"

missing=()
for bin in git php composer node npm python3 curl; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    missing+=("$bin")
  fi
done

if [ "${#missing[@]}" -gt 0 ]; then
  echo "ERROR: missing commands: ${missing[*]}"
  echo "  Install the Ubuntu packages listed in lorefire-desktop/LINUX-5090.md"
  if printf '%s' "${missing[*]}" | grep -q 'php'; then
    print_php_install_help "$CODE"
  fi
  exit 1
fi

php_ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
echo "    PHP     : $(php -v | head -1) ($php_ver)"
if ! php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then
  echo "ERROR: this repo's composer.lock needs PHP 8.4+."
  print_php_install_help "$CODE"
  exit 1
fi

ondrej_lists="$(ondrej_php_sources || true)"
if [ -n "$ondrej_lists" ]; then
  case "$CODE" in
    jammy|noble) ;;
    *)
      echo "WARNING: ondrej/php apt source present, but that PPA has no Release for ${CODE:-this release}."
      echo "  apt update will 404 until you remove it:"
      echo "    sudo add-apt-repository --remove ppa:ondrej/php"
      echo "    sudo rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list /etc/apt/sources.list.d/ondrej-ubuntu-php-*.sources"
      echo "    sudo apt update"
      echo "  Leftover files:"
      echo "$ondrej_lists" | sed 's/^/    /'
      ;;
  esac
fi
echo "    Node    : $(node -v)"
echo "    Python  : $(python3 --version)"
echo ""

GPU=false
if bash "$DETECT"; then
  GPU=true
else
  echo ""
  echo "==> CUDA missing — continuing with CPU WhisperX (slower)."
  echo "    After installing NVIDIA driver 570+, re-run:"
  echo "      php artisan python:setup --gpu"
fi
echo ""

cd "$DESKTOP"

if [ ! -f composer.json ]; then
  echo "ERROR: lorefire-desktop/composer.json not found."
  exit 1
fi

echo "==> composer install"
composer install --no-interaction

if [ ! -f .env ]; then
  echo "==> copying .env.example → .env"
  cp .env.example .env
  php artisan key:generate --ansi
fi

echo "==> npm install"
npm install

echo "==> migrations"
php artisan native:migrate --force || php artisan migrate --force

echo "==> frontend build"
npm run build

echo "==> bundled Python runtime (optional; setup.sh falls back to system python3)"
if [ -x resources/python/download_runtime.sh ]; then
  bash resources/python/download_runtime.sh || echo "WARNING: runtime download skipped; using system Python."
fi

echo "==> WhisperX venv"
if [ "$GPU" = true ]; then
  php artisan python:setup --gpu
else
  php artisan python:setup --cpu
fi

if command -v ollama >/dev/null 2>&1; then
  echo "==> Ollama is installed ($(ollama --version 2>/dev/null || echo present))"
  if [ "$GPU" = true ]; then
    echo "    Pulling qwen2.5:32b (Q4, ~20GB — leaves room for WhisperX large-v3 on 32GB)..."
    ollama pull qwen2.5:32b || echo "WARNING: ollama pull failed. Run: ollama pull qwen2.5:32b"
  fi
else
  echo "==> Ollama not on PATH."
  echo "    Install with: curl -fsSL https://ollama.com/install.sh | sh"
  echo "    Then:         ollama pull qwen2.5:32b"
fi

echo ""
echo "==> linux-5090 setup complete."
echo ""
echo "    Start Lorefire 2E:"
echo "      cd $DESKTOP"
echo "      php artisan native:serve"
echo ""
echo "    In Settings (or onboarding):"
echo "      LLM provider     : Ollama"
echo "      Ollama model     : qwen2.5:32b"
echo "      WhisperX model   : large-v3"
echo "      HuggingFace token: required for speaker diarization"
echo ""
echo "    Audio: Session page → Start Recording (mic) or Import Audio File"
echo "    (webm, wav, mp3, m4a, flac) for AD&D 2E session transcription."
echo ""
