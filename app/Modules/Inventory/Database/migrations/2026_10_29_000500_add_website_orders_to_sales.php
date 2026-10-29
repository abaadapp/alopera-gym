<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One sales table for both sources: the point of sale (completed on the spot) and orders placed on the club's
 * website (pending → paid/handed over, or cancelled). Products can be shown in the website shop.
 * Additive only: existing sales keep source "pos".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('source', 20)->default('pos')->after('number'); // pos|website
            $table->string('fulfillment', 20)->nullable()->after('status'); // website: new|preparing|ready|delivered
            $table->string('customer_name', 120)->nullable()->after('customer_id');
            $table->string('customer_phone', 32)->nullable()->after('customer_name');
            $table->string('customer_email')->nullable()->after('customer_phone');
            $table->text('notes')->nullable();
            $table->string('public_token', 40)->nullable()->unique();
            $table->timestamp('reserved_until')->nullable(); // online payment window; stock is held until then
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->index(['tenant_id', 'source', 'status']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('show_on_website')->default(false)->after('is_active');
            $table->index(['tenant_id', 'show_on_website']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'show_on_website']);
            $table->dropColumn('show_on_website');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source', 'status']);
            $table->dropConstrainedForeignId('delivered_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['source', 'fulfillment', 'customer_name', 'customer_phone', 'customer_email', 'notes', 'public_token', 'reserved_until', 'paid_at', 'delivered_at', 'cancelled_at', 'cancel_reason']);
        });
    }
};
