#!/usr/bin/env bash
# linux-5090-detect.sh — Probe NVIDIA driver + CUDA on Ubuntu x86_64.
#
# Exit 0 when a working NVIDIA driver is present (CUDA-first path).
# Exit 1 when this machine should use the CPU fallback.
# Never used by the Windows ARM / Snapdragon path.

set -euo pipefail

os="$(uname -s)"
arch="$(uname -m)"

echo "==> Lorefire linux-5090 hardware probe"
echo "    OS   : $os"
echo "    Arch : $arch"

if [ "$os" != "Linux" ] || [ "$arch" != "x86_64" ]; then
  echo "    Result: not linux-5090 (need Linux x86_64). CPU / other platform path."
  exit 1
fi

if ! command -v nvidia-smi >/dev/null 2>&1; then
  echo "    nvidia-smi: missing"
  echo "    Result: CUDA not available — WhisperX will use CPU wheels."
  echo "    Install driver 570+ (Blackwell / RTX 5090), then re-run."
  exit 1
fi

echo "    nvidia-smi:"
nvidia-smi --query-gpu=name,driver_version,memory.total --format=csv,noheader || {
  echo "    nvidia-smi failed to query the GPU."
  exit 1
}

if command -v nvcc >/dev/null 2>&1; then
  echo "    nvcc : $(nvcc --version | tail -1)"
else
  echo "    nvcc : not on PATH (toolkit optional if the NVIDIA driver is loaded)"
fi

if [ -f /usr/local/cuda/version.json ]; then
  echo "    CUDA toolkit: /usr/local/cuda/version.json present"
elif [ -d /usr/local/cuda ]; then
  echo "    CUDA toolkit: /usr/local/cuda present"
else
  echo "    CUDA toolkit: not found at /usr/local/cuda (driver-only is OK for PyTorch wheels)"
fi

if ldconfig -p 2>/dev/null | grep -q 'libcuda.so'; then
  echo "    libcuda: present"
else
  echo "    libcuda: not in ldconfig (may still work if the driver just installed)"
fi

echo "    Result: NVIDIA driver OK — CUDA-first WhisperX / Ollama path."
exit 0
