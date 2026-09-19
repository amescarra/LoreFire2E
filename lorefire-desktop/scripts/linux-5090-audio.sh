#!/usr/bin/env bash
# linux-5090-audio.sh — Anker PowerConf S500 on Ubuntu PipeWire + BlueZ.
#
# The S500 is a Bluetooth speakerphone. There is no Anker proprietary driver
# on Linux. Capture must go through PipeWire/Pulse (arecord -l will only show
# the onboard ALC897). A2DP is playback-only; Handsfree / headset-head-unit
# is required for the microphone.
#
# Hardware on ourai (do not invent other paths):
#   Device : Anker PowerConf S500
#   BT MAC : 84:D3:52:EE:10:E3
#   Pulse  : bluez_card.84_D3_52_EE_10_E3
#            bluez_input.84_D3_52_EE_10_E3.*
#            bluez_output.84_D3_52_EE_10_E3.*
#
# Usage (linux-5090 / ourai only — not Windows ARM):
#   bash lorefire-desktop/scripts/linux-5090-audio.sh           # status
#   bash lorefire-desktop/scripts/linux-5090-audio.sh --apply   # connect + handsfree + defaults
#   bash lorefire-desktop/scripts/linux-5090-audio.sh --packages
#
# Windows ARM: do not run this. See WINDOWS-ARM.md.

set -euo pipefail

MAC="84:D3:52:EE:10:E3"
MAC_NODE="84_D3_52_EE_10_E3"
LABEL="Anker PowerConf S500"
CARD="bluez_card.${MAC_NODE}"
PROFILE="headset-head-unit"

if [ "$(uname -s)" != "Linux" ]; then
  echo "ERROR: linux-5090-audio.sh is for Ubuntu PipeWire + BlueZ only."
  echo "  Windows ARM: see lorefire-desktop/WINDOWS-ARM.md"
  exit 1
fi

cmd_ok() { command -v "$1" >/dev/null 2>&1; }

print_packages() {
  echo "==> Required stack (BlueZ + PipeWire — no Anker .deb)"
  local pkgs=(
    "pipewire:pipewire or pw-cli"
    "pipewire-pulse:Pulse compatibility for Electron getUserMedia"
    "wireplumber:wpctl"
    "pulseaudio-utils:pactl"
    "bluez:bluetoothctl"
    "libspa-0.2-bluetooth:libspa-bluez5 SPA plugin"
  )
  local missing=()
  for spec in "${pkgs[@]}"; do
    local apt="${spec%%:*}"
    local why="${spec#*:}"
    local present=no
    case "$apt" in
      pipewire)
        if cmd_ok pipewire || cmd_ok pw-cli; then present=yes; fi
        ;;
      pipewire-pulse)
        if cmd_ok pipewire-pulse || cmd_ok pactl; then present=yes; fi
        ;;
      wireplumber)
        if cmd_ok wpctl; then present=yes; fi
        ;;
      pulseaudio-utils)
        if cmd_ok pactl; then present=yes; fi
        ;;
      bluez)
        if cmd_ok bluetoothctl; then present=yes; fi
        ;;
      libspa-0.2-bluetooth)
        if [ -f /usr/lib/x86_64-linux-gnu/spa-0.2/bluez5/libspa-bluez5.so ] \
          || [ -f /usr/lib/spa-0.2/bluez5/libspa-bluez5.so ]; then
          present=yes
        fi
        ;;
    esac
    echo "    [$present] $apt — $why"
    if [ "$present" = no ]; then
      missing+=("$apt")
    fi
  done
  if [ "${#missing[@]}" -gt 0 ]; then
    echo ""
    echo "    Install missing:"
    echo "      sudo apt update"
    echo "      sudo apt install -y ${missing[*]}"
    return 1
  fi
  return 0
}

