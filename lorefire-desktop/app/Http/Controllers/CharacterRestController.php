<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Character;
use App\Support\Adnd2e;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * AD&D 2E rest: an overnight rest recovers 1 hit point and rememorizes spells.
 * There is no 5e short rest.
 */
class CharacterRestController extends Controller
{
    public function overnight(Request $request, Character $character): RedirectResponse
    {
        $wasAlive = (int) $character->current_hp > 0;
        $result = Adnd2e::overnightRest(
            (int) $character->current_hp,
            (int) $character->max_hp,
            (string) $character->class,
            (int) $character->level,
            $character->class_features ?? [],
        );

        $character->update($result);
        $character->spells()->update(Adnd2e::rememorizeSpellFields());

        $message = $wasAlive
            ? 'Overnight rest taken. One hit point recovered; memorized spells are available again.'
            : 'Overnight rest taken. Slain characters do not recover hit points; memorized spells are available again.';

        return back()->with('success', $message);
    }

    public function overnightForCampaign(Request $request, Campaign $campaign, Character $character): RedirectResponse
    {
        return $this->overnight($request, $character);
    }
}
