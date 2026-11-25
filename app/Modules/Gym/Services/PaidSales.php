<?php

namespace App\Modules\Gym\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Gym\Models\Trainer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A membership or a personal-training package is sold only when it is paid (owner's decision, 2026-11-25): no invoice
 * before the payment and no «بانتظار الدفع». At the desk the sale and the whole payment are one step — the invoice is
 * issued and paid at once, so ActivatePaidItems activates it on the spot; if the money cannot be recorded (a closed
 * drawer, a missing reference…), nothing is sold. Online (the website, the portal, a payment link) the sale waits for
 * Paymob's confirmed money instead (MembershipCheckouts).
 *
 * The member receives the payment and the activation messages, never an invoice to pay.
 */
final class PaidSales
{
    /** How money is taken at the desk. A bank transfer is the money seen in the account: recorded as confirmed. */
    public const METHODS = ['cash', 'card', 'bank_transfer'];

    public function __construct(
        private readonly MembershipService $memberships,
        private readonly PtService $pt,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  string  $mode  new | renewal
     * @param  array<string, mixed>  $options  MembershipService's (coupon_code, discount, notes, starts_on, branch_id)
     * @param  array{method: string, reference?: string|null, account_id?: int|null}  $payment
     */
    public function membership(Member $member, MembershipPlan $plan, string $mode, array $options, array $payment): Membership
    {
        return DB::transaction(function () use ($member, $plan, $mode, $options, $payment) {
            $options['invoice_message'] = false;
            $membership = $mode === 'renewal' ? $this->memberships->renew($member, $plan, $options) : $this->memberships->sell($member, $plan, $options);
            $this->payInFull($membership->invoice_id, $payment, $options['branch_id'] ?? null);

            return $membership->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array{method: string, reference?: string|null, account_id?: int|null}  $payment
     */
    public function upgrade(Membership $current, MembershipPlan $plan, array $options, array $payment): Membership
    {
        return DB::transaction(function () use ($current, $plan, $options, $payment) {
            $membership = $this->memberships->upgrade($current, $plan, $options + ['invoice_message' => false]);
            $this->payInFull($membership->invoice_id, $payment, $options['branch_id'] ?? null);

            return $membership->refresh();
        });
    }

    /** @param array{method: string, reference?: string|null, account_id?: int|null} $payment */
    public function pt(Member $member, PtPackage $package, ?Trainer $trainer, array $payment, ?int $branchId = null): PtSubscription
    {
        return DB::transaction(function () use ($member, $package, $trainer, $payment, $branchId) {
            $subscription = $this->pt->sell($member, $package, $trainer, $branchId);
            $this->payInFull($subscription->invoice_id, $payment, $branchId);

            return $subscription->refresh();
        });
    }

    /**
     * The whole amount, recorded on the invoice just issued: it ends paid, or nothing stands (the caller's transaction).
     *
     * @param  array{method: string, reference?: string|null, account_id?: int|null}  $payment
     */
    private function payInFull(?int $invoiceId, array $payment, ?int $branchId): void
    {
        $invoice = Invoice::whereKey($invoiceId)->firstOrFail();
        if ($invoice->status === Invoice::PAID || Money::isZero($invoice->balance())) {
            return; // nothing to collect (a full discount, an upgrade's credit): paid on issue
        }
        $method = (string) ($payment['method'] ?? '');
        if (! in_array($method, self::METHODS, true)) {
            throw new BusinessRuleException('sale.method', 'اختاري طريقة الدفع.', 'method');
        }
        if ($method === 'card' && blank($payment['reference'] ?? null)) {
            throw new BusinessRuleException('sale.reference', 'أدخلي رقم العملية من جهاز الشبكة.', 'reference');
        }
        if ($method === 'bank_transfer' && ! Auth::guard('web')->user()?->can('payments.confirm')) {
            throw new BusinessRuleException('sale.transfer', 'البيع بتحويل بنكي يحتاج صلاحية تأكيد التحويلات، لأن المبلغ يجب أن يكون قد وصل الحساب. اطلبي من المالية، أو أرسلي للعضوة رابط دفع.', 'method');
        }

        $recorded = $this->payments->record($invoice, $invoice->balance(), $method, array_filter([
            'reference' => $payment['reference'] ?? null,
            'account_id' => $payment['account_id'] ?? null,
            'branch_id' => $branchId,
        ], fn ($v) => $v !== null && $v !== ''));
        // A transfer is recorded waiting for confirmation: here it was seen in the account, so it is confirmed now.
        if ($recorded->status === Payment::PENDING) {
            $this->payments->confirm($recorded, $payment['reference'] ?? null);
        }

        if (Invoice::whereKey($invoice->id)->value('status') !== Invoice::PAID) {
            throw new BusinessRuleException('sale.unpaid', 'لم يكتمل تسجيل المبلغ، فلم يُبَع شيء.', 'method');
        }
    }
}
