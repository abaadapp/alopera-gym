<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The English side of what the website shows from the gym (#68): plans, programs, trainers and personal-training
 * packages get optional English words next to their Arabic ones. New nullable columns only: nothing existing changes,
 * and a record without English shows its Arabic on the English site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gym_membership_plans', function (Blueprint $table) {
            $table->string('name_en')->nullable();
            $table->text('description_en')->nullable();
            $table->json('features_en')->nullable();
        });
        Schema::table('gym_programs', function (Blueprint $table) {
            $table->string('name_en')->nullable();
            $table->text('description_en')->nullable();
        });
        Schema::table('gym_trainers', function (Blueprint $table) {
            $table->string('name_en')->nullable();
            $table->text('bio_en')->nullable();
            $table->json('specialties_en')->nullable();
        });
        Schema::table('gym_pt_packages', function (Blueprint $table) {
            $table->string('name_en')->nullable();
            $table->text('description_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gym_membership_plans', fn (Blueprint $table) => $table->dropColumn(['name_en', 'description_en', 'features_en']));
        Schema::table('gym_programs', fn (Blueprint $table) => $table->dropColumn(['name_en', 'description_en']));
        Schema::table('gym_trainers', fn (Blueprint $table) => $table->dropColumn(['name_en', 'bio_en', 'specialties_en']));
        Schema::table('gym_pt_packages', fn (Blueprint $table) => $table->dropColumn(['name_en', 'description_en']));
    }
};
