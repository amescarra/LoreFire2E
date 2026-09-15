<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class NavigationUiTest extends TestCase
{
    public function test_splash_and_settings_poll_without_inertia_reload(): void
    {
        $root = dirname(__DIR__, 2);
        $splash = file_get_contents($root.'/resources/js/Components/SplashOverlay.tsx');
        $settings = file_get_contents($root.'/resources/js/Pages/Settings/Index.tsx');
        $onboarding = file_get_contents($root.'/resources/js/Pages/Onboarding/Index.tsx');
        $layout = file_get_contents($root.'/resources/js/Layouts/AppLayout.tsx');
        $app = file_get_contents($root.'/resources/js/app.tsx');
        $nav = file_get_contents($root.'/resources/js/lib/navigation.ts');

        $this->assertIsString($splash);
        $this->assertIsString($settings);
        $this->assertIsString($onboarding);
        $this->assertIsString($layout);
        $this->assertIsString($app);
        $this->assertIsString($nav);

        $this->assertStringContainsString('sessionOverlay', $splash);
        $this->assertStringContainsString('fetchPythonSetupStatus', $splash);
        $this->assertStringContainsString('data-testid="splash-continue"', $splash);
        $this->assertStringNotContainsString('router.reload', $splash);
        $this->assertStringNotContainsString('className="fixed inset-0 z-[9999] flex flex-col items-center justify-center gap-8 drag-region"', $splash);

        $this->assertStringContainsString('fetchPythonSetupStatus', $settings);
        $this->assertStringNotContainsString('router.reload', $settings);
        $this->assertStringContainsString('fetchPythonSetupStatus', $onboarding);
        $this->assertStringNotContainsString("only: ['python_setup']", $onboarding);

        $this->assertStringContainsString('data-testid="nav-back"', $layout);
        $this->assertStringContainsString('goBack()', $layout);
        $this->assertStringContainsString('drag-region flex-1 h-full min-w-[64px]', $layout);
        $this->assertStringNotContainsString('className="drag-region shrink-0 flex items-center gap-3 px-6 h-12 border-b"', $layout);

        $this->assertStringContainsString('installHistoryGuard', $app);
        $this->assertStringContainsString('history.back()', $nav);
        $this->assertStringContainsString('location.replace', $nav);
    }
}
