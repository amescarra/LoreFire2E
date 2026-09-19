<?php

namespace Tests\Unit;

use App\Support\Adnd2eEquipmentCatalog;
use PHPUnit\Framework\TestCase;

class Adnd2eEquipmentCatalogTest extends TestCase
{
    public function test_plate_mail_plus_one_is_descending_ac_2(): void
    {
        $row = Adnd2eEquipmentCatalog::find('Plate mail +1');

        $this->assertNotNull($row);
        $this->assertSame('armor', $row['type']);
        $this->assertSame(2, $row['ac']);
        $this->assertSame(50.0, $row['weight']);
        $this->assertSame(1, $row['bonus']);
        $this->assertStringContainsString('AC 2', Adnd2eEquipmentCatalog::formatRow($row));
        $this->assertStringContainsString('bonus +1', Adnd2eEquipmentCatalog::formatRow($row));
    }

    public function test_long_sword_plus_two_keeps_dice_and_records_bonus(): void
    {
        $row = Adnd2eEquipmentCatalog::find('Long sword +2');

        $this->assertNotNull($row);
        $this->assertSame('weapon', $row['type']);
        $this->assertSame('1d8', $row['sm']);
        $this->assertSame('1d12', $row['l']);
        $this->assertSame(5, $row['speed']);
        $this->assertSame(2, $row['bonus']);
    }

    public function test_mundane_plate_weight_and_artifact_tags(): void
    {
        $plate = Adnd2eEquipmentCatalog::find('Plate mail');
        $this->assertNotNull($plate);
        $this->assertSame(3, $plate['ac']);
        $this->assertSame(50.0, $plate['weight']);
        $this->assertSame(0, $plate['bonus']);

        $kas = Adnd2eEquipmentCatalog::findArtifact('Sword of Kas');
        $this->assertNotNull($kas);
        $this->assertSame(6, $kas['bonus']);
        $this->assertContains('weapon', $kas['tags']);
        $this->assertStringNotContainsStringIgnoringCase('lore', Adnd2eEquipmentCatalog::formatArtifact($kas));
    }
}
