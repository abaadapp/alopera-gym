<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice keeps the club identity it was issued with (name, contact lines, tax and CR numbers, currency), so a
 * later change of name or tax number does not rewrite an invoice already sent.
 *
 * Invoices issued before this have no record of what the club looked like then; they are stamped with the club as it
 * is now, which keeps them from changing from here on. Only the new column is written; nothing else is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->json('issuer')->nullable()->after('currency');
        });

        DB::table('tenants')->orderBy('id')->each(function ($tenant) {
            $settings = json_decode($tenant->settings ?? '[]', true) ?: [];
            $stamp = [
                'name' => (string) $tenant->name,
                'legal_name' => (string) ($tenant->legal_name ?? ''),
                'address' => (string) ($tenant->address ?? ''),
                'city' => (string) ($tenant->city ?? ''),
                'phone' => (string) ($tenant->phone ?? ''),
                'email' => (string) ($tenant->email ?? ''),
                'vat_number' => trim((string) ($settings['vat_number'] ?? '')),
                'cr_number' => trim((string) ($settings['cr_number'] ?? '')),
                'currency' => (string) ($tenant->currency ?: 'OMR'),
            ];
            DB::table('invoices')->where('tenant_id', $tenant->id)->whereNull('issuer')->update(['issuer' => json_encode($stamp, JSON_UNESCAPED_UNICODE)]);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('issuer'));
    }
};
