<?php

namespace Tests\Feature;

use App\Models\Citation;
use App\Models\KitCard;
use App\Models\RuleSource;
use App\Support\IngestLock;
use App\Support\RuleSources;
use App\Support\TableLaw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RuleSourcesCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_seeds_live_core_enabled_and_extra_packs_off(): void
    {
        $this->assertTrue(Schema::hasTable('rule_sources'));
        $this->assertTrue(Schema::hasTable('spell_cards'));
        $this->assertTrue(Schema::hasTable('monster_cards'));
        $this->assertTrue(Schema::hasTable('kit_cards'));
        $this->assertTrue(Schema::hasTable('racial_option_cards'));
        $this->assertTrue(Schema::hasTable('citations'));
        $this->assertTrue(Schema::hasTable('legal_documents'));
        $this->assertFalse(Schema::hasColumn('citations', 'excerpt'));
        $this->assertFalse(Schema::hasColumn('spell_cards', 'excerpt'));

        foreach (RuleSources::liveCoreCodes() as $code) {
            $row = RuleSource::query()->where('code', $code)->first();
            $this->assertNotNull($row, $code);
            $this->assertTrue((bool) $row->enabled, $code);
        }

        $this->assertFalse((bool) RuleSource::query()->where('code', 'TOM')->value('enabled'));
        $this->assertFalse((bool) RuleSource::query()->where('code', 'PHB_1995')->value('enabled'));
        $this->assertSame('phb_zero', TableLaw::deathMode());
        $this->assertTrue(IngestLock::oglRowPresent());
        $this->assertFalse(IngestLock::fggAllowed());
    }

    public function test_rules_page_shows_table_law_and_live_core_sources(): void
    {
        $this->withoutVite()
            ->get('/settings/rules')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Settings/Rules')
                ->where('death_mode', 'phb_zero')
                ->has('sources')
                ->has('table_law')
                ->has('cards')
                ->has('citations')
            );
    }

    public function test_enabling_an_extra_pack_toggles_the_row(): void
    {
        $this->from('/settings/rules')
            ->post('/settings/rules/sources/TOM', ['enabled' => 1])
            ->assertRedirect();

        $this->assertTrue((bool) RuleSource::query()->where('code', 'TOM')->value('enabled'));
    }

    public function test_card_and_citation_writes_reject_excerpts(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(\InvalidArgumentException::class);
        $this->from('/settings/rules')
            ->post('/settings/rules/cards', [
                'kind' => 'kit',
                'name' => 'Swashbuckler',
                'source_code' => 'PHBR1',
                'effect_summary' => 'User stub. Fill from the fighter kit book.',
                'excerpt' => 'copied official paragraph',
            ]);
    }

    public function test_card_and_citation_writes_store_user_summaries(): void
    {
        $this->from('/settings/rules')
            ->post('/settings/rules/cards', [
                'kind' => 'kit',
                'name' => 'Swashbuckler',
                'source_code' => 'PHBR1',
                'effect_summary' => 'User stub. Fill from the fighter kit book.',
                'user_verified' => true,
            ])
            ->assertRedirect();

        $this->assertSame(1, KitCard::query()->where('name', 'Swashbuckler')->count());

        $this->from('/settings/rules')
            ->post('/settings/rules/citations', [
                'work' => "Player's Handbook",
                'year' => 1989,
                'pages' => '',
                'topic' => 'surprise',
                'source_code' => 'PHB_1989',
            ])
            ->assertRedirect();

        $this->assertSame(1, Citation::query()->where('topic', 'surprise')->count());
        $this->assertNull(Citation::query()->where('topic', 'surprise')->value('pages'));
    }

    public function test_fgg_opt_in_requires_ogl_row(): void
    {
        $this->from('/settings/rules')
            ->post('/settings/rules/fgg', ['opt_in' => 1])
            ->assertRedirect();

        $this->assertTrue(IngestLock::fggOptedIn());
        $this->assertTrue(IngestLock::fggAllowed());
        $this->assertTrue(IngestLock::allows(IngestLock::KIND_FGG));
    }
}
