<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class RuleSettingsUiTest extends TestCase
{
    public function test_rules_ui_has_no_official_paste_affordance(): void
    {
        $root = dirname(__DIR__, 2);
        $rules = file_get_contents($root.'/resources/js/Pages/Settings/Rules.tsx');
        $settings = file_get_contents($root.'/resources/js/Pages/Settings/Index.tsx');
        $readme = file_get_contents($root.'/README.md');

        $this->assertIsString($rules);
        $this->assertIsString($settings);
        $this->assertIsString($readme);

        $this->assertStringContainsString('Open Table Law and Sources', $settings);
        $this->assertStringContainsString('/settings/rules', $settings);

        $this->assertStringContainsString('Table Law and Sources', $rules);
        $this->assertStringContainsString('Do not paste book text', $rules);
        $this->assertStringContainsString('Bibliographic index only', $rules);
        $this->assertStringContainsString('death_mode', $rules);
        $this->assertStringContainsString('TABLE LAW', $rules);
        $this->assertStringNotContainsString('name="excerpt"', $rules);
        $this->assertStringNotContainsString('Paste official', $rules);
        $this->assertStringNotContainsString('PDF import', $rules);

        $this->assertStringContainsString('does not ingest official prose', $readme);
        $this->assertStringContainsString('Tome of Magic', $readme);
        $this->assertStringContainsString('Enable', $readme);
    }
}
