<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Point-of-sale devices (a till, a tablet at the desk), separate from access devices at the gates. A device is
 * bound to one branch and identified by a secret kept in an encrypted cookie on that browser; only its SHA-256 hash
 * is stored, as for a one-time pairing code. Its peripherals (receipt printer, barcode scanner, cash drawer,
 * customer display, card terminal) are recorded with their connection and settings. A sale records the device it
 * was rung up on, and a client key so a retried checkout is one sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('status', 20)->default('pending'); // pending (waiting for its code)|active|revoked
            $table->string('pairing_hash', 64)->nullable();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->foreignId('settlement_account_id')->nullable()->constrained('gym_accounts')->nullOnDelete(); // the bank its card terminal pays into
            $table->json('settings')->nullable(); // auto_print, print_website_orders
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('pos_peripherals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_device_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // printer|scanner|drawer|display|card_terminal
            $table->string('name', 60);
            $table->string('brand', 60)->nullable();
            $table->string('model', 60)->nullable();
            $table->string('connection', 20); // browser|network|usb|bluetooth|keyboard
            $table->string('address', 120)->nullable(); // network printers
            $table->unsignedInteger('port')->nullable();
            $table->unsignedSmallInteger('paper_width')->nullable(); // 58|80 mm
            $table->boolean('is_default')->default(false);
            $table->boolean('auto_print')->default(false);
            $table->boolean('print_website_orders')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('notes')->nullable();
            $table->string('last_status', 20)->nullable(); // ok|error
            $table->string('last_error')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('pos_device_id')->nullable()->after('cashier_id')->constrained()->nullOnDelete();
            $table->foreignId('settlement_account_id')->nullable()->after('pos_device_id')->constrained('gym_accounts')->nullOnDelete();
            $table->string('client_key', 64)->nullable();
            $table->unique(['tenant_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'client_key']);
            $table->dropConstrainedForeignId('pos_device_id');
            $table->dropConstrainedForeignId('settlement_account_id');
            $table->dropColumn('client_key');
        });
        Schema::dropIfExists('pos_peripherals');
        Schema::dropIfExists('pos_devices');
    }
};
