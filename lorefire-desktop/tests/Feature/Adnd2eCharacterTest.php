<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Character;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class Adnd2eCharacterTest extends TestCase
{
    use RefreshDatabase;

    public function test_warlock_class_is_rewritten_to_mage(): void
    {
        $campaign = Campaign::factory()->create();

        $this->post(route('campaigns.characters.store', $campaign), [
            'name' => 'Hexer',
            'race' => 'Human',
            'class' => 'Warlock',
            'level' => 1,
            'strength' => 10,
            'dexterity' => 10,
            'constitution' => 10,
            'intelligence' => 14,
            'wisdom' => 10,
            'charisma' => 16,
        ])->assertRedirect();

        $character = Character::query()->where('name', 'Hexer')->firstOrFail();
        $this->assertSame('Mage', $character->class);
        $this->assertSame('d4', $character->hit_die);
    }

    public function test_creating_a_character_applies_2e_defaults(): void
    {
        $campaign = Campaign::factory()->create();

        $response = $this->post(route('campaigns.characters.store', $campaign), [
            'name' => 'Elanor',
            'race' => 'Elf',
            'class' => 'Mage',
            'subclass' => 'Illusionist',
            'level' => 1,
            'alignment' => 'Chaotic Neutral',
            'background' => 'Sage',
            'strength' => 8,
            'dexterity' => 16,
            'constitution' => 12,
            'intelligence' => 16,
            'wisdom' => 10,
            'charisma' => 12,
        ]);

        $response->assertRedirect();

        $character = Character::query()->where('name', 'Elanor')->firstOrFail();
        $this->assertSame(20, $character->thac0);
        $this->assertSame('d4', $character->hit_die);
        $this->assertSame(12, $character->speed);
        $this->assertArrayHasKey('paralyzation', $character->saving_throws);
        $this->assertGreaterThanOrEqual(1, $character->memorization[1] ?? $character->memorization['1'] ?? 0);
        $this->assertArrayNotHasKey('proficiency_bonus', $character->getAttributes());
        $this->assertArrayNotHasKey('dnd_beyond_url', $character->getAttributes());
    }

    public function test_dnd_beyond_import_is_removed(): void
    {
        $this->assertFalse(class_exists(\App\Http\Controllers\DndBeyondImportController::class));

        $campaign = Campaign::factory()->create();

        $this->post("/campaigns/{$campaign->id}/characters/import-beyond", [
            'url' => 'https://www.dndbeyond.com/characters/1',
        ])->assertStatus(405);

        $this->post('/characters/import-beyond', [
            'url' => 'https://www.dndbeyond.com/characters/1',
        ])->assertStatus(405);
    }

    public function test_overnight_rest_heals_one_and_clears_cast_marks(): void
    {
        $character = Character::factory()->create([
            'max_hp' => 10,
            'current_hp' => 4,
            'memorization_used' => [1 => 2],
        ]);

        $spell = $character->spells()->create([
            'name' => 'Magic Missile',
            'level' => 1,
            'times_memorized' => 2,
            'times_cast' => 2,
        ]);
        $this->assertTrue($spell->fresh()->is_prepared);
        $this->assertTrue($spell->fresh()->is_cast);

        $this->post(route('characters.rest.overnight', $character))->assertRedirect();

        $character->refresh();
        $spell->refresh();
        $this->assertSame(5, $character->current_hp);
        $this->assertNull($character->memorization_used);
        $this->assertSame(2, $spell->times_memorized);
        $this->assertSame(0, $spell->times_cast);
        $this->assertFalse($spell->is_cast);
        $this->assertTrue($spell->is_prepared);
    }

    public function test_hit_points_at_zero_are_slain_and_cannot_sit_below_zero(): void
    {
        $character = Character::factory()->create([
            'max_hp' => 10,
            'current_hp' => 2,
        ]);

        $this->patchJson(route('characters.hp.update', $character), [
            'current_hp' => -10,
        ])->assertOk()->assertJson([
            'current_hp' => 0,
            'vitality' => 'slain',
        ]);

        $character->refresh();
        $this->assertSame(0, $character->current_hp);
        $this->assertSame('slain', $character->vitalityState());
    }

    public function test_table_law_store_records_dual_class_house_switch(): void
    {
        $law = \App\Support\TableLaw::current();
        $this->assertSame('phb_zero', $law['death_mode']);
        $this->assertTrue($law['dual_class_house_switch']['enabled']);
        $this->assertFalse($law['dual_class_house_switch']['human_only']);
        $this->assertSame(6, $law['dual_class_house_switch']['min_original_level']);
        $this->assertSame(5, $law['dual_class_house_switch']['resume_new_level']);
        $this->assertSame(
            \App\Support\TableLaw::defaults(),
            \App\Models\AppSetting::get(\App\Support\TableLaw::SETTING_KEY)
        );
    }

    public function test_number_needed_to_hit_uses_thac0_minus_ac(): void
    {
        $character = Character::factory()->create([
            'thac0' => 18,
        ]);

        $this->assertSame(14, $character->numberNeededToHit(4));
    }

    public function test_short_and_long_rest_routes_are_gone(): void
    {
        $character = Character::factory()->create();

        $this->post("/characters/{$character->id}/rest/short")->assertNotFound();
        $this->post("/characters/{$character->id}/rest/long")->assertNotFound();
    }

    public function test_attunement_route_is_gone(): void
    {
        $character = Character::factory()->create();

        $status = $this->patch("/characters/{$character->id}/inventory/1/attune")->status();
        $this->assertContains($status, [404, 405]);
    }

    public function test_creating_a_multi_class_character_combines_combat_stats(): void
    {
        $campaign = Campaign::factory()->create();

        $this->post(route('campaigns.characters.store', $campaign), [
            'name' => 'Elara',
            'race' => 'Elf',
            'class' => 'Fighter',
            'class_path' => 'multi',
            'class_levels' => [
                ['class' => 'Fighter', 'level' => 5],
                ['class' => 'Mage', 'level' => 5],
            ],
            'level' => 5,
            'alignment' => 'Chaotic Good',
            'strength' => 16,
            'dexterity' => 14,
            'constitution' => 12,
            'intelligence' => 16,
            'wisdom' => 10,
            'charisma' => 10,
        ])->assertRedirect();

        $character = Character::query()->where('name', 'Elara')->firstOrFail();
        $this->assertSame('multi', $character->class_path);
        $this->assertSame('Fighter/Mage', $character->class);
        $this->assertSame(16, $character->thac0);
        $this->assertSame('d10/d4', $character->hit_die);
        $this->assertArrayHasKey('rod', $character->saving_throws);
        $this->assertGreaterThanOrEqual(1, $character->memorization[1] ?? $character->memorization['1'] ?? 0);
    }

    public function test_elf_fighter_mage_can_store_bladesinger_kit(): void
    {
        $campaign = Campaign::factory()->create();

        $this->post(route('campaigns.characters.store', $campaign), [
            'name' => 'Aelindra',
            'race' => 'Elf',
            'class' => 'Fighter',
            'subclass' => 'Bladesinger',
            'class_path' => 'multi',
            'class_levels' => [
                ['class' => 'Fighter', 'level' => 3],
                ['class' => 'Mage', 'level' => 3],
            ],
            'level' => 3,
            'alignment' => 'Chaotic Good',
            'strength' => 16,
            'dexterity' => 16,
            'constitution' => 12,
            'intelligence' => 16,
            'wisdom' => 10,
            'charisma' => 12,
        ])->assertRedirect();

        $character = Character::query()->where('name', 'Aelindra')->firstOrFail();
        $this->assertSame('Bladesinger', $character->subclass);
        $this->assertSame('Elf', $character->race);
        $this->assertSame('Fighter/Mage', $character->class);
    }

    public function test_character_can_store_fighter_and_psionicist_levels(): void
    {
        $campaign = Campaign::factory()->create();

        $this->post(route('campaigns.characters.store', $campaign), [
            'name' => 'Aldric',
            'race' => 'Human',
            'class' => 'Fighter',
            'class_path' => 'multi',
            'class_levels' => [
                ['class' => 'Fighter', 'level' => 10],
                ['class' => 'Psion', 'level' => 9],
            ],
            'level' => 10,
            'alignment' => 'Lawful Neutral',
            'strength' => 16,
            'dexterity' => 12,
            'constitution' => 14,
            'intelligence' => 14,
            'wisdom' => 16,
            'charisma' => 10,
            'psp_max' => 40,
            'psp_current' => 28,
            'psionic_powers' => ['Sight', 'Nudge'],
        ])->assertRedirect();

        $character = Character::query()->where('name', 'Aldric')->firstOrFail();
        $this->assertSame('multi', $character->class_path);
        $this->assertSame('Fighter/Psionicist', $character->class);
        $this->assertSame([
            ['class' => 'Fighter', 'level' => 10],
            ['class' => 'Psionicist', 'level' => 9],
        ], $character->class_levels);
        $this->assertSame(11, $character->thac0);
        $this->assertSame('d10/d6', $character->hit_die);
        $this->assertSame(40, $character->psp_max);
        $this->assertSame(28, $character->psp_current);
        $this->assertSame(['Sight', 'Nudge'], $character->psionic_powers);

        $this->post(route('campaigns.characters.store', $campaign), [
            'name' => 'Aldric Dual',
            'race' => 'Human',
            'class' => 'Psionicist',
            'class_path' => 'dual',
            'class_levels' => [
                ['class' => 'Psionicist', 'level' => 9],
                ['class' => 'Fighter', 'level' => 10],
            ],
            'level' => 10,
            'alignment' => 'Lawful Neutral',
            'strength' => 16,
            'dexterity' => 12,
            'constitution' => 14,
            'intelligence' => 14,
            'wisdom' => 16,
            'charisma' => 10,
            'psp_max' => 40,
            'psp_current' => 28,
            'psionic_powers' => ['Sight', 'Nudge'],
        ])->assertRedirect();

        $dual = Character::query()->where('name', 'Aldric Dual')->firstOrFail();
        $this->assertSame('dual', $dual->class_path);
        $this->assertSame('Psionicist → Fighter', $dual->class);
        $this->assertSame(10, $dual->level);
        $this->assertSame([
            ['class' => 'Psionicist', 'level' => 9],
            ['class' => 'Fighter', 'level' => 10],
        ], $dual->class_levels);
        $this->assertNull($dual->subclass);
        $this->assertSame(11, $dual->thac0);
        $this->assertSame(40, $dual->psp_max);
        $this->assertSame(['Sight', 'Nudge'], $dual->psionic_powers);
    }

    public function test_dual_class_rejects_original_below_sixth_on_create(): void
    {
        $campaign = Campaign::factory()->create();

        $this->post(route('campaigns.characters.store', $campaign), [
            'name' => 'Too Early',
            'race' => 'Elf',
            'class' => 'Fighter',
            'class_path' => 'dual',
            'class_levels' => [
                ['class' => 'Fighter', 'level' => 5],
                ['class' => 'Mage', 'level' => 1],
            ],
            'level' => 1,
            'strength' => 16,
            'dexterity' => 14,
            'constitution' => 12,
            'intelligence' => 14,
            'wisdom' => 10,
            'charisma' => 10,
        ])->assertSessionHasErrors('class_levels.0.level');
    }

    public function test_existing_dual_below_sixth_is_not_blocked_on_edit(): void
    {
        $campaign = Campaign::factory()->create();
        $character = Character::factory()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Grandfathered Dual',
            'race' => 'Elf',
            'class' => 'Fighter → Mage',
            'class_path' => 'dual',
            'class_levels' => [
                ['class' => 'Fighter', 'level' => 4],
                ['class' => 'Mage', 'level' => 1],
            ],
            'level' => 1,
        ]);

        $this->put(route('campaigns.characters.update', [$campaign, $character]), [
            'name' => $character->name,
            'race' => $character->race,
            'class' => 'Fighter',
            'class_path' => 'dual',
            'class_levels' => [
                ['class' => 'Fighter', 'level' => 4],
                ['class' => 'Mage', 'level' => 1],
            ],
            'level' => 1,
            'strength' => $character->strength,
            'dexterity' => $character->dexterity,
            'constitution' => $character->constitution,
            'intelligence' => $character->intelligence,
            'wisdom' => $character->wisdom,
            'charisma' => $character->charisma,
            'max_hp' => $character->max_hp,
            'current_hp' => $character->current_hp,
            'armor_class' => $character->armor_class,
            'speed' => $character->speed,
            'experience_points' => (int) ($character->experience_points ?? 0),
            'thac0' => $character->thac0,
            'copper' => (int) ($character->copper ?? 0),
            'silver' => (int) ($character->silver ?? 0),
            'electrum' => (int) ($character->electrum ?? 0),
            'gold' => (int) ($character->gold ?? 0),
            'platinum' => (int) ($character->platinum ?? 0),
        ])->assertRedirect();

        $character->refresh();
        $this->assertSame('dual', $character->class_path);
        $this->assertSame('Fighter → Mage', $character->class);
        $this->assertSame(4, $character->class_levels[0]['level']);
    }

    public function test_edit_rejects_beginning_a_second_class_below_sixth(): void
    {
        $campaign = Campaign::factory()->create();
        $character = Character::factory()->create([
            'campaign_id' => $campaign->id,
            'class' => 'Fighter',
            'class_path' => 'single',
            'class_levels' => [['class' => 'Fighter', 'level' => 5]],
            'level' => 5,
        ]);

        $this->put(route('campaigns.characters.update', [$campaign, $character]), [
            'name' => $character->name,
            'race' => $character->race,
            'class' => 'Fighter',
            'class_path' => 'dual',
            'class_levels' => [
                ['class' => 'Fighter', 'level' => 5],
                ['class' => 'Mage', 'level' => 1],
            ],
            'level' => 1,
            'strength' => $character->strength,
            'dexterity' => $character->dexterity,
            'constitution' => $character->constitution,
            'intelligence' => $character->intelligence,
            'wisdom' => $character->wisdom,
            'charisma' => $character->charisma,
            'max_hp' => $character->max_hp,
            'current_hp' => $character->current_hp,
            'armor_class' => $character->armor_class,
            'speed' => $character->speed,
            'experience_points' => (int) ($character->experience_points ?? 0),
            'thac0' => $character->thac0,
            'copper' => (int) ($character->copper ?? 0),
            'silver' => (int) ($character->silver ?? 0),
            'electrum' => (int) ($character->electrum ?? 0),
            'gold' => (int) ($character->gold ?? 0),
            'platinum' => (int) ($character->platinum ?? 0),
        ])->assertSessionHasErrors('class_levels.0.level');
    }

    public function test_condition_manager_adds_and_clears_2e_states(): void
    {
        $character = Character::factory()->create();

        $this->post(route('characters.conditions.store', $character), [
            'condition' => 'Held',
            'notes' => 'Hold Person',
        ])->assertRedirect();

        $this->assertDatabaseHas('character_conditions', [
            'character_id' => $character->id,
            'condition' => 'Held',
        ]);

        $this->post(route('characters.conditions.store', $character), [
            'condition' => 'Exhausted',
        ])->assertSessionHasErrors('condition');

        $condition = $character->conditions()->firstOrFail();
        $this->delete(route('characters.conditions.destroy', [$character, $condition]))->assertRedirect();
        $this->assertDatabaseMissing('character_conditions', ['id' => $condition->id]);
    }

    public function test_update_accepts_per_class_xp_and_derives_total(): void
    {
        $campaign = Campaign::factory()->create();
        $character = Character::factory()->create([
            'campaign_id' => $campaign->id,
            'name' => 'Ailduin',
            'race' => 'Elf',
            'class' => 'Fighter/Mage',
            'class_path' => 'multi',
            'class_levels' => [
                ['class' => 'Fighter', 'level' => 11],
                ['class' => 'Mage', 'level' => 12],
            ],
            'level' => 12,
            'experience_points' => 0,
        ]);

        $this->put(route('campaigns.characters.update', [$campaign, $character]), [
            'name' => $character->name,
            'race' => $character->race,
            'class' => 'Fighter',
            'class_path' => 'multi',
            'class_levels' => [
                ['class' => 'Fighter', 'level' => 11, 'xp' => 500000],
                ['class' => 'Mage', 'level' => 12, 'xp' => 750000],
            ],
            'level' => 12,
            'strength' => $character->strength,
            'dexterity' => $character->dexterity,
            'constitution' => $character->constitution,
            'intelligence' => $character->intelligence,
            'wisdom' => $character->wisdom,
            'charisma' => $character->charisma,
            'max_hp' => $character->max_hp,
            'current_hp' => $character->current_hp,
            'armor_class' => $character->armor_class,
            'speed' => $character->speed,
            'experience_points' => 0,
            'thac0' => $character->thac0,
            'copper' => (int) ($character->copper ?? 0),
            'silver' => (int) ($character->silver ?? 0),
            'electrum' => (int) ($character->electrum ?? 0),
            'gold' => (int) ($character->gold ?? 0),
            'platinum' => (int) ($character->platinum ?? 0),
        ])->assertRedirect();

        $character->refresh();
        $this->assertSame(500000, $character->class_levels[0]['xp']);
        $this->assertSame(750000, $character->class_levels[1]['xp']);
        $this->assertSame(1250000, (int) $character->experience_points);
    }

    public function test_create_single_class_copies_experience_points_onto_class_levels(): void
    {
        $campaign = Campaign::factory()->create();

        $this->post(route('campaigns.characters.store', $campaign), [
            'name' => 'Mira',
            'race' => 'Human',
            'class' => 'Cleric',
            'class_path' => 'single',
            'class_levels' => [
                ['class' => 'Cleric', 'level' => 10],
            ],
            'level' => 10,
            'experience_points' => 450000,
            'strength' => 12,
            'dexterity' => 10,
            'constitution' => 14,
            'intelligence' => 10,
            'wisdom' => 16,
            'charisma' => 12,
        ])->assertRedirect();

        $character = Character::query()->where('name', 'Mira')->firstOrFail();
        $this->assertSame(450000, $character->class_levels[0]['xp']);
        $this->assertSame(450000, (int) $character->experience_points);
    }

    public function test_character_list_show_and_edit_are_separate_inertia_pages(): void
    {
        $character = Character::factory()->create([
            'name' => 'Thurmbog',
            'campaign_id' => null,
            'class' => 'Fighter',
            'strength' => 18,
            'exceptional_strength' => '67',
        ]);

        $this->get(route('characters.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Characters/Index'));

        $this->get(route('characters.show', $character))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Characters/Show')
                ->where('character.id', $character->id)
                ->where('character.exceptional_strength', '67'));

        $this->get(route('characters.edit', $character))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Characters/Edit')
                ->where('character.id', $character->id));
    }
}
