<?php

namespace Tests\Unit;

use App\Support\Adnd2eCreatureStubs;
use PHPUnit\Framework\TestCase;

class Adnd2eCreatureStubsTest extends TestCase
{
    public function test_orc_stub_is_labels_only(): void
    {
        $row = Adnd2eCreatureStubs::find('Orc');

        $this->assertNotNull($row);
        $this->assertSame(6, $row['ac']);
        $this->assertSame('1', $row['hd']);
        $this->assertSame(19, $row['thac0']);
        $this->assertSame('1d8', $row['dmg']);

        $line = Adnd2eCreatureStubs::formatRow($row);
        $this->assertStringContainsString('THAC0 19', $line);
        $this->assertStringNotContainsStringIgnoringCase('tribal', $line);
        $this->assertStringNotContainsStringIgnoringCase('ecology', $line);
    }
}
