<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One default printer per point-of-sale device, held by the database itself: a partial unique index, so two saves at
 * the same moment (two screens, a double click) can never leave a device with two, whatever the code does.
 *
 * The scope is the device: a device belongs to one branch, so each till of a branch keeps its own default and a
 * branch with several tills has one per till.
 *
 * Nothing is reset where nothing is wrong. Only a device that already has two or more default printers is touched,
 * and it keeps the one its receipts go to today (the oldest active default, else the oldest default); the others stay
 * as they are, only no longer default. No other column and no other row changes.
 */
return new class extends Migration
{
    private const INDEX = 'pos_peripherals_one_default_printer';

    public function up(): void
    {
        $doubled = DB::table('pos_peripherals')->where('type', 'printer')->where('is_default', true)
            ->groupBy('pos_device_id')->havingRaw('count(*) > 1')->pluck('pos_device_id');

        foreach ($doubled as $device) {
            $keep = DB::table('pos_peripherals')->where('pos_device_id', $device)->where('type', 'printer')->where('is_default', true)
                ->orderByDesc('is_active')->orderBy('id')->value('id');
            DB::table('pos_peripherals')->where('pos_device_id', $device)->where('type', 'printer')->where('is_default', true)
                ->where('id', '<>', $keep)->update(['is_default' => false]);
        }

        DB::statement('CREATE UNIQUE INDEX '.self::INDEX.' ON pos_peripherals (pos_device_id) WHERE is_default AND type = \'printer\'');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }
};
