<?php

namespace App\Modules\Gym\Access;

use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;

final class AccessDecision
{
    public const REASONS = [
        'ok' => 'مسموح',
        'unknown_credential' => 'رمز/بطاقة/بصمة غير معروفة',
        'member_blocked' => 'حساب المشتركة موقوف',
        'member_inactive' => 'حساب المشتركة غير نشط',
        'no_membership' => 'لا توجد عضوية',
        'membership_expired' => 'العضوية منتهية',
        'membership_frozen' => 'العضوية مجمّدة',
        'membership_not_started' => 'العضوية لم تبدأ بعد',
        'pending_payment' => 'العضوية بانتظار الدفع',
        'dues_outstanding' => 'يوجد مبلغ مستحق متأخر',
        'device_not_for_members' => 'الجهاز غير مخصص للمشتركات',
    ];

    public function __construct(
        public readonly bool $allowed,
        public readonly string $reason,
        public readonly ?Member $member = null,
        public readonly ?Membership $membership = null,
    ) {}

    public static function allow(Member $member, Membership $membership): self
    {
        return new self(true, 'ok', $member, $membership);
    }

    public static function deny(string $reason, ?Member $member = null, ?Membership $membership = null): self
    {
        return new self(false, $reason, $member, $membership);
    }

    public function message(): string
    {
        return self::REASONS[$this->reason] ?? $this->reason;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason,
            'message' => $this->message(),
            'member' => $this->member ? [
                'id' => $this->member->id,
                'name' => $this->member->full_name,
                'member_no' => $this->member->member_no,
                'photo_url' => $this->member->photoUrl(),
            ] : null,
            'membership' => $this->membership ? [
                'plan' => $this->membership->plan_snapshot['name'] ?? null,
                'ends_on' => $this->membership->ends_on->toDateString(),
                'days_left' => $this->membership->daysRemaining(),
                'sessions_left' => $this->membership->sessionsRemaining(),
            ] : null,
        ];
    }
}
