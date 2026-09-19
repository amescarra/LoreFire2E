<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Native\Laravel\Facades\System;
use Throwable;
use TypeError;

/**
 * Thin wrapper around NativePHP print APIs plus an OS-browser fallback.
 *
 * NativePHP types printToPDF() as string, but Electron's print-to-pdf IPC
 * can return a JSON body with a null `result` (unavailable under
 * native:serve on some Windows ARM hosts, oversized data-URL HTML, etc.).
 * window.print() is also a no-op in that Electron window. Callers should
 * treat a failed printToPDF as "show preview", and the preview Print
 * action should hit printWithOsDialog() then openHtmlInSystemBrowser().
 */
class NativePdfPrinter
{
    /**
     * @return string|null Base64-encoded PDF bytes, or null when NativePHP
     *                     print-to-pdf is unavailable or returned nothing.
     */
    public function printToPdf(string $html, array $settings = []): ?string
    {
        // This Electron plugin interpolates HTML into a data:text/html URL.
        // rawurlencode is required until NativePHP/desktop #53 (base64) ships.
        $payload = rawurlencode($html);

        try {
            $result = System::printToPDF($payload, $settings);
        } catch (TypeError $e) {
            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! is_string($result) || $result === '') {
            return null;
        }

        return $result;
    }

    /**
     * Ask NativePHP/Electron to show the OS print dialog (silent: false).
     *
     * Uses a short HTTP timeout: System::print() waits until the dialog
     * closes, and the NativePHP client default timeout is one hour. If the
     * same IPC family that broke printToPDF is dead, we fail fast and let
     * the caller open the HTML in the system browser.
     */
    public function printWithOsDialog(string $html): bool
    {
        try {
            $response = $this->nativeHttp()
                ->timeout(3)
                ->connectTimeout(1)
                ->post('system/print', [
                    'html'     => rawurlencode($html),
                    'printer'  => '',
                    'settings' => [
                        'silent'          => false,
                        'printBackground' => false,
                    ],
                ]);

            return $response->successful();
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Write the combined sheets (no preview toolbar) to a local HTML file
     * and open it with the OS default browser so Print / Save as PDF works.
     *
     * @return string Absolute path of the written HTML file.
     */
    public function openHtmlInSystemBrowser(string $html, string $basename): string
    {
        $dir = storage_path('app/pdf-previews');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $basename) ?: 'character-sheets';
        $safe = str_ends_with(strtolower($safe), '.html') ? $safe : $safe.'.html';
        $path = $dir.DIRECTORY_SEPARATOR.$safe;
        file_put_contents($path, $html);

        if (! $this->openPath($path)) {
            throw new \RuntimeException('Could not open the character sheets in the system browser.');
        }

        return $path;
    }

    public function openPath(string $path): bool
    {
        $uri = $this->fileUri($path);

        try {
            $response = $this->nativeHttp()
                ->timeout(3)
                ->connectTimeout(1)
                ->post('shell/open-external', ['url' => $uri]);

            if ($response->successful()) {
                return true;
            }
        } catch (Throwable $e) {
            // Fall through to the OS opener — NativePHP shell IPC may be down.
        }

        return $this->openPathWithOperatingSystem($path);
    }

    public function openPathWithOperatingSystem(string $path): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('cmd /c start "" '.escapeshellarg($path), 'r'));

            return true;
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            exec('open '.escapeshellarg($path).' > /dev/null 2>&1 &');

            return true;
        }

        exec('xdg-open '.escapeshellarg($path).' > /dev/null 2>&1 &');

        return true;
    }

    private function nativeHttp(): \Illuminate\Http\Client\PendingRequest
    {
        $base = rtrim((string) config('nativephp-internal.api_url', 'http://localhost:4000/api/'), '/').'/';

        return Http::asJson()
            ->baseUrl($base)
            ->withHeaders([
                'X-NativePHP-Secret' => (string) config('nativephp-internal.secret'),
            ]);
    }

    private function fileUri(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (preg_match('/^[A-Za-z]:\//', $normalized)) {
            return 'file:///'.$normalized;
        }

        return 'file://'.$normalized;
    }
}
