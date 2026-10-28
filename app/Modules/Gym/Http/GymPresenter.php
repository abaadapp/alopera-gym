<?php

namespace App\Modules\Gym\Http;

use App\Core\Support\Phone;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;

/** Shapes gym models into the arrays the pages receive. */
final class GymPresenter
{
    /** @return array<string, mixed> */
    public static function memberRow(Member $m): array
    {
        $current = $m->relationLoaded('currentMembership') ? $m->currentMembership : null;
        $current ??= $m->relationLoaded('upcomingMembership') ? $m->upcomingMembership : null;

        return [
            'id' => $m->id,
            'member_no' => $m->member_no,
            'full_name' => $m->full_name,
            'phone' => $m->phone,
            'phone_display' => Phone::display($m->phone),
            'email' => $m->email,
            'status' => $m->status,
            'photo_url' => $m->photoUrl(),
            'joined_on' => $m->joined_on?->toDateString(),
            'last_check_in_at' => $m->last_check_in_at?->format('Y-m-d H:i'),
            'membership' => $current ? self::membership($current) : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function membership(Membership $m): array
    {
        return [
            'id' => $m->id,
            'plan' => $m->plan_snapshot['name'] ?? '',
            'plan_id' => $m->membership_plan_id,
            'kind' => $m->kind,
            'status' => $m->status,
            'starts_on' => $m->starts_on->toDateString(),
            'ends_on' => $m->ends_on->toDateString(),
            'days_left' => $m->daysRemaining(),
            'sessions_total' => $m->sessions_total,
            'sessions_used' => $m->sessions_used,
            'sessions_left' => $m->sessionsRemaining(),
            'total' => $m->total,
            'discount' => $m->discount,
            'credit_applied' => $m->credit_applied,
            'freeze_days_used' => $m->freeze_days_used,
            'freezes_count' => $m->freezes_count,
            'freeze_allowed' => (bool) ($m->plan_snapshot['freeze_allowed'] ?? false),
            'max_freeze_days' => (int) ($m->plan_snapshot['max_freeze_days'] ?? 0),
            'invoice_id' => $m->invoice_id,
            'cancel_reason' => $m->cancel_reason,
            'activated_at' => $m->activated_at?->format('Y-m-d H:i'),
            'created_at' => $m->created_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return array<string, mixed> */
    public static function plan(MembershipPlan $p): array
    {
        return $p->only('id', 'name', 'type', 'description', 'duration_value', 'duration_unit', 'price', 'signup_fee', 'sessions_limit',
            'freeze_allowed', 'max_freeze_days', 'max_freezes', 'features', 'color', 'is_public', 'is_featured', 'is_active', 'sort') + [
                'duration_label' => $p->durationLabel(),
                'type_label' => MembershipPlan::TYPES[$p->type] ?? $p->type,
                'program_ids' => $p->relationLoaded('programs') ? $p->programs->pluck('id')->all() : [],
            ];
    }

    /** @return array<string, mixed> */
    public static function session(ClassSession $s): array
    {
        return [
            'id' => $s->id,
            'program' => $s->relationLoaded('program') ? $s->program?->name : null,
            'program_id' => $s->program_id,
            'color' => $s->relationLoaded('program') ? $s->program?->color : null,
            'trainer' => $s->relationLoaded('trainer') ? $s->trainer?->name : null,
            'trainer_id' => $s->trainer_id,
            'starts_at' => $s->starts_at->format('Y-m-d H:i'),
            'ends_at' => $s->ends_at->format('Y-m-d H:i'),
            'date' => $s->starts_at->toDateString(),
            'time' => $s->starts_at->format('H:i'),
            'end_time' => $s->ends_at->format('H:i'),
            'capacity' => $s->capacity,
            'booked' => $s->booked_count,
            'seats_left' => $s->seatsLeft(),
            'is_full' => $s->isFull(),
            'room' => $s->room,
            'status' => $s->status,
        ];
    }
}
