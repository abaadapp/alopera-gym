<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which demo-club row is the copy of which gym row (App\Core\Demo\DemoMirror): the gym's setup — plans, prices,
 * programs, products, the website, templates — is copied to the demo club, one way, and kept in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_mirror_links', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 64);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
            $table->unique(['table_name', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_mirror_links');
    }
};
