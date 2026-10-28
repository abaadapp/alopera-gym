<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gym tables are prefixed `gym_` so the module can later be dropped into ABAAD
 * next to its own customers/products/invoices without name clashes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('member_no', 20);
            $table->string('full_name');
            $table->string('phone', 32);
            $table->string('phone_alt', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable(); // member portal; null until she sets one
            $table->string('gender', 10)->default('female');
            $table->date('date_of_birth')->nullable();
            $table->string('national_id', 40)->nullable();
            $table->string('nationality', 2)->nullable();
            $table->string('address')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 32)->nullable();
            $table->text('health_notes')->nullable();
            $table->text('goals')->nullable();
            $table->string('source', 30)->nullable();
            $table->string('status', 20)->default('active'); // active|inactive|blocked (the person, not her membership)
            $table->string('photo_path')->nullable();
            $table->string('qr_token', 64);
            $table->boolean('whatsapp_opt_in')->default(true);   // service messages
            $table->boolean('marketing_opt_in')->default(false); // campaigns
            $table->timestamp('marketing_consent_at')->nullable();
            $table->timestamp('marketing_opt_out_at')->nullable();
            $table->text('notes')->nullable();
            $table->date('joined_on')->nullable();
            $table->timestamp('last_check_in_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'member_no']);
            $table->unique(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'email']);
            $table->unique('qr_token');
            $table->index(['tenant_id', 'full_name']);
        });

        Schema::create('gym_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('category', 20)->default('class'); // class|program|pt
            $table->string('level', 20)->default('all');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->string('color', 9)->default('#db2777');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->unsignedSmallInteger('default_capacity')->default(15);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('gym_membership_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_value');
            $table->string('duration_unit', 10); // day|week|month|year
            $table->decimal('price', 12, 3);
            $table->decimal('signup_fee', 12, 3)->default(0);
            $table->unsignedInteger('sessions_limit')->nullable(); // class bookings; null = unlimited
            $table->boolean('freeze_allowed')->default(true);
            $table->unsignedSmallInteger('max_freeze_days')->default(0);
            $table->unsignedSmallInteger('max_freezes')->default(0);
            $table->json('features')->nullable();
            $table->string('color', 9)->nullable();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Empty = the plan may book every program.
        Schema::create('gym_plan_program', function (Blueprint $table) {
            $table->foreignId('membership_plan_id')->constrained('gym_membership_plans')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('gym_programs')->cascadeOnDelete();
            $table->primary(['membership_plan_id', 'program_id']);
        });

        Schema::create('gym_coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('description')->nullable();
            $table->string('type', 10); // percent|fixed
            $table->decimal('value', 12, 3);
            $table->decimal('min_amount', 12, 3)->default(0);
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('per_member_limit')->nullable()->default(1);
            $table->json('plan_ids')->nullable(); // null = all plans
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('gym_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->constrained('gym_members')->restrictOnDelete();
            $table->foreignId('membership_plan_id')->constrained('gym_membership_plans')->restrictOnDelete();
            $table->foreignId('previous_membership_id')->nullable()->constrained('gym_memberships')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained('gym_coupons')->nullOnDelete();
            $table->string('kind', 10)->default('new'); // new|renewal|upgrade
            $table->string('status', 20)->index(); // pending|scheduled|active|frozen|expired|cancelled
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('price', 12, 3);
            $table->decimal('signup_fee', 12, 3)->default(0);
            $table->decimal('discount', 12, 3)->default(0);
            $table->decimal('credit_applied', 12, 3)->default(0); // unused value carried over on upgrade
            $table->decimal('total', 12, 3);
            $table->unsignedInteger('sessions_total')->nullable();
            $table->unsignedInteger('sessions_used')->default(0);
            $table->unsignedSmallInteger('freeze_days_used')->default(0);
            $table->unsignedSmallInteger('freezes_count')->default(0);
            $table->json('plan_snapshot');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status', 'ends_on']);
            $table->index(['member_id', 'status']);
        });

        Schema::create('gym_coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('coupon_id')->constrained('gym_coupons')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('gym_members')->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('gym_memberships')->nullOnDelete();
            $table->decimal('amount', 12, 3);
            $table->timestamps();
        });

        Schema::create('gym_membership_freezes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('gym_memberships')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('planned_end_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->unsignedSmallInteger('days')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('gym_membership_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_id')->constrained('gym_memberships')->cascadeOnDelete();
            $table->string('type', 30);
            $table->json('data')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('gym_member_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('gym_members')->cascadeOnDelete();
            $table->string('type', 20); // contract|id|medical|waiver|other
            $table->string('title');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->date('expires_on')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('gym_member_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('gym_members')->cascadeOnDelete();
            $table->string('channel', 20); // call|whatsapp|sms|email|visit|note
            $table->string('direction', 10)->default('internal'); // inbound|outbound|internal
            $table->text('body');
            $table->date('follow_up_on')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['gym_member_notes', 'gym_member_documents', 'gym_membership_events', 'gym_membership_freezes',
            'gym_coupon_redemptions', 'gym_memberships', 'gym_coupons', 'gym_plan_program', 'gym_membership_plans',
            'gym_programs', 'gym_members'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
