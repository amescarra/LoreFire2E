<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('player_name')->nullable();
            $table->string('race');
            $table->string('subrace')->nullable();
            $table->string('class');
            $table->string('subclass')->nullable(); // kit or specialist school
            $table->string('class_path')->default('single'); // single, multi, dual
            $table->json('class_levels')->nullable();
            $table->unsignedTinyInteger('level')->default(1);
            $table->string('background')->nullable();
            $table->string('alignment')->nullable();
            $table->unsignedInteger('experience_points')->default(0);
            // Ability scores
            $table->unsignedTinyInteger('strength')->default(10);
            $table->string('exceptional_strength')->nullable(); // 01–00 for warriors
            $table->unsignedTinyInteger('dexterity')->default(10);
            $table->unsignedTinyInteger('constitution')->default(10);
            $table->unsignedTinyInteger('intelligence')->default(10);
            $table->unsignedTinyInteger('wisdom')->default(10);
            $table->unsignedTinyInteger('charisma')->default(10);
            // HP — current is 0 when slain (PHB 1989). The old DMG optional survival to -10 is not used.
            $table->unsignedSmallInteger('max_hp')->default(0);
            $table->smallInteger('current_hp')->default(0);
            // Combat (descending AC, THAC0)
            $table->smallInteger('armor_class')->default(10);
            $table->smallInteger('thac0')->default(20);
            $table->unsignedTinyInteger('speed')->default(12); // movement rate
            $table->string('hit_die')->nullable();
            // 2E saving throws + proficiencies
            $table->json('saving_throws')->nullable();
            $table->json('weapon_proficiencies')->nullable();
            $table->json('nonweapon_proficiencies')->nullable();
            $table->json('priest_spheres')->nullable();
            // Currency
            $table->unsignedInteger('copper')->default(0);
            $table->unsignedInteger('silver')->default(0);
            $table->unsignedInteger('electrum')->default(0);
            $table->unsignedInteger('gold')->default(0);
            $table->unsignedInteger('platinum')->default(0);
            // Vancian memorization — capacity / casts used per spell level
            $table->string('spellcasting_ability')->nullable();
            $table->json('memorization')->nullable();
            $table->json('memorization_used')->nullable();
            // Character notes (not 5e personality traits)
            $table->text('mannerisms')->nullable();
            $table->text('motivations')->nullable();
            $table->text('ties')->nullable();
            $table->text('weaknesses')->nullable();
            $table->text('backstory')->nullable();
            $table->string('portrait_path')->nullable();
            $table->json('imported_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
