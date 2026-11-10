<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The English side of the website shop (#68): optional English words for products and their categories. New nullable columns only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('name_en')->nullable();
            $table->text('description_en')->nullable();
        });
        Schema::table('product_categories', function (Blueprint $table) {
            $table->string('name_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['name_en', 'description_en']));
        Schema::table('product_categories', fn (Blueprint $table) => $table->dropColumn('name_en'));
    }
};
