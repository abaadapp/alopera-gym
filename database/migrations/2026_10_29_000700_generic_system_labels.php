<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The system now speaks to any club (women's, men's or mixed): the built-in role names and descriptions and the
 * sales revenue account get their generic wording. Only rows still carrying the old built-in text change; a
 * name a club chose itself is left alone.
 */
return new class extends Migration
{
    private const ROLES = [
        'owner' => [['مالكة النادي', 'مالك النادي'], null],
        'reception' => [null, ['تسجيل الدخول والحجوزات وبيع العضويات وتحصيل الدفعات.', 'تسجيل الدخول والحجوزات وبيع العضويات والمنتجات وتحصيل الدفعات وتسليم طلبات الموقع.']],
        'finance' => [null, ['الفواتير والتحصيل والمصروفات والحسابات، وإعداد الرواتب وصرفها بعد اعتماد المالكة.', 'الفواتير والتحصيل والمصروفات والحسابات، وإعداد الرواتب وصرفها بعد اعتماد المالك.']],
        'trainer' => [['المدربة', 'المدرب'], ['حصصها وجلساتها الفردية وتسجيل حضور مشتركاتها.', 'حصصه وجلساته الفردية وتسجيل حضور أعضائه.']],
    ];

    public function up(): void
    {
        foreach (self::ROLES as $key => [$name, $description]) {
            if ($name) {
                DB::table('roles')->where('key', $key)->where('is_system', true)->where('name', $name[0])->update(['name' => $name[1]]);
            }
            if ($description) {
                DB::table('roles')->where('key', $key)->where('is_system', true)->where('description', $description[0])->update(['description' => $description[1]]);
            }
        }

        DB::table('gym_accounts')->where('system_key', 'revenue_pos')->where('name', 'إيرادات نقطة البيع (سابقة)')->update(['name' => 'إيرادات المبيعات']);
    }

    public function down(): void
    {
        // Wording only.
    }
};
