<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_voiceprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->foreignId('character_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_dm')->default(false);
            $table->json('embedding')->nullable();
            $table->string('embedding_model')->nullable();
            $table->string('enrollment_audio_path')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'is_dm']);
        });

        Schema::table('speaker_profiles', function (Blueprint $table) {
            $table->foreignId('campaign_voiceprint_id')
                ->nullable()
                ->after('character_id')
                ->constrained('campaign_voiceprints')
                ->nullOnDelete();
            $table->float('match_confidence')->nullable()->after('campaign_voiceprint_id');
            $table->string('match_source', 20)->nullable()->after('match_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('speaker_profiles', function (Blueprint $table) {
            $table->dropForeign(['campaign_voiceprint_id']);
            $table->dropColumn(['campaign_voiceprint_id', 'match_confidence', 'match_source']);
        });

        Schema::dropIfExists('campaign_voiceprints');
    }
};
