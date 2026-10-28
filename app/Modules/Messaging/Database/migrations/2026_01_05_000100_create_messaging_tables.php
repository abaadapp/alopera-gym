<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Maps an event the system sends (welcome, renewal…) to a template approved in Meta.
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50); // event key, or custom_* for marketing
            $table->string('name');    // template name as approved in WhatsApp Manager
            $table->string('language', 10)->default('ar');
            $table->string('category', 20)->default('utility'); // utility|marketing|authentication
            $table->text('body');      // preview with {{1}}, {{2}}…
            $table->json('variables')->nullable(); // ordered list of variable keys for {{n}}
            $table->string('status', 20)->default('draft'); // draft|pending|approved|rejected
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('gym_members')->nullOnDelete();
            $table->string('phone', 32);
            $table->string('contact_name')->nullable();
            $table->string('status', 10)->default('open'); // open|closed
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_inbound_at')->nullable(); // opens the 24h customer-service window
            $table->timestamps();
            $table->unique(['tenant_id', 'phone']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_template_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->json('audience'); // segment definition
            $table->string('status', 20)->default('draft'); // draft|scheduled|sending|completed|cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('read_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('gym_members')->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20)->default('whatsapp');
            $table->string('direction', 10); // outbound|inbound
            $table->string('type', 20); // template|text|media
            $table->string('to_phone', 32)->nullable();
            $table->string('template_key', 50)->nullable();
            $table->string('template_name')->nullable();
            $table->json('template_params')->nullable();
            $table->text('body')->nullable();
            $table->string('status', 20)->index(); // scheduled|queued|sent|delivered|read|failed|received|skipped
            $table->string('provider_message_id')->nullable()->unique();
            $table->string('error_code', 40)->nullable();
            $table->string('error_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('dedupe_key', 120)->nullable(); // stops the same reminder being sent twice
            $table->nullableMorphs('related');
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'dedupe_key']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('auto_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('keyword');
            $table->string('match_type', 10)->default('contains'); // exact|contains
            $table->text('reply');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_replies');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('message_templates');
    }
};
