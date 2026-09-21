<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Character;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Toggle Vancian memorization capacity used for a spell level.
 */
class CharacterSpellSlotsController extends Controller
{
    public function update(Request $request, Character $character): RedirectResponse
    {
        $data = $request->validate([
            'level' => 'required|integer|min:1|max:9',
            'action' => 'required|in:use,recover',
        ]);

        $level = (string) $data['level'];
        $capacity = is_array($character->memorization) ? $character->memorization : [];
        $used = is_array($character->memorization_used) ? $character->memorization_used : [];

        $max = (int) ($capacity[$level] ?? 0);
        $curr = (int) ($used[$level] ?? 0);

        if ($data['action'] === 'use') {
            $curr = min($curr + 1, $max);
        } else {
            $curr = max($curr - 1, 0);
        }

        $used[$level] = $curr;

        $character->update(['memorization_used' => $used]);

        return back()->with('success', 'Memorization updated.');
    }

    public function updateForCampaign(Request $request, Campaign $campaign, Character $character): RedirectResponse
    {
        return $this->update($request, $character);
    }
}
