<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock kept per branch. Until now a product had one quantity for the whole club and its stock movements carried
 * no branch. The club's rule for records without a branch is that they belong to the main branch (BranchContext),
 * so each product's present quantity becomes the main branch's quantity — nothing is spread over other branches and
 * nothing is moved. products.stock_quantity stays as the club total, kept equal to the sum of the branches by
 * StockService. Old movements are not rewritten (no branch = main branch). `php artisan inventory:check` verifies
 * that every product's branches add up to its total and that each branch matches its movements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('min_quantity', 12, 3)->nullable(); // this branch's reorder level; empty: the product's
            $table->timestamps();
            $table->unique(['product_id', 'branch_id']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('branch_balance', 12, 3)->nullable()->after('balance_after'); // the branch's quantity after it
            $table->string('reason', 30)->nullable()->after('note'); // adjustments: count|damaged|lost|expired|correction|internal
            $table->string('document', 40)->nullable()->after('reason'); // number of the document behind it
            $table->index(['tenant_id', 'branch_id', 'created_at']);
        });

        $now = now();
        foreach (DB::table('tenants')->pluck('id') as $tenant) {
            $main = DB::table('branches')->where('tenant_id', $tenant)->orderByDesc('is_main')->orderBy('id')->value('id');
            if (! $main) {
                continue;
            }
            DB::table('products')->where('tenant_id', $tenant)->where(fn ($q) => $q->where('track_stock', true)->orWhere('stock_quantity', '!=', 0))
                ->orderBy('id')->each(function ($p) use ($tenant, $main, $now) {
                    DB::table('product_stocks')->insert(['tenant_id' => $tenant, 'product_id' => $p->id, 'branch_id' => $main, 'quantity' => $p->stock_quantity, 'created_at' => $now, 'updated_at' => $now]);
                });
        }
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'branch_id', 'created_at']);
            $table->dropColumn(['branch_balance', 'reason', 'document']);
        });
        Schema::dropIfExists('product_stocks');
    }
};
