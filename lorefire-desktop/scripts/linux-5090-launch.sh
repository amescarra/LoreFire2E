#!/usr/bin/env bash
# linux-5090-launch.sh — one-click Lorefire 2E NativePHP window on Ubuntu x86_64.
#
# cds to lorefire-desktop and runs: php artisan native:serve
#
# A GNOME/KDE .desktop click has no TTY. NativePHP (Symfony Process setTty)
# then dies: "TTY mode requires /dev/tty to be read/writable."
# Non-TTY stdin → wrap native:serve in util-linux `script -qefc` (PTY).
# Interactive terminal → exec php artisan native:serve (no wrapper).
#
# Usage (from anywhere in the clone):
#   bash lorefire-desktop/scripts/linux-5090-launch.sh
#   bash lorefire-desktop/scripts/linux-5090-launch.sh --install-desktop
#
# --install-desktop writes ~/.local/share/applications/lorefire-2e.desktop
# with absolute Exec / Path / Icon pointing at public/icon.png (the NativePHP icon).
#
# Electron chrome-sandbox: Ubuntu needs that helper root:root mode 4755.
# If it is missing or not setuid, this script documents the chown/chmod and
# uses ELECTRON_DISABLE_SANDBOX=1 as a local-dev fallback so the click still
# opens a window. Do not set that env var on a packaged/prod build.
# Windows ARM does not use chrome-sandbox — keep using scripts/native-serve.ps1.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
DESKTOP="$ROOT/lorefire-desktop"
ICON="$DESKTOP/public/icon.png"
DESKTOP_TEMPLATE="$SCRIPT_DIR/lorefire-2e.desktop"
SANDBOX="$DESKTOP/vendor/nativephp/electron/resources/js/node_modules/electron/dist/chrome-sandbox"

usage() {
  cat <<EOF
Usage: $(basename "$0") [--install-desktop] [--help] [native:serve args...]

  --install-desktop   Install a ~/.local/share/applications/lorefire-2e.desktop
                      shortcut (Icon=$ICON). Does not start the app.
  --help              Show this help.

Default: cd $DESKTOP && php artisan native:serve

If Electron dies with FATAL chrome-sandbox / setuid_sandbox_host:
  sudo chown root:root $SANDBOX
  sudo chmod 4755 $SANDBOX
  # or keep using this script (it sets ELECTRON_DISABLE_SANDBOX=1 when needed)

See lorefire-desktop/LINUX-5090.md § chrome-sandbox and § desktop launcher.
EOF
}

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
  usage
  exit 0
fi

if [ "$(uname -s)" != "Linux" ] || [ "$(uname -m)" != "x86_64" ]; then
  echo "ERROR: This launcher is only for Ubuntu x86_64 (linux-5090)."
  echo "  Windows ARM: powershell -File lorefire-desktop/scripts/native-serve.ps1"
  echo "  Other hosts: cd lorefire-desktop && php artisan native:serve"
  exit 1
fi

if [ ! -f "$DESKTOP/artisan" ]; then
  echo "ERROR: lorefire-desktop/artisan not found at $DESKTOP"
  exit 1
fi

if [ ! -f "$ICON" ]; then
  echo "ERROR: NativePHP app icon missing: $ICON"
  echo "  Expected public/icon.png (copied to Electron build/ + resources/ on native:serve)."
  exit 1
fi

# GUI .desktop launches often have a thin PATH. Prefer a real php if PATH is empty.
if ! command -v php >/dev/null 2>&1; then
  for candidate in /usr/bin/php /usr/local/bin/php; do
    if [ -x "$candidate" ]; then
      PATH="$(dirname "$candidate"):$PATH"
      export PATH
      break
    fi
  done
fi

