<?php

namespace Tests\Unit;

use App\Support\SpellMaterialComponents;
use PHPUnit\Framework\TestCase;

class SpellMaterialComponentsTest extends TestCase
{
    public function test_bare_vsm_invents_no_materials(): void
    {
        $this->assertSame([], SpellMaterialComponents::parse('V, S, M'));
        $this->assertSame([], SpellMaterialComponents::parse('V, S'));
        $this->assertSame([], SpellMaterialComponents::parse('V, S, F'));
        $this->assertSame([], SpellMaterialComponents::parse('V, S, G'));
        $this->assertSame([], SpellMaterialComponents::parse(null, 'The caster hurls a bolt of fire at the target.'));
        $this->assertSame('pinch of sulfur', SpellMaterialComponents::parse(null, 'Material: a pinch of sulfur.')[0]['name']);
    }

    public function test_parenthetical_named_material_is_expendable(): void
    {
        $reqs = SpellMaterialComponents::parse('V, S, M (a pinch of sulfur)');
        $this->assertCount(1, $reqs);
        $this->assertSame('pinch of sulfur', $reqs[0]['name']);
        $this->assertSame(1, $reqs[0]['quantity']);
        $this->assertTrue($reqs[0]['consumed']);
        $this->assertFalse($reqs[0]['focus']);
        $this->assertContains('sulfur', SpellMaterialComponents::searchTerms($reqs[0]['name']));
    }

    public function test_splits_named_pair_without_inventing_unlisted_items(): void
    {
        $reqs = SpellMaterialComponents::parse('V, S, M (bat guano and sulfur)');
        $names = array_column($reqs, 'name');
        $this->assertEqualsCanonicalizing(['bat guano', 'sulfur'], $names);
        $this->assertTrue($reqs[0]['consumed']);
        $this->assertTrue($reqs[1]['consumed']);
    }

    public function test_named_focus_is_not_consumed(): void
    {
        $reqs = SpellMaterialComponents::parse('V, S, F (holy symbol)');
        $this->assertCount(1, $reqs);
        $this->assertSame('holy symbol', $reqs[0]['name']);
        $this->assertTrue($reqs[0]['focus']);
        $this->assertFalse($reqs[0]['consumed']);
    }

    public function test_mixed_material_and_holy_symbol_focus(): void
    {
        $reqs = SpellMaterialComponents::parse('V, S, M, F (a diamond, holy symbol)');
        $byName = [];
        foreach ($reqs as $req) {
            $byName[$req['name']] = $req;
        }
        $this->assertTrue($byName['diamond']['consumed']);
        $this->assertFalse($byName['diamond']['focus']);
        $this->assertTrue($byName['holy symbol']['focus']);
        $this->assertFalse($byName['holy symbol']['consumed']);
    }

    public function test_description_material_label_is_used(): void
    {
        $reqs = SpellMaterialComponents::parse('V, S, M', 'Material: a pinch of sulfur. Other notes stay unused.');
        $this->assertCount(1, $reqs);
        $this->assertSame('pinch of sulfur', $reqs[0]['name']);
        $this->assertTrue($reqs[0]['consumed']);
    }

    public function test_focus_label_is_not_consumed(): void
    {
        $reqs = SpellMaterialComponents::parse('V, S, M', 'Focus: crystal sphere (not consumed).');
        $this->assertCount(1, $reqs);
        $this->assertSame('crystal sphere', $reqs[0]['name']);
        $this->assertTrue($reqs[0]['focus']);
        $this->assertFalse($reqs[0]['consumed']);
    }

    public function test_quantity_and_value_clauses(): void
    {
        $petals = SpellMaterialComponents::parse('V, S, M (3 rose petals)');
        $this->assertSame('rose petals', $petals[0]['name']);
        $this->assertSame(3, $petals[0]['quantity']);

        $times = SpellMaterialComponents::parse('V, S, M (3× rose petals)');
        $this->assertSame('rose petals', $times[0]['name']);
        $this->assertSame(3, $times[0]['quantity']);

        $pearl = SpellMaterialComponents::parse('V, S, M (a pearl worth 100 gp)');
        $this->assertSame('pearl', $pearl[0]['name']);
        $this->assertSame(1, $pearl[0]['quantity']);
    }

    public function test_parsed_requirements_always_include_a_quantity_key(): void
    {
        foreach (SpellMaterialComponents::parse('V, S, M (sulfur)') as $req) {
            $this->assertArrayHasKey('quantity', $req);
            $this->assertSame(1, $req['quantity']);
        }
        $this->assertSame(3, SpellMaterialComponents::parse('V, S, M (3 rose petals)')[0]['quantity']);
        $this->assertSame(3, SpellMaterialComponents::parse('V, S, M (3× rose petals)')[0]['quantity']);
    }

    public function test_inventory_name_matching_prefers_exact_then_contains(): void
    {
        $this->assertGreaterThan(
            SpellMaterialComponents::nameScore('Pinch of Sulfur', ['sulfur']),
            SpellMaterialComponents::nameScore('Sulfur', ['sulfur']),
        );
        $this->assertGreaterThan(0, SpellMaterialComponents::nameScore('Wooden Holy Symbol', ['holy symbol']));
        $this->assertGreaterThan(0, SpellMaterialComponents::nameScore('bat guano', ['Bat Guano (pouch)']));
        $this->assertSame(0, SpellMaterialComponents::nameScore('Longsword', ['sulfur']));
    }

    public function test_error_message_lists_missing_materials(): void
    {
        $this->assertSame(
            'Cannot cast Fireball: missing sulfur.',
            SpellMaterialComponents::errorMessage('Fireball', ['sulfur'], []),
        );
        $this->assertSame(
            'Cannot cast Hold Person: missing focus (holy symbol).',
            SpellMaterialComponents::errorMessage('Hold Person', [], ['holy symbol']),
        );
    }
}
