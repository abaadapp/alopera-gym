<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WhatsApp inbox grows into a working desk: conversations take four states (new, open, being handled, closed) and
 * keep who answered first; messages say where they came from (staff in the panel, an automatic notice, the WhatsApp
 * Business app on the owner's phone, the imported history) and when WhatsApp says they happened; files, internal notes
 * and the conversation's events get their own tables; webhooks are written down before they are processed, so a
 * failure is retried and a repeat is ignored; a status that arrives before its message waits for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('status', 20)->default('new')->change();
            $table->string('profile_name')->nullable()->after('contact_name');
            $table->string('last_preview', 200)->nullable()->after('last_inbound_at');
            $table->string('last_direction', 10)->nullable()->after('last_preview');
            $table->timestamp('first_inbound_at')->nullable()->after('last_direction');
            $table->timestamp('first_response_at')->nullable()->after('first_inbound_at');
            $table->timestamp('assigned_at')->nullable()->after('assigned_to');
            $table->timestamp('closed_at')->nullable()->after('first_response_at');
            // Sample conversations seeded for the demo: shown as such, never mixed with real ones.
            $table->boolean('is_demo')->default(false)->after('closed_at');
            $table->index(['tenant_id', 'status', 'last_message_at']);
            $table->index(['tenant_id', 'assigned_to']);
        });

        Schema::table('messages', function (Blueprint $table) {
            // platform (staff in the panel) | automation | business_app (echo from the phone) | history | customer
            $table->string('source', 20)->nullable()->after('direction');
            // When WhatsApp says it happened (messages can arrive late or out of order).
            $table->timestamp('wa_timestamp')->nullable()->after('read_at');
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('whatsapp_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->string('kind', 20); // image|document|audio|video|sticker
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('filename')->nullable();
            $table->text('caption')->nullable();
            $table->string('path')->nullable(); // on the private disk; never a public URL
            $table->string('provider_media_id')->nullable();
            $table->string('sha256', 100)->nullable();
            $table->string('status', 20)->default('pending'); // pending|stored|failed
            $table->string('error', 250)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('conversation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20); // note|assigned|status|linked|unlinked
            $table->text('body')->nullable(); // an internal note: never sent to the customer
            $table->json('data')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'id']);
        });

        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 32);
            $table->string('name')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'phone']);
        });

        Schema::create('whatsapp_status_backlog', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider_message_id');
            $table->string('status', 20);
            $table->string('error_code', 40)->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('wa_timestamp')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'provider_message_id']);
        });

        // Every webhook body, written before it is processed: the same body twice is one row; a failure is retried.
        Schema::create('whatsapp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('vendor', 20);
            $table->string('digest', 64)->unique();
            $table->text('payload'); // encrypted at rest: it holds customers' messages
            $table->string('fields', 120)->nullable();
            $table->string('status', 20)->default('received'); // received|processed|failed|ignored
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error', 250)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
        Schema::dropIfExists('whatsapp_status_backlog');
        Schema::dropIfExists('whatsapp_contacts');
        Schema::dropIfExists('conversation_events');
        Schema::dropIfExists('whatsapp_attachments');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'id']);
            $table->dropColumn(['source', 'wa_timestamp']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status', 'last_message_at']);
            $table->dropIndex(['tenant_id', 'assigned_to']);
            $table->dropColumn(['profile_name', 'last_preview', 'last_direction', 'first_inbound_at', 'first_response_at', 'assigned_at', 'closed_at', 'is_demo']);
            $table->string('status', 10)->default('open')->change();
        });
    }
};
