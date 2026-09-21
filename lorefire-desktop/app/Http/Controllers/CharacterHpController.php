<?php

namespace App\Http\Controllers;

use App\Models\Character;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles direct HP patching during live play.
 * Called via fetch() from the Live Session page CharacterCard component.
 */
class CharacterHpController extends Controller
{
    /**
     * PATCH /characters/{character}/hp
     *
     * Accepts { current_hp } and persists it. Current HP is clamped at 0 (slain).
     * The old DMG optional survival to -10 is not used.
     * Returns JSON so the client can confirm the saved values.
     */
    public function update(Request $request, Character $character): JsonResponse
    {
        $validated = $request->validate([
            'current_hp' => ['required', 'integer'],
        ]);

        $next = \App\Support\Adnd2e::clampCurrentHp(
            (int) $validated['current_hp'],
            (int) $character->max_hp,
        );

        $character->update([
            'current_hp' => $next,
        ]);

        return response()->json([
            'current_hp' => $character->current_hp,
            'vitality' => $character->vitalityState(),
        ]);
    }
}
