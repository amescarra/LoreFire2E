"""App-owned temp for LoreFire ffmpeg/enrollment work.

Never create unbounded /tmp/lorefire_ffmpeg_* directories. Live WhisperX
used to copy imageio's ffmpeg (~77MB) into a new mkdtemp on every slice;
those leftovers filled Ubuntu tmpfs and stalled chunk writes.

This module:
  * puts a single stably-named ffmpeg alias under LOREFIRE_TMP / XDG cache
  * sweeps leftover lorefire_ffmpeg_* and lorefire-enroll-* dirs/files
  * enforces a size + TTL budget on the app temp tree
"""

from __future__ import annotations

import argparse
import os
import shutil
import sys
import tempfile
import time

FFMPEG_DIR_PREFIX = "lorefire_ffmpeg_"
ENROLL_PREFIX = "lorefire-enroll-"
ALIAS_DIRNAME = "ffmpeg-alias"
DEFAULT_MAX_AGE = 600  # 10 minutes — in-flight unique dirs from old builds
DEFAULT_MAX_BYTES = 512 * 1024 * 1024


def tmp_root() -> str:
    override = os.environ.get("LOREFIRE_TMP")
    if override:
        os.makedirs(override, exist_ok=True)
        return os.path.realpath(override)

    xdg = os.environ.get("XDG_CACHE_HOME")
    if xdg:
        path = os.path.join(xdg, "lorefire")
    else:
        path = os.path.join(os.path.expanduser("~"), ".cache", "lorefire")
    os.makedirs(path, exist_ok=True)
    return os.path.realpath(path)


def enroll_dir() -> str:
    path = os.path.join(tmp_root(), "enroll")
    os.makedirs(path, exist_ok=True)
    return path


def cleanup_path(path: str | None) -> None:
    if not path:
        return
    try:
        if os.path.isdir(path) and not os.path.islink(path):
            shutil.rmtree(path, ignore_errors=True)
        elif os.path.lexists(path):
            os.unlink(path)
    except OSError:
        pass


def _candidate_temp_roots() -> list[str]:
    seen: set[str] = set()
    out: list[str] = []
    for raw in (
        tempfile.gettempdir(),
        os.environ.get("TMPDIR"),
        os.environ.get("TMP"),
        os.environ.get("TEMP"),
        "/tmp",
        tmp_root(),
    ):
        if not raw:
            continue
        if not os.path.isdir(raw):
            continue
        real = os.path.realpath(raw)
        if real in seen:
            continue
        seen.add(real)
        out.append(real)
    return out


def _mtime(path: str) -> float:
    try:
        return os.path.getmtime(path)
    except OSError:
        return 0.0


def _dir_size(path: str) -> int:
    total = 0
    for root, _dirs, files in os.walk(path):
        for name in files:
            try:
                total += os.path.getsize(os.path.join(root, name))
            except OSError:
                pass
    return total


def sweep_stale(max_age_seconds: int = DEFAULT_MAX_AGE, max_bytes: int = DEFAULT_MAX_BYTES) -> int:
    """Remove leftover ffmpeg/enroll temps. Returns how many paths were removed."""
    now = time.time()
    removed = 0

    for root in _candidate_temp_roots():
        try:
            names = os.listdir(root)
        except OSError:
            continue
        for name in names:
            if not (name.startswith(FFMPEG_DIR_PREFIX) or name.startswith(ENROLL_PREFIX)):
                continue
            path = os.path.join(root, name)
            age = now - _mtime(path)
            if max_age_seconds > 0 and age < max_age_seconds:
                continue
            cleanup_path(path)
            if not os.path.lexists(path):
                removed += 1

    alias = os.path.join(tmp_root(), ALIAS_DIRNAME)
    work_entries: list[tuple[float, int, str]] = []
    total = 0
    try:
        for name in os.listdir(tmp_root()):
            path = os.path.join(tmp_root(), name)
            if os.path.realpath(path) == os.path.realpath(alias):
                continue
            if name.startswith(FFMPEG_DIR_PREFIX) or name.startswith(ENROLL_PREFIX):
                continue  # already handled
            age = now - _mtime(path)
            size = _dir_size(path) if os.path.isdir(path) else (os.path.getsize(path) if os.path.isfile(path) else 0)
            if max_age_seconds > 0 and age >= max_age_seconds and name in {"enroll", "work"}:
                cleanup_path(path)
                if not os.path.lexists(path):
                    removed += 1
                continue
            work_entries.append((age, size, path))
            total += size
    except OSError:
        work_entries = []

    if total > max_bytes:
        for _age, _size, path in sorted(work_entries, key=lambda item: item[0], reverse=True):
            if name := os.path.basename(path):
                if name == ALIAS_DIRNAME:
                    continue
            cleanup_path(path)
            if not os.path.lexists(path):
                removed += 1
            total -= _size
            if total <= max_bytes:
                break

    return removed


def _ffmpeg_source() -> str | None:
    override = os.environ.get("LOREFIRE_FFMPEG_SRC")
    if override and os.path.isfile(override):
        return override
    try:
        import imageio_ffmpeg

        src = imageio_ffmpeg.get_ffmpeg_exe()
        if src and os.path.isfile(src):
            return src
    except Exception:
        pass
    found = shutil.which("ffmpeg")
    return found if found and os.path.isfile(found) else None


def ensure_ffmpeg_on_path() -> str | None:
    """Reuse one alias directory. Never mkdtemp(prefix='lorefire_ffmpeg_')."""
    sweep_stale()
    src = _ffmpeg_source()
    if not src:
        return None

    alias_dir = os.path.join(tmp_root(), ALIAS_DIRNAME)
    os.makedirs(alias_dir, exist_ok=True)
    ext = os.path.splitext(src)[1]
    alias = os.path.join(alias_dir, "ffmpeg" + ext)
    if not os.path.exists(alias):
        tmp_alias = f"{alias}.tmp.{os.getpid()}"
        try:
            try:
                os.link(src, tmp_alias)
            except OSError:
                shutil.copy2(src, tmp_alias)
            os.replace(tmp_alias, alias)
        except OSError:
            cleanup_path(tmp_alias)
            if not os.path.exists(alias):
                return None

    os.environ["PATH"] = alias_dir + os.pathsep + os.environ.get("PATH", "")
    return alias


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="LoreFire temp/ffmpeg helper")
    parser.add_argument("command", choices=["sweep", "tmp-root", "ensure-ffmpeg"])
    parser.add_argument("--max-age", type=int, default=DEFAULT_MAX_AGE)
    parser.add_argument("--max-bytes", type=int, default=DEFAULT_MAX_BYTES)
    args = parser.parse_args(argv)

    if args.command == "tmp-root":
        print(tmp_root())
        return 0
    if args.command == "ensure-ffmpeg":
        path = ensure_ffmpeg_on_path()
        print(path or "")
        return 0 if path else 1
    removed = sweep_stale(max_age_seconds=args.max_age, max_bytes=args.max_bytes)
    print(removed)
    return 0


if __name__ == "__main__":
    sys.exit(main())
