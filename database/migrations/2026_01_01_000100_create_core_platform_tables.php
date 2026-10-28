<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('actor_type', 20)->nullable(); // user|member|device|system|gateway
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name')->nullable();
            $table->string('event', 80)->index();
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        // Per-club counters for human-readable numbers (INV-2026-000123).
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        // Club-owned credentials for external services. Secrets are encrypted at rest.
        Schema::create('tenant_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30); // whatsapp|paymob
            $table->boolean('is_enabled')->default(false);
            $table->string('mode', 10)->default('test'); // test|live
            $table->text('credentials')->nullable();      // encrypted:array
            $table->json('settings')->nullable();          // non-secret options
            $table->string('public_identifier')->nullable()->index(); // e.g. WhatsApp phone_number_id
            $table->timestamp('verified_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'provider']);
        });

        // ─── SaaS billing: the club's subscription to the platform ───────────
        Schema::create('platform_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->decimal('price_monthly', 12, 3);
            $table->decimal('price_yearly', 12, 3);
            $table->string('currency', 3)->default('OMR');
            $table->unsignedInteger('max_members')->nullable();
            $table->unsignedInteger('max_staff')->nullable();
            $table->unsignedInteger('max_branches')->nullable();
            $table->json('features')->nullable();
            $table->unsignedInteger('trial_days')->default(14);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_plan_id')->constrained()->restrictOnDelete();
            $table->string('billing_cycle', 10); // monthly|yearly
            $table->string('status', 20)->index(); // trialing|active|past_due|cancelled|expired
            $table->decimal('price', 12, 3);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('trial_ends_on')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('cancelled_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->unique();
            $table->string('status', 20)->default('unpaid'); // unpaid|paid|void
            $table->decimal('amount', 12, 3);
            $table->string('currency', 3)->default('OMR');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('due_on');
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_reference')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoices');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('platform_plans');
        Schema::dropIfExists('tenant_integrations');
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('audit_logs');
    }
};
