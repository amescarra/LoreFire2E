<?php

namespace App\Console\Commands;

use App\Support\NativeAppIcon;
use Illuminate\Console\Command;

class PublishNativeAppIcon extends Command
{
    protected $signature = 'lorefire:publish-native-icon';

    protected $description = 'Copy public/icon.png (G3) into NativePHP Electron resources/build (and out/) for native:serve';

    public function handle(): int
    {
        $src = NativeAppIcon::publicPng();
        if (! is_file($src)) {
            $this->error('Missing '.$src);

            return self::FAILURE;
        }

        $written = NativeAppIcon::publish();
        if ($written === []) {
            $this->warn('No Electron icon paths written. Is vendor/nativephp/electron installed?');

            return self::SUCCESS;
        }

        foreach ($written as $dest) {
            $this->line('  '.$dest);
        }
        $this->info('Published '.count($written).' icon file(s) for WM_CLASS '.NativeAppIcon::SERVE_WM_CLASS);

        return self::SUCCESS;
    }
}
