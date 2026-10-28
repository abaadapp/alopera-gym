<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_trainers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->text('bio')->nullable();
            $table->json('specialties')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('color', 9)->default('#be185d');
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('gym_trainer_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained('gym_trainers')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = Sunday … 6 = Saturday
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();
        });

        // Weekly template. Concrete occurrences live in gym_class_sessions.
        Schema::create('gym_class_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('program_id')->constrained('gym_programs')->restrictOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('gym_trainers')->nullOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('capacity');
            $table->string('room')->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('gym_class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('class_schedule_id')->nullable()->constrained('gym_class_schedules')->nullOnDelete();
            $table->foreignId('program_id')->constrained('gym_programs')->restrictOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('gym_trainers')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('capacity');
            $table->unsignedSmallInteger('booked_count')->default(0);
            $table->string('room')->nullable();
            $table->string('status', 20)->default('scheduled'); // scheduled|cancelled|completed
            $table->string('cancel_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['class_schedule_id', 'starts_at']);
            $table->index(['tenant_id', 'starts_at']);
            $table->index(['trainer_id', 'starts_at']);
        });

        Schema::create('gym_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_session_id')->constrained('gym_class_sessions')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('gym_members')->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('gym_memberships')->nullOnDelete();
            $table->string('status', 20)->default('booked'); // booked|cancelled|attended|no_show
            $table->string('source', 20)->default('reception'); // reception|portal|website|trainer
            $table->boolean('consumed_session')->default(false);
            $table->foreignId('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('attended_at')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'status']);
        });

        // One live booking per member per session; cancelled rows do not count.
        DB::statement("CREATE UNIQUE INDEX gym_bookings_one_live_per_member ON gym_bookings (class_session_id, member_id) WHERE status <> 'cancelled'");

        Schema::create('gym_pt_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sessions_count');
            $table->unsignedSmallInteger('session_minutes')->default(60);
            $table->decimal('price', 12, 3);
            $table->unsignedSmallInteger('validity_days')->default(60);
            $table->foreignId('trainer_id')->nullable()->constrained('gym_trainers')->nullOnDelete();
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('gym_pt_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('gym_members')->restrictOnDelete();
            $table->foreignId('pt_package_id')->constrained('gym_pt_packages')->restrictOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('gym_trainers')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('status', 20); // pending|active|completed|expired|cancelled
            $table->unsignedSmallInteger('sessions_total');
            $table->unsignedSmallInteger('sessions_used')->default(0);
            $table->decimal('price', 12, 3);
            $table->date('starts_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('gym_pt_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pt_subscription_id')->constrained('gym_pt_subscriptions')->cascadeOnDelete();
            $table->foreignId('trainer_id')->constrained('gym_trainers')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('gym_members')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 20)->default('scheduled'); // scheduled|completed|cancelled|no_show
            $table->text('notes')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['trainer_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        foreach (['gym_pt_sessions', 'gym_pt_subscriptions', 'gym_pt_packages', 'gym_bookings', 'gym_class_sessions',
            'gym_class_schedules', 'gym_trainer_availabilities', 'gym_trainers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
