<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The English side of the site's news and offers (#68): optional English title, summary and text. New nullable columns only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_posts', function (Blueprint $table) {
            $table->string('title_en')->nullable();
            $table->string('excerpt_en', 500)->nullable();
            $table->text('body_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_posts', fn (Blueprint $table) => $table->dropColumn(['title_en', 'excerpt_en', 'body_en']));
    }
};
