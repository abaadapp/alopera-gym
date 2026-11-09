<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The official website's content as one document per club: the owner's saved draft and what visitors see
 * (published), each published version kept in site_revisions so the owner can go back. Images stay files on the
 * public disk under tenants/{id}/ (never in public/build), and site_media now records their size and shape so the
 * site can lay each one out whole. Only adds: no existing column or row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->json('draft')->nullable();
            $table->json('published')->nullable();
            // Bumped on every saved draft; a save, publish or discard names the version it started from.
            $table->unsignedInteger('draft_version')->default(0);
            // The draft version that is live; equal to draft_version when nothing is waiting to be published.
            $table->unsignedInteger('published_draft_version')->nullable();
            $table->timestamp('draft_saved_at')->nullable();
            $table->foreignId('draft_saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('content_published_at')->nullable();
            $table->foreignId('content_published_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('site_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->json('document');
            $table->string('note')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::table('site_media', function (Blueprint $table) {
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('orientation', 10)->nullable(); // landscape | portrait | square
            $table->string('alt', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_media', function (Blueprint $table) {
            $table->dropColumn(['width', 'height', 'orientation', 'alt']);
        });
        Schema::dropIfExists('site_revisions');
        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('draft_saved_by');
            $table->dropConstrainedForeignId('content_published_by');
            $table->dropColumn(['draft', 'published', 'draft_version', 'published_draft_version', 'draft_saved_at', 'content_published_at']);
        });
    }
};
