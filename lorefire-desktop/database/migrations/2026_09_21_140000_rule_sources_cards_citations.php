<?php

use App\Models\KitCard;
use App\Models\MonsterCard;
use App\Support\IngestLock;
use App\Support\RuleSources;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2E bibliographic catalog, card stubs, citations (no excerpt), ingest allowlist.
 * death_mode stays phb_zero via TableLaw.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->unsignedSmallInteger('year');
            $table->string('tsr_number', 16);
            $table->string('era', 32);
            $table->boolean('enabled')->default(false);
            $table->boolean('citation_only')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $this->cardTable('spell_cards');
        $this->cardTable('monster_cards');
        $this->cardTable('kit_cards');
        $this->cardTable('racial_option_cards');

        Schema::create('citations', function (Blueprint $table) {
            $table->id();
            $table->string('work');
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('pages', 64)->nullable();
            $table->string('topic');
            $table->string('source_code')->nullable();
            $table->timestamps();
            // No excerpt column. Bibliographic index only.
        });

        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('title');
            $table->string('kind', 32);
            $table->boolean('opted_in')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        RuleSources::seed();
        IngestLock::seedLegalDocuments();

        KitCard::query()->updateOrCreate(
            ['name' => 'Bladesinger'],
            [
                'source_code' => 'PHBR8',
                'effect_summary' => 'User stub. Elf fighter/mage kit name. Fill from the race book with it open.',
                'citation_work' => 'The Complete Book of Elves',
                'citation_year' => 1992,
                'citation_pages' => null,
                'citation_topic' => 'Bladesinger kit',
                'user_verified' => false,
            ]
        );

        MonsterCard::query()->updateOrCreate(
            ['name' => 'Orc'],
            [
                'source_code' => 'MC1',
                'effect_summary' => 'User stub. Combat labels only. Fill from MC1 with the book open.',
                'citation_work' => 'Monstrous Compendium Volume One',
                'citation_year' => 1989,
                'citation_pages' => null,
                'citation_topic' => 'Orc',
                'user_verified' => false,
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
        Schema::dropIfExists('citations');
        Schema::dropIfExists('racial_option_cards');
        Schema::dropIfExists('kit_cards');
        Schema::dropIfExists('monster_cards');
        Schema::dropIfExists('spell_cards');
        Schema::dropIfExists('rule_sources');
    }

    private function cardTable(string $name): void
    {
        Schema::create($name, function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('source_code')->nullable();
            $table->string('effect_summary', 280);
            $table->string('citation_work')->nullable();
            $table->unsignedSmallInteger('citation_year')->nullable();
            $table->string('citation_pages', 64)->nullable();
            $table->string('citation_topic')->nullable();
            $table->boolean('user_verified')->default(false);
            $table->timestamps();
            $table->index('name');
            $table->index('source_code');
        });
    }
};
