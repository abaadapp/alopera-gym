<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a club's integration was last checked, when its last error happened and when the provider last called
 * back with a valid signature — so the integrations screen states what was verified, not what was typed in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_integrations', function (Blueprint $table) {
            $table->timestamp('last_checked_at')->nullable()->after('verified_at');
            $table->timestamp('last_error_at')->nullable()->after('last_error');
            $table->timestamp('last_webhook_at')->nullable()->after('last_error_at');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_integrations', function (Blueprint $table) {
            $table->dropColumn(['last_checked_at', 'last_error_at', 'last_webhook_at']);
        });
    }
};
