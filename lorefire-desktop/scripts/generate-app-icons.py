#!/usr/bin/env python3
"""Generate NativePHP / electron-builder icons from public/icon.png.

NativePHP (native:serve / native:build) copies:

  public/icon.png              → vendor/nativephp/electron/resources/js/{build,resources}/icon.png
  public/icon.ico              → …/icon.ico   (Windows ARM64 and x64)
  public/icon.icns             → …/icon.icns  (macOS)
  public/IconTemplate.png      → …/resources/IconTemplate.png
  public/IconTemplate@2x.png   → …/resources/IconTemplate@2x.png

electron-builder Linux wants dimension-named PNGs (16x16.png … 512x512.png).
Those live in public/icons/ for the .desktop launcher and packaging.

The ICO is architecture-independent — Windows ARM uses the same file as x64.
"""

from __future__ import annotations

import struct
import sys
from io import BytesIO
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
PUBLIC = ROOT / "public"
MASTER = PUBLIC / "icon.png"
LINUX_DIR = PUBLIC / "icons"

# electron-builder linux recommended sizes
LINUX_SIZES = (16, 24, 32, 48, 64, 96, 128, 256, 512)
# Windows ICO (256 is the modern shell size; works on ARM64 and x64).
# Written as PNG-in-ICO (Vista+), not Pillow's single-size BMP fallback.
ICO_SIZES = (16, 24, 32, 48, 64, 128, 256)
# macOS ICNS PNG types (10.8+). Keep the set small so icon.icns stays reasonable.
ICNS_TYPES = (
    ("ic07", 128),
    ("ic08", 256),
    ("ic09", 512),
    ("ic10", 1024),
)


def png_bytes(im: Image.Image, size: int) -> bytes:
    scaled = im.resize((size, size), Image.Resampling.LANCZOS)
    buf = BytesIO()
    scaled.save(buf, format="PNG")
    return buf.getvalue()


def write_icns(path: Path, master: Image.Image) -> None:
    chunks: list[bytes] = []
    for ostype, size in ICNS_TYPES:
        data = png_bytes(master, size)
        chunks.append(ostype.encode("ascii") + struct.pack(">I", 8 + len(data)) + data)
    body = b"".join(chunks)
    path.write_bytes(b"icns" + struct.pack(">I", 8 + len(body)) + body)


def write_ico(path: Path, master: Image.Image) -> None:
    """Multi-size ICO with PNG payloads. Same file for Windows x64 and ARM64."""
    images = [png_bytes(master, size) for size in ICO_SIZES]
    count = len(images)
    offset = 6 + 16 * count
    out = bytearray()
    out += struct.pack("<HHH", 0, 1, count)
    for size, data in zip(ICO_SIZES, images):
        # width/height 0 means 256 in ICONDIRENTRY
        w = 0 if size >= 256 else size
        h = 0 if size >= 256 else size
        out += struct.pack("<BBBBHHII", w, h, 0, 0, 1, 32, len(data), offset)
        offset += len(data)
    for data in images:
        out += data
    path.write_bytes(out)


def main() -> int:
    if not MASTER.is_file():
        print(f"ERROR: missing {MASTER}", file=sys.stderr)
        return 1

    master = Image.open(MASTER).convert("RGBA")
    if master.size[0] != master.size[1]:
        print(f"ERROR: {MASTER} must be square, got {master.size}", file=sys.stderr)
        return 1
    if master.size[0] < 512:
        print(f"ERROR: NativePHP wants icon.png >= 512px, got {master.size[0]}", file=sys.stderr)
        return 1

    LINUX_DIR.mkdir(parents=True, exist_ok=True)
    for size in LINUX_SIZES:
        dest = LINUX_DIR / f"{size}x{size}.png"
        dest.write_bytes(png_bytes(master, size))
        print(f"  wrote {dest.relative_to(ROOT)}")

    logo = PUBLIC / "logo.png"
    logo.write_bytes(png_bytes(master, 128))
    print(f"  wrote {logo.relative_to(ROOT)}")

    (PUBLIC / "IconTemplate.png").write_bytes(png_bytes(master, 16))
    (PUBLIC / "IconTemplate@2x.png").write_bytes(png_bytes(master, 32))
    print("  wrote public/IconTemplate.png")
    print("  wrote public/IconTemplate@2x.png")

    ico_path = PUBLIC / "icon.ico"
    write_ico(ico_path, master)
    print(f"  wrote {ico_path.relative_to(ROOT)}")

    icns_path = PUBLIC / "icon.icns"
    write_icns(icns_path, master)
    print(f"  wrote {icns_path.relative_to(ROOT)}")

    print("done")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
