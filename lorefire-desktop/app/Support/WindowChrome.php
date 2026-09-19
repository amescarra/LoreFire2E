<?php

namespace App\Support;

use Native\Laravel\Facades\Window;
use Throwable;

/**
 * NativePHP window actions for custom title-bar chrome.
 *
 * Electron on Linux/Windows does not draw traffic lights when
 * titleBarStyle is hidden. The renderer calls these endpoints when
 * @electron/remote is unavailable.
 */
class WindowChrome
{
    public const MAIN_ID = 'main';

    public const DEFAULT_WIDTH = 1280;

    public const DEFAULT_HEIGHT = 800;

    public function minimize(?string $id = self::MAIN_ID): bool
    {
        return $this->call(static fn () => Window::minimize($id));
    }

    public function maximize(?string $id = self::MAIN_ID): bool
    {
        return $this->call(static fn () => Window::maximize($id));
    }

    /**
     * NativePHP 1.3 has no unmaximize(). Resize to the remembered default.
     */
    public function restore(?string $id = self::MAIN_ID): bool
    {
        return $this->call(static fn () => Window::resize(self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT, $id));
    }

    public function close(?string $id = self::MAIN_ID): bool
    {
        return $this->call(static fn () => Window::close($id));
    }

    private function call(callable $action): bool
    {
        try {
            $action();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