print_status() {
  echo "==> Bluetooth ($MAC / $LABEL)"
  if cmd_ok bluetoothctl; then
    bluetoothctl info "$MAC" 2>/dev/null | sed -n 's/^/    /p' | head -40 || true
    echo "    Connected? (expect yes):"
    bluetoothctl info "$MAC" 2>/dev/null | grep -E 'Name:|Paired:|Trusted:|Connected:|UUID:' | sed 's/^/      /' || true
  else
    echo "    bluetoothctl missing (apt install bluez)"
  fi

  echo ""
  echo "==> PipeWire / Pulse (BT will NOT show in arecord -l)"
  if cmd_ok arecord; then
    echo "    arecord -l (onboard ALC897 only is expected):"
    arecord -l 2>/dev/null | sed 's/^/      /' || true
  fi
  if cmd_ok pactl; then
    echo "    pactl list cards short:"
    pactl list cards short 2>/dev/null | sed 's/^/      /' || true
    echo "    S500 card profiles / active:"
    pactl list cards 2>/dev/null | awk -v c="$CARD" '
      $0 ~ "Name: "c {hit=1}
      hit && /^Card #/ && $0 !~ c {hit=0}
      hit && (/Name:|device.description|Active Profile:|^\t\t(a2dp|headset|off|handsfree)/) {print "      " $0}
    '
    echo "    pactl list sources short (need bluez_input … headset-head-unit):"
    pactl list sources short 2>/dev/null | sed 's/^/      /' || true
    echo "    pactl list sinks short (bluez_output):"
    pactl list sinks short 2>/dev/null | sed 's/^/      /' || true
    echo "    defaults:"
    echo "      source: $(pactl get-default-source 2>/dev/null || echo unknown)"
    echo "      sink:   $(pactl get-default-sink 2>/dev/null || echo unknown)"
  else
    echo "    pactl missing (apt install pulseaudio-utils / pipewire-pulse)"
  fi
  if cmd_ok wpctl; then
    echo "    wpctl status (Audio):"
    wpctl status 2>/dev/null | awk '
      /^Audio/ {p=1}
      /^Video/ {p=0}
      p {print "      " $0}
    '
  fi
}

apply_s500() {
  echo "==> Connect + Handsfree + PipeWire defaults for $LABEL"
  if cmd_ok bluetoothctl; then
    bluetoothctl power on || true
    bluetoothctl trust "$MAC" || true
    bluetoothctl connect "$MAC" || true
    sleep 1
  fi

  if ! cmd_ok pactl; then
    echo "ERROR: pactl not found. sudo apt install -y pipewire-pulse pulseaudio-utils"
    exit 1
  fi

  local card
  card="$(pactl list cards short 2>/dev/null | awk -v c="$CARD" '$2==c {print $2; exit}')"
  if [ -z "$card" ]; then
    card="$(pactl list cards short 2>/dev/null | awk '/bluez_card/ && /84_D3_52_EE_10_E3/ {print $2; exit}')"
  fi
  if [ -z "$card" ]; then
    echo "ERROR: $CARD not in pactl list cards. Pair/connect the S500 first:"
    echo "  bluetoothctl connect $MAC"
    echo "  Then re-run with --apply."
    exit 1
  fi

  echo "    Card: $card"
  echo "    Profile → $PROFILE (Handsfree / HSP/HFP — required for mic)"
  if ! pactl set-card-profile "$card" "$PROFILE"; then
    echo "    $PROFILE failed; trying headset-head-unit-msbc (wideband 16 kHz)…"
    pactl set-card-profile "$card" headset-head-unit-msbc || {
      echo "ERROR: could not switch off A2DP. LoreFire will not see a BT mic."
      exit 1
    }
  fi
  sleep 1

  local src sink
  src="$(pactl list sources short 2>/dev/null | awk '/bluez_input\.84_D3_52_EE_10_E3/ {print $2; exit}')"
  sink="$(pactl list sinks short 2>/dev/null | awk '/bluez_output\.84_D3_52_EE_10_E3/ {print $2; exit}')"
  if [ -n "$src" ]; then
    echo "    Default source → $src"
    pactl set-default-source "$src"
  else
    echo "WARNING: no bluez_input for $MAC_NODE. Profile may still be A2DP."
  fi
  if [ -n "$sink" ]; then
    echo "    Default sink → $sink"
    pactl set-default-sink "$sink"
  fi
  if cmd_ok wpctl && [ -n "$src" ]; then
    local wp_src wp_sink
    wp_src="$(wpctl status 2>/dev/null | awk '/Sources:/{s=1} s&&/Anker PowerConf S500/{print $1; exit}' | tr -d '.*')"
    wp_sink="$(wpctl status 2>/dev/null | awk '/Sinks:/{s=1} /Sources:/{s=0} s&&/Anker PowerConf S500/{print $1; exit}' | tr -d '.*')"
    [ -n "$wp_src" ] && wpctl set-default "$wp_src" || true
    [ -n "$wp_sink" ] && wpctl set-default "$wp_sink" || true
  fi
  echo "    Done. Electron/LoreFire should list $LABEL after Refresh devices in Settings."
}

MODE="${1:-status}"
case "$MODE" in
  --packages|-p)
    print_packages
    ;;
  --apply|--prefer|-a)
    print_packages || true
    echo ""
    apply_s500
    echo ""
    print_status
    ;;
  --status|-s|"")
    print_packages || true
    echo ""
    print_status
    ;;
  *)
    echo "Usage: $0 [--status|--apply|--packages]"
    exit 2
    ;;
esac
