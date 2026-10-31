<?php

namespace App\Modules\Inventory\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Gym\Services\PtService;
use App\Modules\Inventory\Models\PosDevice;
use App\Modules\Inventory\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * One basket at the point of sale: products, memberships (new or renewed) and training packages, paid together.
 *
 * Nothing here prices, activates or posts anything itself. Products go through PosService (a sale, its stock and a
 * paid invoice); a membership through MembershipService (a pending membership and its invoice) and a package through
 * PtService — each then paid through PaymentService, which fires InvoicePaid, so ActivatePaidItems activates them
 * exactly as at reception. Paid by Paymob, the membership and package invoices stay open for the customer to pay by
 * link; they activate on Paymob's signed notice, never here. All of it, or none of it.
 */
final class PosCheckout
{
    public function __construct(
        private readonly PosService $pos,
        private readonly MembershipService $memberships,
        private readonly PtService $pt,
        private readonly PaymentService $payments,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  array{items: list<array<string, mixed>>, memberships: list<array{plan_id: int}>, packages: list<array{package_id: int}>, method: string, member: ?Member, discount: string|float, branch_id: ?int, card_reference: ?string, customer_name: ?string, device: ?PosDevice, client_key: ?string}  $basket
     * @return list<array{kind: string, id: int, number: string, label: string, total: string, paid: bool, payment_id: ?int, pay_url: ?string}>
     */
    public function checkout(array $basket): array
    {
        ['items' => $items, 'memberships' => $plans, 'packages' => $packages, 'method' => $method, 'member' => $member] = $basket;
        if ($items === [] && $plans === [] && $packages === []) {
            throw new BusinessRuleException('pos.empty', 'السلة فارغة.', 'items');
        }
        if (($plans !== [] || $packages !== []) && ! $member) {
            throw new BusinessRuleException('pos.member_required', 'العضوية والتدريب الشخصي يُباعان لعضو: اختر العضو أو سجّله أولًا.', 'member_id');
        }
        if ($method === 'paymob' && $items !== []) {
            throw new BusinessRuleException('pos.paymob_products', 'المنتجات تُدفع في مكانها نقدًا أو بالبطاقة؛ رابط Paymob للعضويات والتدريب الشخصي.', 'payment_method');
        }

        $device = $basket['device'];
        $branch = $device?->branch_id ?? $basket['branch_id'];
        $note = 'نقطة البيع'.($device ? ': '.$device->name : '');
        $reference = $method === 'card' ? ($basket['card_reference'] ?: null) : null;

        return DB::transaction(function () use ($basket, $items, $plans, $packages, $method, $member, $device, $branch, $note, $reference) {
            $documents = [];

            if ($items !== []) {
                $sale = $this->pos->checkout($items, $method, $member, $basket['discount'], $branch, $basket['card_reference'], $basket['customer_name'], $device, $basket['client_key']);
                $documents[] = $this->saleDocument($sale);
            }

            foreach ($plans as $line) {
                $membership = $this->memberships->renew($member, MembershipPlan::findOrFail($line['plan_id']), array_filter(['branch_id' => $branch]));
                $documents[] = $this->settle(Invoice::findOrFail($membership->invoice_id), 'membership', $method, $reference, $branch, $note);
            }

            foreach ($packages as $line) {
                $subscription = $this->pt->sell($member, PtPackage::findOrFail($line['package_id']));
                $documents[] = $this->settle(Invoice::findOrFail($subscription->invoice_id), 'pt', $method, $reference, $branch, $note);
            }

            return $documents;
        });
    }

    /** @return array{kind: string, id: int, number: string, label: string, total: string, paid: bool, payment_id: ?int, pay_url: ?string} */
    private function saleDocument(Sale $sale): array
    {
        return ['kind' => 'sale', 'id' => $sale->id, 'number' => $sale->number, 'label' => 'منتجات', 'total' => $sale->total, 'paid' => true, 'payment_id' => null, 'pay_url' => null];
    }

    /**
     * Pay a membership or package invoice in full by the basket's method; by Paymob it stays open with its link.
     *
     * @return array{kind: string, id: int, number: string, label: string, total: string, paid: bool, payment_id: ?int, pay_url: ?string}
     */
    private function settle(Invoice $invoice, string $kind, string $method, ?string $reference, ?int $branch, string $note): array
    {
        $payment = null;
        if ($method !== 'paymob' && $invoice->isOpen()) {
            $payment = $this->payments->record($invoice, $invoice->balance(), $method, ['reference' => $reference ?? $invoice->number, 'branch_id' => $branch ?? $invoice->branch_id, 'notes' => $note]);
        }
        $invoice->refresh();
        $slug = $this->tenants->require()->slug;

        return [
            'kind' => $kind,
            'id' => $invoice->id,
            'number' => $invoice->number,
            'label' => $kind === 'membership' ? 'عضوية' : 'تدريب شخصي',
            'total' => $invoice->total,
            'paid' => ! $invoice->isOpen(),
            'payment_id' => $payment?->id,
            'pay_url' => $method === 'paymob' && $invoice->isOpen() ? route('site.pay.show', ['club' => $slug, 'token' => $invoice->public_token]) : null,
        ];
    }
}
