<?php

namespace App\Modules\Gym\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\NumberSequence;
use App\Core\Support\Phone;
use App\Modules\Gym\Events\MemberRegistered;
use App\Modules\Gym\Models\Member;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class MemberService
{
    /** @param array<string, mixed> $data validated attributes */
    public function create(array $data, ?string $password = null): Member
    {
        return DB::transaction(function () use ($data, $password) {
            $data['phone'] = Phone::normalize($data['phone']);
            if (! empty($data['emergency_contact_phone'])) {
                $data['emergency_contact_phone'] = Phone::normalize($data['emergency_contact_phone']);
            }

            if (Member::withTrashed()->where('phone', $data['phone'])->exists()) {
                throw new BusinessRuleException('member.phone_taken', 'رقم الهاتف مسجّل لعضو آخر.', 'phone');
            }

            $member = new Member($data);
            $member->member_no = NumberSequence::next('member');
            $member->joined_on ??= today();
            $member->created_by = Auth::guard('web')->id();
            if (! empty($data['marketing_opt_in'])) {
                $member->marketing_consent_at = now();
            }
            if ($password) {
                $member->password = $password;
            }
            $member->save();

            MemberRegistered::dispatch($member);

            return $member;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Member $member, array $data): Member
    {
        if (isset($data['phone'])) {
            $data['phone'] = Phone::normalize($data['phone']);
            $taken = Member::withTrashed()->where('phone', $data['phone'])->whereKeyNot($member->id)->exists();
            if ($taken) {
                throw new BusinessRuleException('member.phone_taken', 'رقم الهاتف مسجّل لعضو آخر.', 'phone');
            }
        }

        if (array_key_exists('marketing_opt_in', $data)) {
            $optIn = (bool) $data['marketing_opt_in'];
            if ($optIn && ! $member->marketing_opt_in) {
                $member->marketing_consent_at = now();
                $member->marketing_opt_out_at = null;
            } elseif (! $optIn && $member->marketing_opt_in) {
                $member->marketing_opt_out_at = now();
            }
        }

        $member->fill($data)->save();

        return $member;
    }
}
