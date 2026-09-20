<?php

namespace App\Providers;

use App\Support\AppTemp;
use App\Support\NativeSqliteMigrator;
use App\Support\WindowsArm64Php;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        AppTemp::install();

        if (PHP_SAPI === 'cli') {
            WindowsArm64Php::applyToEnvironment();
        }

        Event::listen(CommandFinished::class, [NativeSqliteMigrator::class, 'afterMigrateCommand']);
    }
}
