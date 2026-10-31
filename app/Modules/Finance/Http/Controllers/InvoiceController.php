<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Papers\Papers;
use App\Core\Support\Money;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Services\MemberNotifier;
use App\Modules\Messaging\Services\WhatsAppService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $invoices = BranchContext::scope(Invoice::query())
            ->when($request->string('status')->toString(), fn ($q, $s) => match ($s) {
                'open' => $q->open(),
                'overdue' => $q->overdue(),
                default => $q->where('status', $s),
            })
            ->when($request->string('type')->toString(), fn ($q, $t) => $q->where('type', $t))
            ->when($request->string('q')->toString(), fn ($q, $term) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$term}%")->orWhere('customer_name', 'ilike', "%{$term}%")->orWhere('customer_phone', 'like', '%'.preg_replace('/\D/', '', $term).'%')))
            ->when($request->date('from'), fn ($q, $d) => $q->where('issue_date', '>=', $d))
            ->when($request->date('to'), fn ($q, $d) => $q->where('issue_date', '<=', $d))
            ->latest('id')->paginate(25)->withQueryString()
            ->through(fn (Invoice $i) => self::row($i));

        // What is owed across the branch in view: still open, past due, due within a week, and owed back to customers.
        $open = 'greatest(total - credited_total - paid_total, 0)';
        $totals = BranchContext::scope(Invoice::query())->where('status', '!=', Invoice::VOID)->selectRaw(
            "coalesce(sum(case when status in ('issued','partially_paid') then {$open} end), 0) as receivable,
             coalesce(sum(case when status in ('issued','partially_paid') and due_date < ? then {$open} end), 0) as overdue,
             coalesce(sum(case when status in ('issued','partially_paid') and due_date between ? and ? then {$open} end), 0) as due_soon,
             coalesce(sum(greatest(paid_total - (total - credited_total), 0)), 0) as refund_due",
            [today()->toDateString(), today()->toDateString(), today()->addDays(7)->toDateString()],
        )->first();

        return Inertia::render('Finance/Invoices/Index', [
            'invoices' => $invoices,
            'totals' => collect(['receivable', 'overdue', 'due_soon', 'refund_due'])->mapWithKeys(fn ($k) => [$k => Money::normalize($totals->{$k} ?? 0)]),
            'filters' => $request->only('status', 'type', 'q', 'from', 'to'),
        ]);
    }

    /** @return array<string, mixed> */
    public static function row(Invoice $i): array
    {
        return $i->only('id', 'number', 'type', 'status', 'customer_name', 'customer_phone', 'subtotal', 'discount_total', 'tax_total', 'total', 'credited_total', 'paid_total', 'currency', 'billable_type', 'billable_id') + [
            'net_total' => $i->netTotal(),
            'balance' => $i->balance(),
            'refund_due' => $i->refundDue(),
            'issue_date' => $i->issue_date->toDateString(),
            'due_date' => $i->due_date?->toDateString(),
            'overdue' => $i->isOverdue(),
            'days_overdue' => $i->daysOverdue(),
        ];
    }

    public function create(): Response
    {
        return Inertia::render('Finance/Invoices/Create');
    }

    /** Manual invoice (other services, extra items). */
    public function store(Request $request, InvoiceService $service): RedirectResponse
    {
        $data = $request->validate([
            'member_id' => ['nullable', TenantRule::exists('gym_members')],
            'customer_name' => ['required_without:member_id', 'nullable', 'string', 'max:160'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.description' => ['required', 'string', 'max:250'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:10000'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $member = isset($data['member_id']) ? Member::find($data['member_id']) : null;
        $invoice = $service->issue($member, $data['lines'], ['type' => 'other', 'branch_id' => app(BranchContext::class)->assign(), 'due_date' => $data['due_date'] ?? null, 'notes' => $data['notes'] ?? null], $data['customer_name'] ?? null);

        return redirect()->route('club.invoices.show', $invoice)->with('success', 'تم إصدار الفاتورة '.$invoice->number);
    }

    public function show(Request $request, Invoice $invoice, OnlinePaymentService $online): Response
    {
        $invoice->load(['items', 'payments.receiver:id,name', 'creator:id,name', 'creditNotes.creator:id,name']);

        return Inertia::render('Finance/Invoices/Show', [
            'invoice' => self::row($invoice) + [
                'notes' => $invoice->notes, 'void_reason' => $invoice->void_reason, 'creator' => $invoice->creator?->name,
                'paid_at' => $invoice->paid_at?->format('Y-m-d H:i'), 'tax_rate' => $invoice->tax_rate,
                'items' => $invoice->items->map(fn ($i) => $i->only('id', 'description', 'quantity', 'unit_price', 'discount', 'line_total')),
                'payments' => $invoice->payments->map(fn ($p) => $p->only('id', 'receipt_number', 'kind', 'method', 'status', 'amount', 'reference', 'notes', 'rejection_reason') + [
                    'paid_at' => $p->paid_at?->format('Y-m-d H:i'), 'receiver' => $p->receiver?->name, 'has_proof' => $p->proof_path !== null,
                ]),
                'credit_notes' => $invoice->creditNotes->map(fn ($n) => $n->only('id', 'number', 'amount', 'refunded_total', 'reason') + [
                    'refundable' => $n->refundable(), 'issued_at' => $n->created_at->format('Y-m-d H:i'), 'creator' => $n->creator?->name,
                ]),
                'pay_link' => $invoice->isOpen() ? app(MemberNotifier::class)->paymentLink($invoice) : null,
                'public_link' => app(MemberNotifier::class)->invoiceLink($invoice),
            ],
            'intents' => PaymentIntent::where('invoice_id', $invoice->id)->latest()->limit(10)->get()->map(fn ($i) => $i->only('id', 'reference', 'amount', 'status', 'channel', 'failure_reason') + ['created_at' => $i->created_at->format('Y-m-d H:i')]),
            'onlinePayments' => $online->available(),
            'whatsappEnabled' => app(WhatsAppService::class)->enabled(),
            // The invoice as it prints: the same paper as the print page, the PDF and the customer's link.
            'paper' => Papers::for('invoice', $invoice)->toArray(),
        ]);
    }

    /** The print page: the invoice paper with the browser's print dialog. */
    public function print(Request $request, Invoice $invoice): HttpResponse
    {
        return Papers::for('invoice', $invoice)->page($request->boolean('print', true));
    }

    public function void(Request $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $service->void($invoice, $data['reason']);

        return back()->with('success', 'تم إلغاء الفاتورة.');
    }

    /** Send (or re-send) the invoice with its payment link over WhatsApp. */
    public function send(Invoice $invoice, MemberNotifier $notifier, WhatsAppService $whatsapp): RedirectResponse
    {
        if (! $whatsapp->enabled()) {
            return back()->with('error', 'واتساب غير مفعّل. فعّله من الإعدادات ← الربط.');
        }
        if (! $invoice->isOpen()) {
            return back()->with('error', 'لا يوجد مبلغ مستحق على هذه الفاتورة.');
        }
        $member = $invoice->billable_type === 'member' ? Member::find($invoice->billable_id) : null;
        if (! $member) {
            return back()->with('error', 'الفاتورة غير مرتبطة بعضو.');
        }

        $whatsapp->queueTemplate($member, 'invoice_issued', [
            'member_name' => explode(' ', $member->full_name)[0],
            'invoice_number' => $invoice->number,
            'amount' => number_format((float) $invoice->balance(), 3).' '.$invoice->currency,
            'payment_link' => $notifier->paymentLink($invoice),
        ], $invoice, 'invoice:'.$invoice->id.':resend:'.now()->timestamp);

        return back()->with('success', 'تم إرسال الفاتورة عبر واتساب.');
    }
}
