<?php

namespace App\Modules\Gym\Http;

use App\Core\Support\Phone;
use App\Core\Support\SiteLanguage;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\GiftWhatsApp;

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
            'photo_url' => $m->photoThumbUrl(),
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
            // What this period gives, as it was sold (a period sold before the rule keeps both).
            'opens_door_any_day' => $m->opensDoorAnyDay(),
            'books_classes' => $m->booksClasses(),
            'invoice_id' => $m->invoice_id,
            'cancel_reason' => $m->cancel_reason,
            'activated_at' => $m->activated_at?->format('Y-m-d H:i'),
            'created_at' => $m->created_at?->format('Y-m-d H:i'),
        ];
    }

    /** @return array<string, mixed> */
    public static function plan(MembershipPlan $p): array
    {
        return $p->only('id', 'name', 'name_en', 'type', 'description', 'description_en', 'duration_value', 'duration_unit', 'price', 'signup_fee', 'sessions_limit',
            'freeze_allowed', 'max_freeze_days', 'max_freezes', 'features', 'features_en', 'color', 'is_public', 'is_featured', 'is_active', 'giftable', 'sort') + [
                'duration_label' => $p->durationLabel(),
                'type_label' => MembershipPlan::TYPES[$p->type] ?? $p->type,
                // What a sale of it gives now (the panel's switches, the website's and portal's lists).
                'allows_gym' => $p->allowsGym(),
                'allows_classes' => $p->allowsClasses(),
                'program_ids' => $p->relationLoaded('programs') ? $p->programs->pluck('id')->all() : [],
            ];
    }

    /** A gift membership (#192) as the panel lists and shows it: its own terms, never the link's token. @return array<string, mixed> */
    public static function gift(GiftMembership $g): array
    {
        $status = $g->displayStatus();
        $snapshot = $g->plan_snapshot ?? [];
        $invoice = $g->relationLoaded('invoice') ? $g->invoice : null;

        return [
            'id' => $g->id,
            'number' => $g->number,
            'status' => $status,
            'status_label' => GiftMembership::STATUS_LABELS[$status] ?? $status,
            'status_label_en' => GiftMembership::STATUS_LABELS_EN[$status] ?? $status,
            'source' => $g->source,
            'giver_name' => $g->giver_name,
            'giver_phone' => $g->giver_phone,
            'giver_email' => $g->giver_email,
            'recipient_name' => $g->recipient_name,
            'recipient_phone' => $g->recipient_phone,
            'message' => $g->message,
            'plan' => [
                'id' => $g->membership_plan_id,
                'name' => $snapshot['name'] ?? '',
                'name_en' => $snapshot['name_en'] ?? null,
                'duration_label' => $snapshot['duration_label'] ?? null,
            ],
            'price' => $g->price,
            'invoice' => $invoice ? [
                'id' => $invoice->id, 'number' => $invoice->number, 'status' => $invoice->status,
                'total' => $invoice->total, 'paid_total' => $invoice->paid_total, 'balance' => $invoice->balance(),
            ] : null,
            'paid_at' => $g->paid_at?->toIso8601String(),
            'expires_on' => $g->expires_on?->toDateString(),
            'redeemed_at' => $g->redeemed_at?->toIso8601String(),
            'redeemed_via' => $g->redeemed_via,
            'member' => $g->relationLoaded('member') && $g->member ? $g->member->only('id', 'full_name', 'member_no') : null,
            'membership_id' => $g->membership_id,
            'cancelled_at' => $g->cancelled_at?->toIso8601String(),
            'cancel_reason' => $g->cancel_reason,
            'refunded_at' => $g->refunded_at?->toIso8601String(),
            // Withdrawn by a credit note: whether the money is actually back with the buyer (not the note itself).
            'refund' => $g->refundState(),
            // The recipient's WhatsApp: confirmed by her message, and where the gift's message is (#192).
            'phone_verified_at' => $g->phone_verified_at?->toIso8601String(),
            'delivery' => (function () use ($g) {
                $state = GiftWhatsApp::stateOf($g, $g->relationLoaded('whatsappMessage') ? $g->whatsappMessage : $g->whatsappMessage()->first());

                return ['state' => $state, 'label' => GiftMembership::DELIVERY_LABELS[$state] ?? $state];
            })(),
            'expired_at' => $g->expired_at?->toIso8601String(),
            'created_at' => $g->created_at?->toIso8601String(),
        ];
    }

    /** A plan as the website and portal show it, in the page's language (the English words when written). @return array<string, mixed> */
    public static function sitePlan(MembershipPlan $p): array
    {
        return [
            'name' => SiteLanguage::pick($p->name, $p->name_en),
            'description' => SiteLanguage::pick($p->description, $p->description_en),
            'features' => SiteLanguage::pickList($p->features, $p->features_en),
            'duration_label' => $p->siteDurationLabel(),
            'type_label' => SiteLanguage::word(MembershipPlan::TYPES[$p->type] ?? $p->type),
            // «إهداء هذه الباقة» (#192): only on a plan the club offers as a gift.
            'gift_url' => $p->giftable ? route('site.gift.create', ['plan' => $p->id]) : null,
        ] + self::plan($p);
    }

    /** A class as the website and portal show it, in the page's language. @return array<string, mixed> */
    public static function siteSession(ClassSession $s): array
    {
        return [
            'program' => $s->relationLoaded('program') ? SiteLanguage::pick($s->program?->name, $s->program?->name_en) : null,
            'trainer' => $s->relationLoaded('trainer') ? SiteLanguage::pick($s->trainer?->name, $s->trainer?->name_en) : null,
        ] + self::session($s);
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
