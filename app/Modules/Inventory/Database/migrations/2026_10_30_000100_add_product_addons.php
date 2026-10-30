<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product add-ons: extras a customer buys with a product (an extra scoop, a banana, a flavour), each with its own
 * price, offered on every product, on the products of chosen categories, or on chosen products only, at the point
 * of sale and/or in the website shop. An add-on may use up stock of another product (a scoop takes 0.03 of a tub).
 * What was chosen is kept on the sale line at the price of the day. Categories get an order of their own and
 * products more than one photo. Additive only: existing products, categories and sales are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('name');
        });

        // More photos after the main one (image_path), in order.
        Schema::table('products', function (Blueprint $table) {
            $table->json('images')->nullable()->after('image_path');
        });

        Schema::create('product_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->decimal('price', 12, 3);
            $table->unsignedSmallInteger('max_quantity')->default(1); // per unit of the product
            $table->string('applies_to', 20)->default('products'); // all|categories|products
            $table->boolean('show_in_pos')->default(true);
            $table->boolean('show_on_website')->default(true);
            $table->boolean('is_active')->default(true);
            $table->foreignId('stock_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('stock_usage', 12, 3)->default(1); // of the stock product, per add-on
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('product_addon_product', function (Blueprint $table) {
            $table->foreignId('product_addon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_addon_id', 'product_id']);
            $table->index('product_id');
        });

        Schema::create('product_addon_category', function (Blueprint $table) {
            $table->foreignId('product_addon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_category_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_addon_id', 'product_category_id']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('addons_total', 12, 3)->default(0)->after('unit_cost'); // included in line_total
            $table->decimal('addons_cost', 12, 3)->default(0)->after('addons_total');
        });

        Schema::create('sale_item_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_addon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->decimal('unit_price', 12, 3);
            $table->decimal('quantity', 12, 3); // per unit of the product
            $table->decimal('line_total', 12, 3); // unit_price × quantity × the line's quantity
            $table->foreignId('stock_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('stock_quantity', 12, 3)->default(0); // taken off the stock product for the whole line
            $table->decimal('unit_cost', 12, 3)->default(0); // of the stock product, at the time
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_item_addons');
        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn(['addons_total', 'addons_cost']));
        Schema::dropIfExists('product_addon_category');
        Schema::dropIfExists('product_addon_product');
        Schema::dropIfExists('product_addons');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('images'));
        Schema::table('product_categories', fn (Blueprint $table) => $table->dropColumn('sort_order'));
    }
};
