<?php

namespace Tests\Feature;

use App\Support\WindowChrome;
use Native\Laravel\Facades\Window;
use Tests\TestCase;

class WindowChromeTest extends TestCase
{
    public function test_minimize_calls_native_window(): void
    {
        Window::shouldReceive('minimize')->once()->with(WindowChrome::MAIN_ID);

        $this->postJson('/window/minimize')
            ->assertOk()
            ->assertJson(['ok' => true, 'action' => 'minimize']);
    }

    public function test_maximize_calls_native_window(): void
    {
        Window::shouldReceive('maximize')->once()->with(WindowChrome::MAIN_ID);

        $this->postJson('/window/maximize')
            ->assertOk()
            ->assertJson(['ok' => true, 'action' => 'maximize', 'state' => 'maximized']);
    }

    public function test_maximize_restore_resizes_to_default(): void
    {
        Window::shouldReceive('resize')->once()->with(
            WindowChrome::DEFAULT_WIDTH,
            WindowChrome::DEFAULT_HEIGHT,
            WindowChrome::MAIN_ID
        );

        $this->postJson('/window/maximize', ['restore' => true])
            ->assertOk()
            ->assertJson(['ok' => true, 'action' => 'maximize', 'state' => 'restored']);
    }

    public function test_close_calls_native_window(): void
    {
        Window::shouldReceive('close')->once()->with(WindowChrome::MAIN_ID);

        $this->postJson('/window/close')
            ->assertOk()
            ->assertJson(['ok' => true, 'action' => 'close']);
    }
}
