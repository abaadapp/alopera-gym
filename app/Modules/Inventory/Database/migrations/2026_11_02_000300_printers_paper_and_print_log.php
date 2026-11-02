<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Printers and what they printed.
 *
 * pos_peripherals.paper: the paper a printer feeds, as the paper engine names it — a thermal strip (80mm, 58mm) or a
 * sheet (A4, A5). Filled from paper_width for the printers there are; paper_width stays, written beside it for strips.
 *
 * pos_print_logs: every print the panel started and how it ended (printed by the app on a network printer, a print
 * dialog opened, failed and why, may have reached the paper), by whom, on which device and printer, for which paper.
 * An automatic print after a sale takes a key (sale:12) that is unique, so a reloaded page or a second device never
 * prints the same receipt by itself again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_peripherals', function (Blueprint $table) {
            $table->string('paper', 8)->nullable()->after('paper_width');
        });
        DB::table('pos_peripherals')->where('type', 'printer')->where('paper_width', 58)->update(['paper' => '58mm']);
        DB::table('pos_peripherals')->where('type', 'printer')->whereNull('paper')->update(['paper' => '80mm']);

        Schema::create('pos_print_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('pos_peripheral_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document', 30); // a paper type (sale, receipt, invoice…) or test
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('number', 60)->nullable();
            $table->string('trigger', 10); // auto|manual|reprint|test
            $table->string('via', 10)->nullable(); // native|system|browser|page|share
            $table->string('status', 10); // claimed|printed|opened|failed
            $table->string('code', 30)->nullable();
            $table->boolean('may_have_printed')->default(false);
            $table->boolean('connected')->nullable();
            $table->unsignedInteger('effective_port')->nullable();
            $table->string('paper', 8)->nullable();
            $table->string('auto_key', 60)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'auto_key']);
            $table->index(['tenant_id', 'pos_device_id', 'created_at']);
            $table->index(['tenant_id', 'document', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_print_logs');
        Schema::table('pos_peripherals', fn (Blueprint $table) => $table->dropColumn('paper'));
    }
};
