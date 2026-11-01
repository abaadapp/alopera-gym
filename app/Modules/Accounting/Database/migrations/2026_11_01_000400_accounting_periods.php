<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closed accounting periods: a month (or a whole year) whose books are final. Nothing is posted, edited or deleted with
 * a date inside it; a later correction is posted in an open period. A period is reopened only with its own permission
 * and a reason. A row is made when a period is first closed and kept from then on (reopened, closed again), so the
 * page and the audit trail tell its whole story. New table only: nothing existing changes, and rollback drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8); // month|year
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 10); // closed|reopened
            $table->json('summary')->nullable(); // the figures when it was last closed
            $table->json('warnings')->nullable(); // what was open but did not stop the closing
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reopen_reason', 500)->nullable();
            $table->unsignedSmallInteger('times_closed')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'kind', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_accounting_periods');
    }
};
