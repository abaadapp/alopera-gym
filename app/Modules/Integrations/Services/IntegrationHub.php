<?php

namespace App\Modules\Integrations\Services;

use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Gym\Models\AccessDevice;
use Illuminate\Support\Collection;

/**
 * The club's three integrations — Paymob, access devices and WhatsApp Business — and the state of each, in four
 * words used everywhere: not enabled, needs setup, connected after verification, error.
 *
 * «Connected» is earned, never typed in: Paymob accepted the keys (a connection test or a payment page it opened),
 * Meta answered for the number, or a device called in with its own token. Saving keys alone is still «needs setup».
 */
final class IntegrationHub
{
    public const OFF = 'off';

    public const SETUP = 'setup';

    public const CONNECTED = 'connected';

    public const ERROR = 'error';

    public const LABELS = [
        self::OFF => 'غير مفعّل',
        self::SETUP => 'يحتاج إعدادًا',
        self::CONNECTED => 'متصل بعد التحقق',
        self::ERROR => 'خطأ',
    ];

    public const PAYMOB_FIELDS = [
        'secret_key' => 'Secret key', 'public_key' => 'Public key', 'hmac_secret' => 'HMAC secret', 'integration_ids' => 'Integration IDs',
    ];

    public const WHATSAPP_FIELDS = [
        'phone_number_id' => 'Phone number ID', 'business_account_id' => 'WhatsApp Business Account ID', 'access_token' => 'Access token',
    ];

    /** @return list<array<string, mixed>> */
    public function cards(User $user): array
    {
        $rows = TenantIntegration::all()->keyBy('provider');

        return [
            [
                'key' => 'paymob',
                'name' => 'Paymob',
                'subtitle' => 'الدفع الإلكتروني',
                'site' => 'paymob.com',
                'line' => 'دفع العضويات والباقات وفواتير النادي وطلبات متجر الموقع بالبطاقة. لا يُعتمد أي دفع إلا بإشعار Paymob الموقّع.',
                'route' => 'club.integrations.paymob',
                'can' => $user->can('integrations.manage'),
                'status' => $this->paymob($rows->get(TenantIntegration::PAYMOB)),
            ],
            [
                'key' => 'devices',
                'name' => 'أجهزة البصمة والبوابات',
                'subtitle' => 'الدخول والحضور',
                'site' => 'QR · RFID · بصمة · بوابات',
                'line' => 'تسجيل الأجهزة وربطها بالفروع، ودخول الأعضاء وحضور الموظفين، وحالة كل جهاز وسجل قراءاته.',
                'route' => 'club.devices.index',
                'can' => $user->can('devices.manage'),
                'status' => $this->devices(),
            ],
            [
                'key' => 'whatsapp',
                'name' => 'WhatsApp Business',
                'subtitle' => 'المحادثات والتنبيهات',
                'site' => 'business.whatsapp.com',
                'line' => 'محادثات الأعضاء وتذكير التجديد والحصص وتنبيهات المستندات، بقوالب معتمدة وبموافقة المستلم.',
                'route' => 'club.integrations.whatsapp',
                'can' => $user->can('integrations.manage'),
                'status' => $this->whatsapp($rows->get(TenantIntegration::WHATSAPP)),
            ],
        ];
    }

    /** @return array{state: string, label: string, detail: string} */
    public function paymob(?TenantIntegration $row): array
    {
        if (! $row?->is_enabled) {
            return $this->state(self::OFF, $row?->credential('secret_key') ? 'المفاتيح محفوظة والدفع الإلكتروني متوقف.' : 'لم يُربط حساب Paymob بعد.');
        }
        $missing = $this->missing($row, self::PAYMOB_FIELDS);
        if ($missing !== []) {
            return $this->state(self::SETUP, 'ينقص: '.implode('، ', $missing).'.');
        }
        if ($row->failing()) {
            return $this->state(self::ERROR, (string) $row->last_error);
        }
        if ($row->verified_at) {
            return $this->state(self::CONNECTED, 'قبلت Paymob المفاتيح '.$this->when($row->verified_at).($row->last_webhook_at ? '، وآخر إشعار موقّع '.$this->when($row->last_webhook_at) : '، ولم يصل إشعار دفع موقّع بعد').'.');
        }

        return $this->state(self::SETUP, 'المفاتيح مكتملة ولم يُتحقق منها بعد. اضغط «اختبار الاتصال».');
    }

    /** @return array{state: string, label: string, detail: string} */
    public function whatsapp(?TenantIntegration $row): array
    {
        if (! $row?->is_enabled) {
            return $this->state(self::OFF, $row?->credential('access_token') ? 'المفاتيح محفوظة وواتساب متوقف.' : 'لم يُربط حساب WhatsApp Business بعد.');
        }
        $missing = $this->missing($row, ['phone_number_id' => 'Phone number ID', 'access_token' => 'Access token']);
        if ($missing !== []) {
            return $this->state(self::SETUP, 'ينقص: '.implode('، ', $missing).'.');
        }
        if ($row->failing()) {
            return $this->state(self::ERROR, (string) $row->last_error);
        }
        if ($row->verified_at) {
            $name = $row->option('verified_name');

            return $this->state(self::CONNECTED, 'تحققت Meta من الرقم'.($name ? ' «'.$name.'»' : '').' '.$this->when($row->verified_at).'.');
        }

        return $this->state(self::SETUP, 'المفاتيح مكتملة ولم يُتحقق منها بعد. اضغط «اختبار الاتصال».');
    }

    /** @return array{state: string, label: string, detail: string} */
    public function devices(?Collection $devices = null): array
    {
        $devices ??= AccessDevice::get(['id', 'is_active', 'last_seen_at']);
        $active = $devices->where('is_active', true);
        if ($active->isEmpty()) {
            return $this->state(self::OFF, $devices->isEmpty() ? 'لا توجد أجهزة مسجّلة.' : 'كل الأجهزة المسجّلة موقوفة.');
        }
        $seen = $active->filter(fn (AccessDevice $d) => $d->last_seen_at !== null);
        if ($seen->isEmpty()) {
            return $this->state(self::SETUP, 'سُجّل '.$active->count().' جهاز ولم يتصل أي منها بعد برمزه.');
        }
        $online = $active->filter(fn (AccessDevice $d) => $d->isOnline())->count();
        if ($online === 0) {
            return $this->state(self::ERROR, 'لا يوجد جهاز متصل الآن. آخر اتصال '.$this->when($seen->max('last_seen_at')).'.');
        }

        return $this->state(self::CONNECTED, $online === $active->count() ? 'كل الأجهزة متصلة ('.$online.').' : 'متصل الآن '.$online.' من '.$active->count().' أجهزة.');
    }

    /** @return array{state: string, label: string, detail: string} */
    private function state(string $state, string $detail): array
    {
        return ['state' => $state, 'label' => self::LABELS[$state], 'detail' => $detail];
    }

    /**
     * @param  array<string, string>  $fields
     * @return list<string>
     */
    private function missing(TenantIntegration $row, array $fields): array
    {
        return array_values(array_filter($fields, fn ($label, $key) => $row->credential($key) === null, ARRAY_FILTER_USE_BOTH));
    }

    private function when(\DateTimeInterface $at): string
    {
        return 'في '.$at->format('Y-m-d H:i');
    }
}
