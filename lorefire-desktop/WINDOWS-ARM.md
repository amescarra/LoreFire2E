# Windows on ARM (`native:serve`)

Lorefire 2E stays **ARM-native** on Windows ARM64: ARM Node, ARM Electron, ARM PHP. Do **not** install x64 Node or run NativePHP under Prism / x64 emulation.

## What you need

| Tool | Arch | How |
|---|---|---|
| Node.js 22+ | **ARM64** | [nodejs.org](https://nodejs.org) Windows ARM64 installer, or `winget install OpenJS.NodeJS` on an ARM machine |
| PHP 8.4 ZTS | **ARM64** | `winget install PHP.PHP.8.4` (openssl + pdo_sqlite) |
| Composer | any | uses the ARM `php.exe` on PATH |

Confirm before serving:

```powershell
node -p process.arch   # must be arm64
php -r "echo php_uname('m'), PHP_EOL;"   # must be ARM64
```

Then from `lorefire-desktop`:

```powershell
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan native:serve
```

`php artisan native:serve` is the supported command. A helper script `scripts/native-serve.ps1` only checks the arches and then runs that same command.

## Why this fork patches NativePHP

`vendor/nativephp/electron/resources/js/php.js` sets `platform.arch=arm64` on ARM Windows, then unzips:

```
vendor/nativephp/php-bin/bin/win/arm64/php-8.4.zip
```

**That zip does not exist.** `nativephp/php-bin`, static-php-cli, and windows.php.net ship Windows **x64/x86 only**. There is no usable official Windows ARM64 PHP zip (experimental php-src ARM builds lack sqlite/openssl/pdo).

This repo applies a Composer patch (`patches/nativephp-electron-windows-arm64-system-php.patch`) so that on **Windows ARM64 serve**:

1. The missing php-bin zip is **not** treated as a hard failure.
2. Electron stays ARM64 (`process.arch` is not rewritten to x64).
3. Electron launches the **system ARM64 PHP** (`NATIVEPHP_PHP_EXECUTABLE` / `PHP_BINARY`, else `php.exe` on PATH) **in place**. A copied `php.exe` alone cannot load `php8.dll` + `ext/`.
4. Packaged Windows ARM installers still cannot ship a static NativePHP ARM `php.exe` — that binary does not exist upstream. Packaging ARM is out of scope; `native:serve` is not blocked.

`vendor/` is not committed. `composer install` re-applies the patch. Do not drop a one-off `php.exe` into vendor; it will be wiped.

## What not to do

- Do **not** install x64 Node so php-bin’s `win/x64` zip can be used. That is x64 PHP + x64 Electron under emulation.
- Do **not** set `platform.arch=x64` in `php.js` on ARM Windows.
- Do **not** vendor a fake `php-8.4.zip`.

Optional override if artisan is not already the ARM binary:

```
NATIVEPHP_PHP_EXECUTABLE=C:\path\to\arm64\php.exe
```

## Window chrome

The NativePHP window is **frameless** (`titleBarHidden()`). Windows ARM has no OS traffic lights, so Lorefire draws the same custom **minimize / maximize / close** buttons as Linux (right side of the title bar). They are `no-drag` so they stay clickable next to the drag region. Close calls Electron `window.close()` (NativePHP `Window::close('main')` as fallback).

## Packaging

`php artisan native:build win arm64` remains blocked until NativePHP ships `bin/win/arm64`. Use `native:serve` for ARM development. x64 Windows packages are a separate (emulated) path and are not the goal here.
