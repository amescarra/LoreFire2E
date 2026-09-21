<?php

namespace Tests\Unit;

use App\Support\Adnd2eSpellCatalog;
use PHPUnit\Framework\TestCase;

class Adnd2eSpellCatalogTest extends TestCase
{
    public function test_fireball_is_mage_level_3_header_only(): void
    {
        $row = Adnd2eSpellCatalog::find('Fireball');

        $this->assertNotNull($row);
        $this->assertSame(['Mage'], $row['classes']);
        $this->assertSame(3, $row['level']);
        $this->assertSame('invocation', $row['tag']);
        $this->assertSame('V, S, M', $row['components']);
        $this->assertSame(['bat guano', 'sulfur'], $row['materials']);
        $this->assertStringContainsString('Mage L3', Adnd2eSpellCatalog::formatRow($row));
        $this->assertStringNotContainsStringIgnoringCase('explosive', Adnd2eSpellCatalog::formatRow($row));
    }

    public function test_cure_light_wounds_is_cleric_and_paladin_level_1(): void
    {
        $row = Adnd2eSpellCatalog::find('Cure Light Wounds');

        $this->assertNotNull($row);
        $this->assertSame(1, $row['level']);
        $this->assertContains('Cleric', $row['classes']);
        $this->assertContains('Paladin', $row['classes']);
        $this->assertSame('Healing', $row['tag']);
    }

    public function test_class_level_list_returns_header_names_only(): void
    {
        $mage1 = Adnd2eSpellCatalog::forClass('Mage', 1);
        $names = array_map(fn (array $row) => $row['name'], $mage1);

        $this->assertContains('Magic Missile', $names);
        $this->assertContains('Sleep', $names);
        $this->assertNotContains('Fireball', $names);
    }

    public function test_find_best_disambiguates_hold_person_by_class_and_level(): void
    {
        $mage = Adnd2eSpellCatalog::findBest('Hold Person', 'Mage', 3);
        $this->assertNotNull($mage);
        $this->assertSame(['iron'], $mage['materials']);
        $this->assertSame('V, S, M', $mage['components']);

        $cleric = Adnd2eSpellCatalog::findBest('Hold Person', 'Cleric', 2);
        $this->assertNotNull($cleric);
        $this->assertSame(['holy symbol'], $cleric['materials']);
        $this->assertSame('V, S, F', $cleric['components']);

        $byLevel = Adnd2eSpellCatalog::findBest('Hold Person', null, 2);
        $this->assertSame(['holy symbol'], $byLevel['materials']);
    }

    public function test_components_with_materials_appends_catalog_names(): void
    {
        $row = Adnd2eSpellCatalog::find('Fireball');
        $this->assertSame('V, S, M (bat guano, sulfur)', Adnd2eSpellCatalog::componentsWithMaterials($row));
        $this->assertSame(
            'V, S, M (bat guano, sulfur)',
            Adnd2eSpellCatalog::componentsWithMaterials($row, 'V, S, M'),
        );

        $missile = Adnd2eSpellCatalog::find('Magic Missile');
        $this->assertSame('V, S', Adnd2eSpellCatalog::componentsWithMaterials($missile));
    }
}
