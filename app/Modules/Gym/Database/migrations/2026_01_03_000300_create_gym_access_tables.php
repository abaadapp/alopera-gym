<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_access_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('type', 20); // fingerprint|qr_reader|barcode_reader|rfid_reader|turnstile|kiosk
            $table->string('driver', 30)->default('generic_json');
            $table->string('vendor')->nullable();
            $table->string('model')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('direction', 5)->default('in'); // in|out|both
            $table->string('purpose', 10)->default('members'); // members|staff|both
            $table->string('token_hash', 64)->unique(); // sha256 of the device API token
            $table->string('token_last4', 4);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // Maps what a device reads (card number, fingerprint user id, QR token) to a person.
        Schema::create('gym_access_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('holder_type', 20); // member|user
            $table->unsignedBigInteger('holder_id');
            $table->string('type', 20); // card|fingerprint|pin|barcode
            $table->string('identifier', 128);
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'type', 'identifier']);
            $table->index(['holder_type', 'holder_id']);
        });

        Schema::create('gym_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('gym_members')->cascadeOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('gym_memberships')->nullOnDelete();
            $table->foreignId('access_device_id')->nullable()->constrained('gym_access_devices')->nullOnDelete();
            $table->string('method', 20); // manual|qr|card|fingerprint|barcode|pin
            $table->string('direction', 5)->default('in');
            $table->string('result', 10); // allowed|denied|duplicate
            $table->string('reason', 40)->nullable();
            $table->string('credential_hint', 20)->nullable(); // masked, for unknown-credential investigations
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_in_at')->index();
            $table->timestamps();
            $table->index(['tenant_id', 'checked_in_at']);
            $table->index(['member_id', 'checked_in_at']);
        });

        Schema::create('gym_staff_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('access_device_id')->nullable()->constrained('gym_access_devices')->nullOnDelete();
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->string('method', 20)->default('manual');
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'clock_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_staff_attendances');
        Schema::dropIfExists('gym_check_ins');
        Schema::dropIfExists('gym_access_credentials');
        Schema::dropIfExists('gym_access_devices');
    }
};