install_desktop() {
  local dest="${XDG_DATA_HOME:-$HOME/.local/share}/applications/lorefire-2e.desktop"
  if [ ! -f "$DESKTOP_TEMPLATE" ]; then
    echo "ERROR: missing template $DESKTOP_TEMPLATE"
    exit 1
  fi
  mkdir -p "$(dirname "$dest")"
  # Icon must be the installed NativePHP PNG (absolute — .desktop Icon= does not
  # resolve relative to this file). Exec goes through bash -lc so php is on PATH.
  sed \
    -e "s|^Exec=.*|Exec=bash -lc 'exec \"$SCRIPT_DIR/linux-5090-launch.sh\"'|" \
    -e "s|^Icon=.*|Icon=$ICON|" \
    -e "s|^Path=.*|Path=$DESKTOP|" \
    "$DESKTOP_TEMPLATE" > "$dest"
  chmod 644 "$dest"
  if command -v update-desktop-database >/dev/null 2>&1; then
    update-desktop-database "$(dirname "$dest")" >/dev/null 2>&1 || true
  fi
  echo "Installed desktop launcher:"
  echo "  $dest"
  echo "  Exec → $SCRIPT_DIR/linux-5090-launch.sh"
  echo "  Icon → $ICON"
  echo "  Path → $DESKTOP"
  echo "Find \"Lorefire 2E\" in the application menu, or run this script without flags."
}

if [ "${1:-}" = "--install-desktop" ]; then
  install_desktop
  exit 0
fi

print_chrome_sandbox_help() {
  echo "==> Electron chrome-sandbox (Linux setuid)"
  if [ -e "$SANDBOX" ]; then
    echo "    Found: $SANDBOX"
    echo "    $(ls -l "$SANDBOX")"
  else
    echo "    Not installed yet (appears after the first native:serve electron npm install)."
    echo "    Path: $SANDBOX"
  fi
  echo "    Preferred (day-to-day native:serve):"
  echo "      sudo chown root:root $SANDBOX"
  echo "      sudo chmod 4755 $SANDBOX"
  echo "    Optional local-dev fallback (no sudo, weaker isolation):"
  echo "      ELECTRON_DISABLE_SANDBOX=1 php artisan native:serve"
  echo "    See LINUX-5090.md. Do not set ELECTRON_DISABLE_SANDBOX on a packaged build."
}

sandbox_is_ok() {
  # root-owned, setuid bit (mode 4755). Windows ARM never ships this helper.
  [ -e "$SANDBOX" ] || return 1
  [ "$(stat -c '%u' "$SANDBOX" 2>/dev/null || echo 1)" = "0" ] || return 1
  [ "$(stat -c '%a' "$SANDBOX" 2>/dev/null || echo 0)" = "4755" ] || return 1
  return 0
}

cd "$DESKTOP"

print_chrome_sandbox_help
echo ""

if [ -z "${ELECTRON_DISABLE_SANDBOX:-}" ] && ! sandbox_is_ok; then
  echo "==> chrome-sandbox is not root:root mode 4755 — using ELECTRON_DISABLE_SANDBOX=1"
  echo "    (local-dev fallback so this one-click launcher still opens a window)"
  export ELECTRON_DISABLE_SANDBOX=1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "ERROR: php is not on PATH. Install Ubuntu php-cli (see LINUX-5090.md) then retry."
  exit 1
fi

echo "==> Starting Lorefire 2E"
echo "    cwd     : $DESKTOP"
echo "    icon    : $ICON"

# Interactive terminal: NativePHP can open /dev/tty itself.
if [ -t 0 ]; then
  echo "    command : php artisan native:serve $*"
  echo ""
  exec php artisan native:serve "$@"
fi

# Desktop/.desktop (and any other no-TTY launch): allocate a PTY so
# Symfony Process::setTty() sees a writable /dev/tty.
if ! command -v script >/dev/null 2>&1; then
  echo "ERROR: no TTY (Desktop launcher) and util-linux script(1) is missing."
  echo "  NativePHP: TTY mode requires /dev/tty to be read/writable."
  echo "  sudo apt install bsdutils"
  echo "  or run from a terminal: php artisan native:serve"
  exit 1
fi

LOG="${LOREFIRE_NATIVE_SERVE_LOG:-$DESKTOP/storage/logs/native-serve.desktop.log}"
mkdir -p "$(dirname "$LOG")"
serve_cmd="php artisan native:serve$( [ "$#" -eq 0 ] || printf ' %q' "$@" )"
echo "    no TTY  : wrapping native:serve in script -qefc (PTY)"
echo "    log     : $LOG"
echo "    command : script -qefc $serve_cmd"
echo ""
exec script -qefc "$serve_cmd" "$LOG"
