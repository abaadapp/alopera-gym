<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment links v2, part two — lifts «one link per invoice, ever» (payment_links_invoice_id_unique), so a new link can
 * be made once the one before ended or was cancelled, keeping every earlier link and its history (owner's decision M1,
 * 2026-11-20). What stays: one LIVE link per invoice (payment_links_one_live_per_invoice, the migration before, which
 * must exist first), the plain index on invoice_id, and the foreign key to the invoice.
 *
 * Nothing is lost: no row is touched, only the key goes. The code before (v0.138) reads «the» link of an invoice with
 * first(); with two rows it may read the older one, ended or cancelled, and then refuses to open a payment page (the
 * customer pays at the desk): a refusal, never a wrong payment. A new link's own address (/pay/link/…) does not exist
 * for that code (404). See the pull request (#218) for the review.
 *
 * Deleting a key is a step the deploy stops on until it is marked reviewed (scripts/migration-risk.php). It is not
 * marked yet: the owner approved the design (2026-11-20) but not running it on production; marking it is part of that
 * approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('payment_links', 'payment_links_one_live_per_invoice')) {
            throw new RuntimeException('payment_links: the one-live-link index must exist before the old unique key is lifted.');
        }
        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropUnique(['invoice_id']);
        });
    }

    /** Back to one link per invoice — refused while an invoice has more than one (they would have to be deleted). */
    public function down(): void
    {
        if (DB::table('payment_links')->select('invoice_id')->groupBy('invoice_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('payment_links: an invoice has more than one link; one link per invoice cannot come back without deleting links. Roll the code back only (scripts/rollback-code.sh).');
        }
        Schema::table('payment_links', function (Blueprint $table) {
            $table->unique('invoice_id');
        });
    }
};
