<?php

namespace App\Providers;

use App\Services\PythonSetupService;
use App\Support\NativeSqliteMigrator;
use Native\Laravel\Facades\Window;
use Native\Laravel\Contracts\ProvidesPhpIni;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // Hidden title bar: macOS keeps traffic lights; Linux/Windows do not.
        // Custom controls in AppLayout call Window::minimize/maximize/close.
        Window::open()
            ->width(1280)
            ->height(800)
            ->minWidth(900)
            ->minHeight(600)
            ->title('Lorefire')
            ->titleBarHidden()
            ->trafficLightPosition(6, 17)
            ->hideMenu()
            ->minimizable()
            ->maximizable()
            ->closable()
            ->rememberState();

        try {
            if (! app()->environment('testing')) {
                NativeSqliteMigrator::migrateForce();
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[NativeApp] native:migrate failed: '.$e->getMessage());
        }

        // Kick off Python/WhisperX venv setup in the background on every boot.
        // - If the venv already exists and whisperx imports cleanly, marks 'ready' instantly.
        // - If the venv is missing or broken, runs setup asynchronously.
        //   linux-5090 (Linux x86_64 + NVIDIA) uses CUDA wheels; Windows ARM / CPU hosts stay CPU.
        // - Reaps a stuck `running` job (timeout / silent log) before starting another.
        try {
            if (! app()->environment('testing')) {
                \App\Support\Linux5090::applyRuntimeDefaults();
            }
            app(PythonSetupService::class)->bootCheck();
        } catch (\Throwable $e) {
            // Never crash the app over Python setup
            \Illuminate\Support\Facades\Log::warning('[NativeApp] PythonSetup bootCheck failed: ' . $e->getMessage());
        }
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
            'memory_limit'       => '512M',
            'upload_max_filesize' => '500M',
            'post_max_size'       => '512M',
        ];
    }
}
