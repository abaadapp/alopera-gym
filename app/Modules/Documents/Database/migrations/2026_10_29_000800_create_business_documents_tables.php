<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The business's own documents (commercial register, licences, insurance, lease, staff certificates…), kept
 * apart from members' documents: types, the documents with their expiry, every renewal with the version it
 * replaced, and expiry alerts (one per document, expiry date and threshold, so a run never repeats one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('renewal_months')->nullable(); // usual renewal period
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('gym_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('gym_document_types')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('reference_number', 120)->nullable();
            $table->string('issuer', 160)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->unsignedSmallInteger('renewal_months')->nullable();
            $table->string('status', 20)->default('active'); // active|cancelled
            $table->boolean('is_confidential')->default(false);
            $table->text('notes')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name', 200)->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'expiry_date']);
        });

        Schema::create('gym_document_renewals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('gym_documents')->cascadeOnDelete();
            $table->date('previous_issue_date')->nullable();
            $table->date('previous_expiry_date')->nullable();
            $table->string('previous_reference', 120)->nullable();
            $table->string('previous_file_path')->nullable();
            $table->string('previous_file_name', 200)->nullable();
            $table->string('previous_file_mime', 120)->nullable();
            $table->unsignedInteger('previous_file_size')->nullable();
            $table->date('new_issue_date')->nullable();
            $table->date('new_expiry_date');
            $table->string('new_reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('renewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('gym_document_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('gym_documents')->cascadeOnDelete();
            $table->date('expiry_date');
            $table->unsignedSmallInteger('threshold_days'); // 0 = expired
            $table->smallInteger('days_left');
            $table->string('whatsapp_status', 40)->nullable(); // queued | skipped:<reason>
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['document_id', 'expiry_date', 'threshold_days'], 'gym_document_alerts_once');
            $table->index(['tenant_id', 'acknowledged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_document_alerts');
        Schema::dropIfExists('gym_document_renewals');
        Schema::dropIfExists('gym_documents');
        Schema::dropIfExists('gym_document_types');
    }
};
