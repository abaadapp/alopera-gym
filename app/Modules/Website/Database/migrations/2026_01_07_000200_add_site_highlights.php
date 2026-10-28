<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->string('hero_badge', 60)->nullable()->after('hero_cta_label'); // small label above the headline; empty hides it
            $table->json('features')->nullable()->after('stats'); // [{title, text}] the three "why us" cards; null = defaults
        });

        // Sites that existed before this keep the badge they were showing.
        DB::table('site_settings')->update(['hero_badge' => 'نادٍ مخصص للنساء']);
    }

    public function down(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn(['hero_badge', 'features']);
        });
    }
};
