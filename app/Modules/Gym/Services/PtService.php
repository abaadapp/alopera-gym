<?php

namespace App\Modules\Gym\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Gym\Events\PtSubscriptionActivated;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSession;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Gym\Models\TrainerAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Personal training: packages sold, then sessions booked inside the trainer's availability. */
final class PtService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly AuditLogger $audit,
    ) {}

    public function sell(Member $member, PtPackage $package, ?Trainer $trainer = null): PtSubscription
    {
        if (! $package->is_active) {
            throw new BusinessRuleException('pt.package_inactive', 'الباقة غير متاحة.', 'pt_package_id');
        }

        return DB::transaction(function () use ($member, $package, $trainer) {
            $subscription = PtSubscription::create([
                'member_id' => $member->id,
                'pt_package_id' => $package->id,
                'trainer_id' => $trainer?->id ?? $package->trainer_id,
                'status' => PtSubscription::PENDING,
                'sessions_total' => $package->sessions_count,
                'price' => $package->price,
                'created_by' => Auth::guard('web')->id(),
            ]);

            $invoice = $this->invoices->issue($member, [[
                'description' => 'تدريب شخصي — '.$package->name.' ('.$package->sessions_count.' جلسات)',
                'unit_price' => $package->price,
                'itemable' => $subscription,
            ]], ['type' => 'pt']);

            $subscription->forceFill(['invoice_id' => $invoice->id])->save();

            return $subscription->refresh();
        });
    }

    public function activate(PtSubscription $subscription, ?CarbonImmutable $today = null): PtSubscription
    {
        $today ??= CarbonImmutable::today();
        $subscription = PtSubscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();

        if ($subscription->status !== PtSubscription::PENDING) {
            return $subscription;
        }

        $validity = (int) PtPackage::withTrashed()->whereKey($subscription->pt_package_id)->value('validity_days');
        $subscription->forceFill([
            'status' => PtSubscription::ACTIVE,
            'starts_on' => $today,
            'expires_on' => $today->addDays(max(1, $validity)),
            'activated_at' => now(),
        ])->save();

        $this->audit->log('pt_subscription.activated', $subscription);
        PtSubscriptionActivated::dispatch($subscription);

        return $subscription;
    }

    public function schedule(PtSubscription $subscription, CarbonImmutable $startsAt, ?int $trainerId = null, ?string $notes = null): PtSession
    {
        return DB::transaction(function () use ($subscription, $startsAt, $trainerId, $notes) {
            $subscription = PtSubscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $trainerId ??= $subscription->trainer_id;

            if ($subscription->status !== PtSubscription::ACTIVE) {
                $this->fail('pt.inactive', 'باقة التدريب الشخصي غير نشطة.');
            }
            if (! $trainerId) {
                $this->fail('pt.no_trainer', 'اختاري المدربة.');
            }
            if ($startsAt->isPast()) {
                $this->fail('pt.past', 'لا يمكن جدولة جلسة في وقت مضى.');
            }
            if ($subscription->expires_on && $startsAt->startOfDay()->gt($subscription->expires_on)) {
                $this->fail('pt.expired', 'موعد الجلسة بعد انتهاء صلاحية الباقة.');
            }

            $booked = PtSession::where('pt_subscription_id', $subscription->id)
                ->whereIn('status', [PtSession::SCHEDULED, PtSession::COMPLETED, PtSession::NO_SHOW])->count();
            if ($booked >= $subscription->sessions_total) {
                $this->fail('pt.no_sessions', 'تم جدولة أو استهلاك كل جلسات الباقة.');
            }

            $minutes = (int) (PtPackage::withTrashed()->whereKey($subscription->pt_package_id)->value('session_minutes') ?: 60);
            $endsAt = $startsAt->addMinutes($minutes);

            $this->assertTrainerFree($trainerId, $startsAt, $endsAt);
            $this->assertMemberFree($subscription->member_id, $startsAt, $endsAt);

            return PtSession::create([
                'pt_subscription_id' => $subscription->id,
                'trainer_id' => $trainerId,
                'member_id' => $subscription->member_id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => PtSession::SCHEDULED,
                'notes' => $notes,
            ]);
        });
    }

    public function assertTrainerFree(int $trainerId, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreSessionId = null): void
    {
        $windows = TrainerAvailability::where('trainer_id', $trainerId)->where('weekday', $start->dayOfWeek)->get();
        $inside = $windows->contains(function (TrainerAvailability $w) use ($start, $end) {
            $wStart = CarbonImmutable::parse($start->toDateString().' '.$w->starts_at);
            $wEnd = CarbonImmutable::parse($start->toDateString().' '.$w->ends_at);

            return $start->gte($wStart) && $end->lte($wEnd);
        });

        if (! $inside) {
            $this->fail('pt.outside_availability', 'الموعد خارج أوقات توفر المدربة.');
        }

        $ptClash = PtSession::where('trainer_id', $trainerId)->where('status', PtSession::SCHEDULED)
            ->when($ignoreSessionId, fn ($q) => $q->whereKeyNot($ignoreSessionId))
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
        $classClash = ClassSession::where('trainer_id', $trainerId)->where('status', ClassSession::SCHEDULED)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();

        if ($ptClash || $classClash) {
            $this->fail('pt.trainer_busy', 'المدربة لديها موعد آخر في هذا الوقت.');
        }
    }

    private function assertMemberFree(int $memberId, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $clash = PtSession::where('member_id', $memberId)->where('status', PtSession::SCHEDULED)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();

        if ($clash) {
            $this->fail('pt.member_busy', 'لدى المشتركة جلسة أخرى في هذا الوقت.');
        }
    }

    /** Completed and no-show both consume a session. */
    public function close(PtSession $session, string $status, ?string $notes = null): PtSession
    {
        if (! in_array($status, [PtSession::COMPLETED, PtSession::NO_SHOW, PtSession::CANCELLED], true)) {
            $this->fail('pt.status', 'حالة غير صالحة.');
        }

        return DB::transaction(function () use ($session, $status, $notes) {
            $session = PtSession::whereKey($session->id)->lockForUpdate()->firstOrFail();

            if ($session->status !== PtSession::SCHEDULED) {
                $this->fail('pt.closed', 'تم إغلاق هذه الجلسة بالفعل.');
            }

            $session->forceFill([
                'status' => $status,
                'notes' => $notes ?? $session->notes,
                'cancel_reason' => $status === PtSession::CANCELLED ? $notes : null,
                'completed_by' => Auth::guard('web')->id(),
            ])->save();

            if ($status !== PtSession::CANCELLED) {
                $subscription = PtSubscription::whereKey($session->pt_subscription_id)->lockForUpdate()->firstOrFail();
                $used = $subscription->sessions_used + 1;
                $subscription->forceFill([
                    'sessions_used' => $used,
                    'status' => $used >= $subscription->sessions_total ? PtSubscription::COMPLETED : $subscription->status,
                ])->save();
            }

            return $session;
        });
    }

    /** @return int number of subscriptions expired */
    public function expireDue(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();

        return PtSubscription::where('status', PtSubscription::ACTIVE)
            ->whereNotNull('expires_on')->where('expires_on', '<', $today)
            ->update(['status' => PtSubscription::EXPIRED, 'updated_at' => now()]);
    }

    private function fail(string $rule, string $message): never
    {
        throw new BusinessRuleException($rule, $message, 'starts_at');
    }
}
