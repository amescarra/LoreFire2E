<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class WindowChromeUiTest extends TestCase
{
    public function test_custom_controls_are_wired_outside_drag_regions(): void
    {
        $root = dirname(__DIR__, 2);
        $layout = file_get_contents($root.'/resources/js/Layouts/AppLayout.tsx');
        $controls = file_get_contents($root.'/resources/js/Components/WindowControls.tsx');
        $chrome = file_get_contents($root.'/resources/js/lib/windowChrome.ts');
        $splash = file_get_contents($root.'/resources/js/Components/SplashOverlay.tsx');
        $onboarding = file_get_contents($root.'/resources/js/Pages/Onboarding/Index.tsx');
        $css = file_get_contents($root.'/resources/css/app.css');
        $provider = file_get_contents($root.'/app/Providers/NativeAppServiceProvider.php');
        $routes = file_get_contents($root.'/routes/web.php');
        $support = file_get_contents($root.'/app/Support/WindowChrome.php');
        $linux = file_get_contents($root.'/LINUX-5090.md');
        $windows = file_get_contents($root.'/WINDOWS-ARM.md');

        $this->assertIsString($layout);
        $this->assertIsString($controls);
        $this->assertIsString($chrome);
        $this->assertIsString($splash);
        $this->assertIsString($onboarding);
        $this->assertIsString($css);
        $this->assertIsString($provider);
        $this->assertIsString($routes);
        $this->assertIsString($support);
        $this->assertIsString($linux);
        $this->assertIsString($windows);

        $this->assertStringContainsString('data-testid="window-controls"', $controls);
        $this->assertStringContainsString('data-testid="window-minimize"', $controls);
        $this->assertStringContainsString('data-testid="window-maximize"', $controls);
        $this->assertStringContainsString('data-testid="window-close"', $controls);
        $this->assertStringContainsString('text-[var(--color-text-bright)]', $controls);
        $this->assertStringContainsString('window-controls', $controls);
        $this->assertStringContainsString('no-drag', $controls);
        $this->assertStringContainsString("runWindowChrome('minimize')", $controls);
        $this->assertStringContainsString("runWindowChrome('maximize'", $controls);
        $this->assertStringContainsString("runWindowChrome('close')", $controls);

        $this->assertStringContainsString("platform === 'linux' || platform === 'windows'", $chrome);
        $this->assertStringContainsString('@electron/remote', $chrome);
        $this->assertStringContainsString('/window/${action}', $chrome);
        $this->assertStringContainsString('unmaximize', $chrome);
        $this->assertStringContainsString('app?.quit', $chrome);
        $this->assertStringContainsString('window.close()', $chrome);

        $this->assertStringContainsString('<WindowControls className="-mr-6" />', $layout);
        $this->assertStringContainsString('<WindowControls />', $splash);
        $this->assertStringContainsString('<WindowControls />', $onboarding);
        $this->assertStringNotContainsString('className="drag-region absolute top-0 left-0 right-0 h-12"', $splash);

        $this->assertStringContainsString('.window-controls', $css);
        $this->assertStringContainsString('-webkit-app-region: no-drag', $css);
        $this->assertStringContainsString('-webkit-app-region: drag', $css);

        $this->assertStringContainsString('->titleBarHidden()', $provider);
        $this->assertStringContainsString('->hideMenu()', $provider);
        $this->assertStringContainsString('->closable()', $provider);
        $this->assertStringContainsString('->minimizable()', $provider);
        $this->assertStringContainsString('->maximizable()', $provider);

        $this->assertStringContainsString("Route::post('minimize'", $routes);
        $this->assertStringContainsString("Route::post('maximize'", $routes);
        $this->assertStringContainsString("Route::post('close'", $routes);
        $this->assertStringContainsString('Window::close($id)', $support);
        $this->assertStringContainsString('Window::minimize($id)', $support);
        $this->assertStringContainsString('Window::maximize($id)', $support);

        $this->assertStringContainsString('titleBarHidden()', $linux);
        $this->assertStringContainsString('custom controls', $linux);
        $this->assertStringContainsString('no-drag', $windows);
        $this->assertStringContainsString('Window::close', $windows);
    }
}
