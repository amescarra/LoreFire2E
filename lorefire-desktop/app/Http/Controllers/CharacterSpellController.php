<?php

namespace App\Http\Controllers;

use App\Models\Character;
use App\Models\CharacterSpell;
use App\Support\Adnd2e;
use App\Support\SpellMaterialComponents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CharacterSpellController extends Controller
{
    public function store(Request $request, Character $character): RedirectResponse
    {
        $data = $this->withMemorizationCounts($request->validate($this->spellRules()));

        $character->spells()->create($data);

        return back()->with('success', 'Spell added to the repertoire.');
    }

    public function update(Request $request, Character $character, CharacterSpell $spell): RedirectResponse
    {
        abort_if($spell->character_id !== $character->id, 403);

        $data = $this->withMemorizationCounts($request->validate($this->spellRules()), $spell);

        $spell->update($data);

        return back()->with('success', 'Spell updated.');
    }

    public function togglePrepared(Request $request, Character $character, CharacterSpell $spell): RedirectResponse
    {
        abort_if($spell->character_id !== $character->id, 403);

        $validated = $request->validate([
            'times_memorized' => 'nullable|integer|min:0|max:'.Adnd2e::MAX_TIMES_MEMORIZED,
        ]);

        $current = Adnd2e::effectiveTimesMemorized((int) $spell->times_memorized, (bool) $spell->is_prepared);
        $next = array_key_exists('times_memorized', $validated) && $validated['times_memorized'] !== null
            ? (int) $validated['times_memorized']
            : ($current > 0 ? 0 : 1);

        $spell->update(Adnd2e::spellVancianFlags($next, (int) $spell->times_cast));

        return back();
    }

    public function toggleCast(Request $request, Character $character, CharacterSpell $spell): RedirectResponse
    {
        abort_if($spell->character_id !== $character->id, 403);

        $times = Adnd2e::effectiveTimesMemorized((int) $spell->times_memorized, (bool) $spell->is_prepared);
        if ($times < 1) {
            return back();
        }

        $action = $request->validate([
            'action' => 'nullable|in:use,recover',
        ])['action'] ?? 'use';

        if ($action === 'recover') {
            $spell->update(Adnd2e::restoreMemorizedInstance($times, (int) $spell->times_cast));

            return back();
        }

        $result = SpellMaterialComponents::tryBurnOneCopy($character, $spell);
        if (! $result['ok']) {
            return back()->with('error', $result['error']);
        }

        return back();
    }

    public function destroy(Character $character, CharacterSpell $spell): RedirectResponse
    {
        abort_if($spell->character_id !== $character->id, 403);

        $spell->delete();

        return back()->with('success', 'Spell removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function spellRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'level' => 'required|integer|min:1|max:9',
            'school' => 'nullable|string|max:100',
            'casting_time' => 'nullable|string|max:100',
            'range' => 'nullable|string|max:100',
            'components' => 'nullable|string|max:255',
            'duration' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'is_prepared' => 'boolean',
            'is_cast' => 'boolean',
            'times_memorized' => 'nullable|integer|min:0|max:'.Adnd2e::MAX_TIMES_MEMORIZED,
            'times_cast' => 'nullable|integer|min:0|max:'.Adnd2e::MAX_TIMES_MEMORIZED,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withMemorizationCounts(array $data, ?CharacterSpell $existing = null): array
    {
        $times = array_key_exists('times_memorized', $data) ? (int) $data['times_memorized'] : null;
        $data['times_memorized'] = Adnd2e::timesMemorizedFromInput($times, $data['is_prepared'] ?? false);

        if (! array_key_exists('times_cast', $data)) {
            $data['times_cast'] = $existing ? (int) $existing->times_cast : 0;
        }

        return array_merge($data, Adnd2e::spellVancianFlags(
            (int) $data['times_memorized'],
            (int) $data['times_cast'],
        ));
    }
}
