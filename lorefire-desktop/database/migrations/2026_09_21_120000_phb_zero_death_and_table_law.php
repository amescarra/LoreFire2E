<?php

use App\Models\AppSetting;
use App\Support\TableLaw;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PHB 1989 death: existing rows with current_hp at 0 or below are slain.
 * Negative HP (old DMG optional band) is clamped to 0. Record the dual-class
 * house switch as table law.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('characters')->where('current_hp', '<', 0)->update(['current_hp' => 0]);

        if (AppSetting::get(TableLaw::SETTING_KEY) === null) {
            TableLaw::persistDefaults();
        }
    }

    public function down(): void
    {
        AppSetting::query()->where('key', TableLaw::SETTING_KEY)->delete();
    }
};
