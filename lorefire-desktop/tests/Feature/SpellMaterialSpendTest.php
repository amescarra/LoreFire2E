<?php

namespace Tests\Feature;

use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpellMaterialSpendTest extends TestCase
{
    use RefreshDatabase;

    public function test_cast_spends_matching_inventory_when_named_materials_are_present(): void
    {
        $character = Character::factory()->create(['class' => 'Mage']);
        $spell = $character->spells()->create([
            'name' => 'Fireball',
            'level' => 3,
            'components' => 'V, S, M (a pinch of sulfur)',
            'times_memorized' => 2,
            'times_cast' => 0,
        ]);
        $sulfur = $character->inventoryItems()->create([
            'name' => 'Sulfur',
            'quantity' => 3,
        ]);

        $this->patch(route('characters.spells.cast', [$character, $spell]), [
            'action' => 'use',
        ])->assertRedirect()->assertSessionMissing('error');

        $spell->refresh();
        $sulfur->refresh();
        $this->assertSame(1, $spell->times_cast);
        $this->assertSame(2, (int) $sulfur->quantity);
    }

    public function test_cast_is_blocked_when_required_materials_are_missing(): void
    {
        $character = Character::factory()->create(['class' => 'Mage']);
        $spell = $character->spells()->create([
            'name' => 'Fireball',
            'level' => 3,
            'components' => 'V, S, M (a pinch of sulfur)',
            'times_memorized' => 1,
            'times_cast' => 0,
        ]);

        $this->patch(route('characters.spells.cast', [$character, $spell]), [
            'action' => 'use',
        ])->assertRedirect()->assertSessionHas('error');

        $this->assertStringContainsString('sulfur', session('error'));
        $this->assertSame(0, $spell->fresh()->times_cast);
        $this->assertSame(0, $character->inventoryItems()->count());
    }

    public function test_vs_only_spell_still_casts_without_inventory(): void
    {
        $character = Character::factory()->create(['class' => 'Mage']);
        $spell = $character->spells()->create([
            'name' => 'Magic Missile',
            'level' => 1,
            'components' => 'V, S',
            'times_memorized' => 1,
            'times_cast' => 0,
        ]);

        $this->patch(route('characters.spells.cast', [$character, $spell]), [
            'action' => 'use',
        ])->assertRedirect()->assertSessionMissing('error');

        $this->assertSame(1, $spell->fresh()->times_cast);
    }

    public function test_bare_vsm_with_no_item_names_does_not_require_inventory(): void
    {
        $character = Character::factory()->create(['class' => 'Mage']);
        $spell = $character->spells()->create([
            'name' => 'Sleep',
            'level' => 1,
            'components' => 'V, S, M',
            'times_memorized' => 1,
            'times_cast' => 0,
        ]);

        $this->patch(route('characters.spells.cast', [$character, $spell]), [
            'action' => 'use',
        ])->assertRedirect()->assertSessionMissing('error');

        $this->assertSame(1, $spell->fresh()->times_cast);
    }

    public function test_named_focus_is_required_but_not_consumed(): void
    {
        $character = Character::factory()->create(['class' => 'Cleric']);
        $spell = $character->spells()->create([
            'name' => 'Hold Person',
            'level' => 2,
            'components' => 'V, S, F (holy symbol)',
            'times_memorized' => 1,
            'times_cast' => 0,
        ]);
        $symbol = $character->inventoryItems()->create([
            'name' => 'Wooden Holy Symbol',
            'quantity' => 1,
        ]);

        $this->patch(route('characters.spells.cast', [$character, $spell]), [
            'action' => 'use',
        ])->assertRedirect()->assertSessionMissing('error');

        $this->assertSame(1, $spell->fresh()->times_cast);
        $this->assertSame(1, (int) $symbol->fresh()->quantity);
    }

    public function test_overnight_rest_does_not_refund_spent_materials(): void
    {
        $character = Character::factory()->create([
            'class' => 'Mage',
            'max_hp' => 10,
            'current_hp' => 8,
        ]);
        $spell = $character->spells()->create([
            'name' => 'Fireball',
            'level' => 3,
            'components' => 'V, S, M (sulfur)',
            'times_memorized' => 1,
            'times_cast' => 0,
        ]);
        $sulfur = $character->inventoryItems()->create([
            'name' => 'Sulfur',
            'quantity' => 1,
        ]);

        $this->patch(route('characters.spells.cast', [$character, $spell]), [
            'action' => 'use',
        ])->assertRedirect();

        $this->assertSame(0, $character->inventoryItems()->count());
        $this->assertSame(1, $spell->fresh()->times_cast);

        $this->post(route('characters.rest.overnight', $character))->assertRedirect();

        $spell->refresh();
        $this->assertSame(0, $spell->times_cast);
        $this->assertSame(1, $spell->times_memorized);
        $this->assertSame(0, $character->inventoryItems()->count());
        $this->assertNull($sulfur->fresh());
    }

    public function test_contains_match_spends_the_inventory_stack(): void
    {
        $character = Character::factory()->create(['class' => 'Mage']);
        $spell = $character->spells()->create([
            'name' => 'Web',
            'level' => 2,
            'description' => 'Material: spider silk.',
            'components' => 'V, S, M',
            'times_memorized' => 1,
            'times_cast' => 0,
        ]);
        $item = $character->inventoryItems()->create([
            'name' => 'Bundle of Spider Silk',
            'quantity' => 2,
        ]);

        $this->patch(route('characters.spells.cast', [$character, $spell]), [
            'action' => 'use',
        ])->assertRedirect()->assertSessionMissing('error');

        $this->assertSame(1, $spell->fresh()->times_cast);
        $this->assertSame(1, (int) $item->fresh()->quantity);
    }

    public function test_material_requirements_are_exposed_on_the_sheet(): void
    {
        $character = Character::factory()->create(['class' => 'Mage']);
        $character->spells()->create([
            'name' => 'Fireball',
            'level' => 3,
            'components' => 'V, S, M (sulfur)',
            'times_memorized' => 1,
        ]);
        $character->inventoryItems()->create([
            'name' => 'Sulfur',
            'quantity' => 1,
        ]);

        $this->get(route('characters.show', $character))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Characters/Show')
                ->where('character.spells.0.material_requirements.0.name', 'sulfur')
                ->where('character.spells.0.material_requirements.0.quantity', 1)
                ->where('character.spells.0.material_requirements.0.consumed', true)
            );
    }

    public function test_material_requirements_surface_leading_counts(): void
    {
        $character = Character::factory()->create(['class' => 'Mage']);
        $character->spells()->create([
            'name' => 'Custom Petal Charm',
            'level' => 1,
            'components' => 'V, S, M (3 rose petals)',
            'times_memorized' => 1,
        ]);

        $this->get(route('characters.show', $character))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Characters/Show')
                ->where('character.spells.0.material_requirements.0.name', 'rose petals')
                ->where('character.spells.0.material_requirements.0.quantity', 3)
                ->where('character.spells.0.material_requirements.0.consumed', true)
                ->where('character.spells.0.material_requirements.0.focus', false)
            );
    }
}
