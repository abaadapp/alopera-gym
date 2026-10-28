<?php

namespace App\Modules\Platform\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Models\PlatformInvoice;
use App\Models\PlatformPlan;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The club's subscription to the platform. Entirely separate from the gym
 * memberships the club sells to its own members.
 */
final class SubscriptionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function startTrial(Tenant $tenant, PlatformPlan $plan, string $cycle = TenantSubscription::MONTHLY): TenantSubscription
    {
        $today = CarbonImmutable::today();
        $trialEnd = $today->addDays(max(1, $plan->trial_days));

        $subscription = TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'platform_plan_id' => $plan->id,
            'billing_cycle' => $cycle,
            'status' => TenantSubscription::TRIALING,
            'price' => $plan->priceFor($cycle),
            'starts_on' => $today,
            'ends_on' => $trialEnd,
            'trial_ends_on' => $trialEnd,
        ]);

        $tenant->forceFill(['status' => Tenant::STATUS_TRIAL, 'trial_ends_at' => $trialEnd->endOfDay()])->save();

        return $subscription;
    }

    /**
     * Start (or switch to) a paid period and issue the platform invoice for it.
     */
    public function subscribe(Tenant $tenant, PlatformPlan $plan, string $cycle, ?CarbonImmutable $startsOn = null): TenantSubscription
    {
        if (! in_array($cycle, [TenantSubscription::MONTHLY, TenantSubscription::YEARLY], true)) {
            throw new BusinessRuleException('subscription.cycle', 'دورة فوترة غير صالحة.', 'billing_cycle');
        }

        return DB::transaction(function () use ($tenant, $plan, $cycle, $startsOn) {
            $start = $startsOn ?? CarbonImmutable::today();
            $end = $cycle === TenantSubscription::YEARLY ? $start->addYearNoOverflow()->subDay() : $start->addMonthNoOverflow()->subDay();

            TenantSubscription::where('tenant_id', $tenant->id)
                ->whereIn('status', [TenantSubscription::TRIALING, TenantSubscription::ACTIVE, TenantSubscription::PAST_DUE])
                ->update(['status' => TenantSubscription::CANCELLED, 'cancelled_at' => now(), 'updated_at' => now()]);

            $subscription = TenantSubscription::create([
                'tenant_id' => $tenant->id,
                'platform_plan_id' => $plan->id,
                'billing_cycle' => $cycle,
                'status' => TenantSubscription::PAST_DUE, // becomes active when its invoice is paid
                'price' => $plan->priceFor($cycle),
                'starts_on' => $start,
                'ends_on' => $end,
            ]);

            $this->issueInvoice($subscription);
            $tenant->forceFill(['status' => Tenant::STATUS_PAST_DUE])->save();
            $this->audit->log('platform.subscription_started', $subscription, ['plan' => $plan->code, 'cycle' => $cycle], tenantId: $tenant->id);

            return $subscription;
        });
    }

    public function issueInvoice(TenantSubscription $subscription): PlatformInvoice
    {
        $count = PlatformInvoice::count() + 1;

        return PlatformInvoice::create([
            'tenant_id' => $subscription->tenant_id,
            'tenant_subscription_id' => $subscription->id,
            'number' => 'PLT-'.now()->format('Y').'-'.str_pad((string) $count, 5, '0', STR_PAD_LEFT),
            'status' => PlatformInvoice::UNPAID,
            'amount' => $subscription->price,
            'currency' => config('gym.platform_currency', 'OMR'),
            'period_start' => $subscription->starts_on,
            'period_end' => $subscription->ends_on,
            'due_on' => $subscription->starts_on->addDays(7),
        ]);
    }

    /** Super admin records the club's payment (bank transfer/cash) for a platform invoice. */
    public function markPaid(PlatformInvoice $invoice, string $method, ?string $reference = null): PlatformInvoice
    {
        return DB::transaction(function () use ($invoice, $method, $reference) {
            $invoice = PlatformInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status !== PlatformInvoice::UNPAID) {
                throw new BusinessRuleException('platform_invoice.not_unpaid', 'الفاتورة ليست غير مدفوعة.');
            }

            $invoice->forceFill([
                'status' => PlatformInvoice::PAID,
                'paid_at' => now(),
                'payment_method' => $method,
                'payment_reference' => $reference,
                'recorded_by' => Auth::id(),
            ])->save();

            $subscription = $invoice->subscription;
            if ($subscription && in_array($subscription->status, [TenantSubscription::PAST_DUE, TenantSubscription::TRIALING], true)) {
                $subscription->forceFill(['status' => TenantSubscription::ACTIVE])->save();
            }

            $tenant = $invoice->tenant;
            if (in_array($tenant->status, [Tenant::STATUS_PAST_DUE, Tenant::STATUS_TRIAL, Tenant::STATUS_SUSPENDED], true)) {
                $tenant->forceFill(['status' => Tenant::STATUS_ACTIVE, 'suspended_at' => null, 'suspension_reason' => null])->save();
            }

            $this->audit->log('platform.invoice_paid', $invoice, ['method' => $method], tenantId: $invoice->tenant_id);

            return $invoice;
        });
    }

    public function suspend(Tenant $tenant, string $reason): void
    {
        $tenant->forceFill(['status' => Tenant::STATUS_SUSPENDED, 'suspended_at' => now(), 'suspension_reason' => $reason])->save();
        $this->audit->log('platform.tenant_suspended', $tenant, ['reason' => $reason], tenantId: $tenant->id);
    }

    public function reactivate(Tenant $tenant): void
    {
        $tenant->forceFill(['status' => Tenant::STATUS_ACTIVE, 'suspended_at' => null, 'suspension_reason' => null])->save();
        $this->audit->log('platform.tenant_reactivated', $tenant, tenantId: $tenant->id);
    }

    /**
     * Daily: end trials and periods; renew auto-renewing subscriptions with a
     * new invoice; suspend clubs that stay unpaid beyond the grace period.
     *
     * @return array{renewed: int, suspended: int}
     */
    public function runDaily(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $grace = (int) config('gym.subscription_grace_days', 7);
        $result = ['renewed' => 0, 'suspended' => 0];

        $due = TenantSubscription::with(['tenant', 'plan'])
            ->whereIn('status', [TenantSubscription::TRIALING, TenantSubscription::ACTIVE])
            ->where('ends_on', '<', $today)
            ->get();

        foreach ($due as $subscription) {
            $subscription->forceFill(['status' => TenantSubscription::EXPIRED])->save();
            if ($subscription->auto_renew && $subscription->tenant && $subscription->plan) {
                $this->subscribe($subscription->tenant, $subscription->plan, $subscription->billing_cycle, $subscription->ends_on->addDay());
                $result['renewed']++;
            }
        }

        $overdue = PlatformInvoice::with('tenant')
            ->where('status', PlatformInvoice::UNPAID)
            ->where('due_on', '<', $today->subDays($grace))
            ->get();

        foreach ($overdue as $invoice) {
            if ($invoice->tenant && $invoice->tenant->status !== Tenant::STATUS_SUSPENDED) {
                $this->suspend($invoice->tenant, 'فاتورة المنصة '.$invoice->number.' غير مدفوعة');
                $result['suspended']++;
            }
        }

        return $result;
    }
}
