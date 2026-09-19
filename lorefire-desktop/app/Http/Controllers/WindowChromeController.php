<?php

namespace App\Http\Controllers;

use App\Support\WindowChrome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WindowChromeController extends Controller
{
    public function minimize(WindowChrome $chrome): JsonResponse
    {
        return response()->json([
            'ok' => $chrome->minimize(),
            'action' => 'minimize',
        ]);
    }

    public function maximize(Request $request, WindowChrome $chrome): JsonResponse
    {
        $restore = $request->boolean('restore');
        $ok = $restore ? $chrome->restore() : $chrome->maximize();

        return response()->json([
            'ok' => $ok,
            'action' => 'maximize',
            'state' => $restore ? 'restored' : 'maximized',
        ]);
    }

    public function close(WindowChrome $chrome): JsonResponse
    {
        return response()->json([
            'ok' => $chrome->close(),
            'action' => 'close',
        ]);
    }
}
