<?php

namespace Tests\Unit;

use App\Support\RuleSources;
use PHPUnit\Framework\TestCase;

class RuleSourcesTest extends TestCase
{
    public function test_live_core_sources_are_enabled_in_the_catalog(): void
    {
        $byCode = [];
        foreach (RuleSources::catalog() as $row) {
            $byCode[$row['code']] = $row;
            foreach (['code', 'title', 'year', 'tsr_number', 'era', 'enabled', 'citation_only', 'notes'] as $field) {
                $this->assertArrayHasKey($field, $row, $row['code']);
            }
        }

        foreach (['PHB_1989', 'DMG_1989', 'PHBR1', 'PHBR5', 'PHBR7', 'PHBR11', 'PHBR15', 'DMGR7', 'PHBR6', 'PHBR10', 'MC1', 'MC2', 'MC3_FR', 'MC11_FR'] as $code) {
            $this->assertArrayHasKey($code, $byCode, $code);
            $this->assertTrue($byCode[$code]['enabled'], $code.' should be live core enabled');
        }

        $this->assertSame('2101', $byCode['PHB_1989']['tsr_number']);
        $this->assertSame(1989, $byCode['PHB_1989']['year']);
        $this->assertSame('2100', $byCode['DMG_1989']['tsr_number']);
        $this->assertSame('2104', $byCode['MC3_FR']['tsr_number']);
        $this->assertSame('2125', $byCode['MC11_FR']['tsr_number']);
        $this->assertContains('PHB_1989', RuleSources::liveCoreCodes());
    }

    public function test_extra_packs_and_later_2e_start_disabled(): void
    {
        $byCode = [];
        foreach (RuleSources::catalog() as $row) {
            $byCode[$row['code']] = $row;
        }

        foreach (['TOM', 'LNL_2E', 'MMYTH', 'AEG', 'DMGR1', 'DMGR2', 'MC4', 'MM_1993'] as $code) {
            $this->assertFalse($byCode[$code]['enabled'], $code);
            $this->assertSame(RuleSources::ERA_EXTRA_2E, $byCode[$code]['era'], $code);
        }

        foreach (['PHB_1995', 'DMG_1995', 'PO_CT', 'PO_SP', 'PO_SM'] as $code) {
            $this->assertFalse($byCode[$code]['enabled'], $code);
            $this->assertSame(RuleSources::ERA_LATER_2E, $byCode[$code]['era'], $code);
            $this->assertTrue($byCode[$code]['citation_only'], $code);
        }
    }

    public function test_catalog_has_no_1e_rows(): void
    {
        $blob = strtolower(json_encode(RuleSources::catalog()) ?: '');
        $this->assertStringNotContainsString('deities & demigods', $blob);
        $this->assertStringNotContainsString('"year":1977', $blob);
        $this->assertStringNotContainsString('1st edition', $blob);
        $this->assertStringNotContainsString('unearthed arcana', $blob);
    }

    public function test_lookup_prefers_1989_phb_over_1995(): void
    {
        $hits = RuleSources::matchQuestion('LOOKUP the 1989 PHB for surprise. Pages?');
        $this->assertNotSame([], $hits);
        $this->assertSame('PHB_1989', $hits[0]['code']);
        $this->assertNotSame('PHB_1995', $hits[0]['code']);
    }
}
