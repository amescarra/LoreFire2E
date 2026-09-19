<?php

namespace App\Jobs;

use App\Support\NativePdfPrinter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Native\Laravel\Facades\Shell;
use Throwable;

class ExportPdf implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(
        public string $cacheKey,
        public string $filename,
        public string $htmlPath,
    ) {}

    public function handle(NativePdfPrinter $printer): void
    {
        $html = is_file($this->htmlPath) ? (string) file_get_contents($this->htmlPath) : '';

        try {
            if ($html === '') {
                throw new \RuntimeException('PDF HTML source is empty.');
            }

            $pdfBase64 = $printer->printToPdf($html, [
                'pageSize'        => 'Letter',
                'printBackground' => true,
                'margins'         => ['top' => 0.35, 'bottom' => 0.35, 'left' => 0.35, 'right' => 0.35],
            ]);

            if (is_string($pdfBase64) && $pdfBase64 !== '' && $this->saveAndOpenPdf($pdfBase64)) {
                Cache::put($this->cacheKey, [
                    'status'   => 'done',
                    'filename' => $this->filename,
                ], now()->addMinutes(5));

                return;
            }

            $this->storePreviewFallback($html);
        } catch (Throwable $e) {
            if ($html !== '') {
                $this->storePreviewFallback($html, $e);
            } else {
                $this->markFailed($e);
            }
        } finally {
            @unlink($this->htmlPath);
        }
    }

    public function failed(Throwable $e): void
    {
        $html = is_file($this->htmlPath) ? (string) file_get_contents($this->htmlPath) : '';
        if ($html !== '') {
            $this->storePreviewFallback($html, $e);
        } else {
            $this->markFailed($e);
        }
        @unlink($this->htmlPath);
    }

    /**
     * @return bool True when the PDF was written to Downloads.
     */
    private function saveAndOpenPdf(string $pdfBase64): bool
    {
        $bytes = base64_decode($pdfBase64, true);
        if ($bytes === false || $bytes === '') {
            return false;
        }

        $dir = $this->downloadsDirectory();
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return false;
        }

        $dest = $dir.DIRECTORY_SEPARATOR.$this->filename;
        if (@file_put_contents($dest, $bytes) === false) {
            return false;
        }

        try {
            Shell::openFile($dest);
        } catch (Throwable $e) {
            Log::warning('ExportPdf saved a PDF but could not open it', [
                'path'  => $dest,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    private function storePreviewFallback(string $html, ?Throwable $cause = null): void
    {
        $previewPath = $this->previewStoragePath();
        $dir = dirname($previewPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($previewPath, $html);

        if ($cause) {
            Log::warning('ExportPdf falling back to print preview', [
                'error' => $cause->getMessage(),
                'key'   => $this->cacheKey,
            ]);
        } else {
            Log::info('ExportPdf falling back to print preview (NativePHP printToPDF unavailable)', [
                'key' => $this->cacheKey,
            ]);
        }

        Cache::put($this->cacheKey, [
            'status'      => 'preview',
            'preview_url' => '/pdf-export/preview?key='.urlencode($this->cacheKey),
            'filename'    => $this->filename,
            'message'     => 'Native PDF export is unavailable. Use Print and choose Save as PDF.',
        ], now()->addMinutes(15));
    }

    private function markFailed(Throwable $e): void
    {
        Cache::put($this->cacheKey, [
            'status' => 'failed',
            'error'  => $e->getMessage(),
        ], now()->addMinutes(5));
        Log::error('ExportPdf failed', ['error' => $e->getMessage(), 'key' => $this->cacheKey]);
    }

    private function downloadsDirectory(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return (getenv('USERPROFILE') ?: sys_get_temp_dir()).DIRECTORY_SEPARATOR.'Downloads';
        }

        return rtrim(getenv('HOME') ?: sys_get_temp_dir(), '/').'/Downloads';
    }

    public function previewStoragePath(): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $this->cacheKey) ?: 'export';

        return storage_path('app/pdf-previews/'.$safe.'.html');
    }
}
