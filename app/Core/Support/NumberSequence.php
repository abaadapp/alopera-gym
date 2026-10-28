<?php

namespace App\Core\Support;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free, per-club document numbers (INV-2026-000042, RC-000017, M-00031).
 *
 * The counter row is locked for the duration of the surrounding transaction,
 * so two cashiers issuing at the same moment never get the same number.
 */
final class NumberSequence
{
    public const FORMATS = [
        'member' => ['prefix' => 'M-', 'pad' => 5, 'yearly' => false],
        'invoice' => ['prefix' => 'INV-', 'pad' => 6, 'yearly' => true],
        'receipt' => ['prefix' => 'RC-', 'pad' => 6, 'yearly' => true],
        'credit_note' => ['prefix' => 'CN-', 'pad' => 6, 'yearly' => true],
        'sale' => ['prefix' => 'POS-', 'pad' => 6, 'yearly' => true],
        'purchase_order' => ['prefix' => 'PO-', 'pad' => 5, 'yearly' => true],
        'expense' => ['prefix' => 'EX-', 'pad' => 5, 'yearly' => true],
    ];

    public static function next(string $key, ?int $tenantId = null): string
    {
        $tenantId ??= app(TenantContext::class)->require()->id;
        $format = self::FORMATS[$key] ?? ['prefix' => strtoupper($key).'-', 'pad' => 6, 'yearly' => false];
        $counterKey = $format['yearly'] ? $key.':'.now()->year : $key;

        $value = DB::transaction(function () use ($tenantId, $counterKey) {
            DB::table('number_sequences')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'key' => $counterKey,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('number_sequences')
                ->where('tenant_id', $tenantId)
                ->where('key', $counterKey)
                ->lockForUpdate()
                ->first();

            DB::table('number_sequences')->where('id', $row->id)->update([
                'next_value' => $row->next_value + 1,
                'updated_at' => now(),
            ]);

            return (int) $row->next_value;
        });

        return $format['prefix']
            .($format['yearly'] ? now()->year.'-' : '')
            .str_pad((string) $value, $format['pad'], '0', STR_PAD_LEFT);
    }
}
