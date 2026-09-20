<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_voiceprints', function (Blueprint $table) {
            $table->text('extract_error')->nullable()->after('enrollment_audio_path');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_voiceprints', function (Blueprint $table) {
            $table->dropColumn('extract_error');
        });
    }
};
