<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Auth\PermissionRegistry;
use App\Core\Papers\Papers;
use App\Core\Support\Money;
use App\Core\Support\Period;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceAttachment;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\ManualInvoices;
use App\Modules\Gym\Models\Member;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Messaging\Services\MemberNotifier;
use App\Modules\Messaging\Services\WhatsAppService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoiceController extends Controller
{
    /** The list's tabs: how much of an invoice is paid, and whether it is past due (late wins: it is what to chase). */
    public const STATES = ['unpaid' => 'غير مدفوعة', 'partial' => 'مدفوعة جزئيًا', 'paid' => 'مدفوعة', 'overdue' => 'متأخرة', 'draft' => 'مسودات'];

    /** The document itself, beside its payment: in force, voided, or cancelled in full by a credit note. */
    public const DOCUMENT_STATUSES = ['active' => 'سارية', 'void' => 'ملغاة', 'credited' => 'ملغاة بإشعار دائن'];

    public function index(Request $request): Response
    {
        $perPage = in_array($request->integer('per_page'), [20, 50, 100], true) ? $request->integer('per_page') : 20;
        $period = Period::fromRequest($request);
        // The drafts tab lists the editor's saved drafts (not invoices yet: no number, nothing owed); every other view
        // lists issued invoices only.
        $drafts = $request->string('state')->toString() === 'draft';
        $invoices = BranchContext::scope($drafts ? Invoice::drafts() : Invoice::query())
            ->when($drafts ? '' : $request->string('state')->toString(), fn ($q, $s) => match ($s) {
                'unpaid' => $q->where('status', Invoice::ISSUED)->where(fn ($w) => $w->whereNull('due_date')->orWhere('due_date', '>=', today())),
                'partial' => $q->where('status', Invoice::PARTIALLY_PAID)->where(fn ($w) => $w->whereNull('due_date')->orWhere('due_date', '>=', today())),
                'paid' => $q->where('status', Invoice::PAID),
                'overdue' => $q->overdue(),
                default => $q,
            })
            // The document status — and the older links (open, overdue, a raw status) that pointed here before the tabs.
            ->when($request->string('status')->toString(), fn ($q, $s) => match ($s) {
                'active' => $q->whereNotIn('status', [Invoice::VOID, Invoice::CREDITED]),
                'open' => $q->open(),
                'overdue' => $q->overdue(),
                default => $q->where('status', $s),
            })
            ->when($request->string('type')->toString(), fn ($q, $t) => $q->where('type', $t))
            ->when($request->string('q')->toString(), fn ($q, $term) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$term}%")->orWhere('customer_name', 'ilike', "%{$term}%")->when(preg_replace('/\D/', '', $term), fn ($x, $digits) => $x->orWhere('customer_phone', 'like', "%{$digits}%"))))
            // The period (PeriodTabs) is on the issue date; the due date has its own choices (and older links' dates).
            ->tap(fn ($q) => $period->apply($q, 'issue_date'))
            ->when($request->string('due')->toString(), fn ($q, $due) => match ($due) {
                'week' => $q->whereBetween('due_date', [today(), today()->addDays(7)]),
                'month' => $q->whereBetween('due_date', [today()->startOfMonth(), today()->endOfMonth()]),
                'past' => $q->where('due_date', '<', today()),
                default => $q,
            })
            ->when($request->date('due_from'), fn ($q, $d) => $q->where('due_date', '>=', $d))
            ->when($request->date('due_to'), fn ($q, $d) => $q->where('due_date', '<=', $d))
            ->latest('id')->paginate($perPage)->withQueryString()
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
            'filters' => $request->only('state', 'status', 'type', 'q', 'due', 'due_from', 'due_to', 'per_page'),
            'period' => $period->toArray(),
            'draftCount' => $request->user()->can('invoices.manage') ? BranchContext::scope(Invoice::drafts())->count() : 0,
            'states' => self::STATES,
            'statuses' => self::DOCUMENT_STATUSES,
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
            'state' => self::state($i),
        ];
    }

    /** One word for the list: the document's own status when voided or credited, else how much is paid — late first. */
    public static function state(Invoice $i): string
    {
        return match (true) {
            in_array($i->status, [Invoice::VOID, Invoice::CREDITED, Invoice::DRAFT], true) => $i->status,
            $i->status === Invoice::PAID => 'paid',
            $i->isOverdue() => 'overdue',
            $i->status === Invoice::PARTIALLY_PAID => 'partial',
            default => 'unpaid',
        };
    }

    /** The invoice editor, empty. */
    public function create(Request $request): Response
    {
        return Inertia::render('Finance/Invoices/Create', $this->editor($request));
    }

    /** A saved draft, reopened in the editor. */
    public function edit(Request $request, int $draft): Response
    {
        $invoice = $this->draftOf($request, $draft)->load(['items.itemable', 'billable', 'attachments']);
        $member = $invoice->billable instanceof Member ? $invoice->billable : null;

        return Inertia::render('Finance/Invoices/Create', $this->editor($request) + [
            'draft' => [
                'id' => $invoice->id,
                'customer_mode' => $member ? 'member' : ($invoice->customer_phone || $invoice->customer_email ? 'new' : 'walk_in'),
                'member' => $member ? ['id' => $member->id, 'label' => $member->full_name.' · '.$member->member_no] : null,
                'customer_name' => $member ? '' : ($invoice->customer_name === 'عميل نقدي' ? '' : $invoice->customer_name),
                'customer_phone' => $member ? '' : (string) $invoice->customer_phone,
                'customer_email' => $member ? '' : (string) $invoice->customer_email,
                'issue_date' => $invoice->issue_date->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'payment_terms_days' => $invoice->payment_terms_days,
                'notes' => (string) $invoice->notes,
                'internal_notes' => (string) $invoice->internal_notes,
                'lines' => $invoice->items->map(fn ($i) => [
                    'product_id' => $i->itemable instanceof Product ? (string) $i->itemable->id : '',
                    'description' => $i->description,
                    'quantity' => Money::normalize($i->quantity),
                    'unit_price' => Money::normalize($i->unit_price),
                    'discount' => Money::normalize($i->discount),
                ])->all(),
                'attachments' => $invoice->attachments->map(fn ($a) => $a->only('id', 'name', 'size') + ['url' => route('club.invoices.attachments.show', $a)])->all(),
                'updated_at' => $invoice->updated_at?->format('Y-m-d H:i'),
            ],
        ]);
    }

    /** From the editor: saved as a draft, or issued (with the money taken now, if any). */
    public function store(Request $request, ManualInvoices $invoices): RedirectResponse
    {
        return $this->save($request, $invoices, null);
    }

    public function update(Request $request, ManualInvoices $invoices, int $draft): RedirectResponse
    {
        return $this->save($request, $invoices, $this->draftOf($request, $draft));
    }

    public function discard(Request $request, ManualInvoices $invoices, int $draft): RedirectResponse
    {
        $invoices->discard($this->draftOf($request, $draft));

        return redirect()->route('club.invoices.index', ['state' => 'draft'])->with('success', 'حُذفت المسودة.');
    }

    /**
     * The invoice's paper as the form stands: the engine's unsaved invoice (the same arithmetic issuing will save) drawn
     * by the invoice paper's own builder and view. Nothing is numbered or saved; an unfinished line is left out.
     */
    public function preview(Request $request, ManualInvoices $invoices): JsonResponse
    {
        $data = $request->validate($this->rules(preview: true));
        $data['lines'] = collect($data['lines'] ?? [])
            ->filter(fn ($l) => filled($l['description'] ?? null) && (float) ($l['quantity'] ?? 0) > 0)
            ->map(fn ($l) => [
                'product_id' => $l['product_id'] ?? null,
                'description' => $l['description'],
                'quantity' => $l['quantity'],
                'unit_price' => $l['unit_price'] ?? 0,
                // A discount typed larger than its line would be refused on issue; the preview shows it at most whole.
                'discount' => min((float) ($l['discount'] ?? 0), (float) Money::mul($l['unit_price'] ?? 0, (float) $l['quantity'])),
            ])->values()->all();
        if ($data['lines'] === []) {
            return response()->json(['html' => null, 'size' => null, 'totals' => null]);
        }
        $data += ['customer_mode' => 'walk_in', 'issue_date' => today()->toDateString()];
        if (($data['customer_mode'] ?? null) === 'member' && empty($data['member_id'])) {
            $data['customer_mode'] = 'walk_in';
        }

        $draft = $invoices->preview($data);
        $draft->number = 'مسودة';
        $draft->setRelation('payments', new EloquentCollection)->setRelation('branch', null)->setRelation('creator', $request->user());
        $paper = Papers::for('invoice', $draft);

        return response()->json([
            'html' => $paper->html(),
            'size' => $paper->size,
            'totals' => $draft->only('subtotal', 'discount_total', 'tax_rate', 'tax_total', 'total'),
        ]);
    }

    /** A file kept with an invoice (or a draft), for whoever may read the invoice in its branch. */
    public function attachment(Request $request, InvoiceAttachment $attachment): StreamedResponse
    {
        $invoice = Invoice::withoutGlobalScope('issued')->findOrFail($attachment->invoice_id);
        abort_unless(app(BranchContext::class)->allows($request->user(), $invoice->owningBranchId()), 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->name);
    }

    private function save(Request $request, ManualInvoices $invoices, ?Invoice $draft): RedirectResponse
    {
        // An older form (a member or a name, issued today) still issues: who is billed and the date follow from it.
        $request->mergeIfMissing(['customer_mode' => $request->filled('member_id') ? 'member' : 'walk_in', 'issue_date' => today()->toDateString(), 'issue' => $draft === null && ! $request->has('customer_mode')]);
        $issue = $request->boolean('issue');
        $data = $request->validate($this->rules(issue: $issue));
        $files = $request->file('attachments', []);
        $remove = array_map('intval', $data['remove_attachments'] ?? []);

        if (! $issue) {
            $saved = $invoices->saveDraft($data, $files, $draft, $remove);

            return redirect()->route('club.invoices.drafts.edit', $saved->id)->with('success', 'حُفظت المسودة. لا رقم لها ولا قيد حتى تُصدر.');
        }

        $method = $data['payment_method'] ?? 'credit';
        abort_if($method !== 'credit' && ! $request->user()->can('payments.record'), 403);
        $invoice = $invoices->issue($data, [
            'method' => $method,
            'amount' => $data['paid_amount'] ?? null,
            'paid_at' => $data['payment_date'] ?? null,
            'reference' => $data['payment_reference'] ?? null,
            'proof' => $request->file('payment_proof'),
        ], $files, $draft, $remove);

        return redirect()->route('club.invoices.show', $invoice)->with('success', 'تم إصدار الفاتورة '.$invoice->number);
    }

    /** @return array<string, mixed> */
    private function rules(bool $issue = false, bool $preview = false): array
    {
        $file = ['file', 'mimes:jpg,jpeg,png,pdf,webp,heic', 'max:'.config('gym.max_document_kb', 10240)];

        return [
            'customer_mode' => [$preview ? 'nullable' : 'required', 'in:member,new,walk_in'],
            'member_id' => [$preview ? 'nullable' : 'required_if:customer_mode,member', 'nullable', TenantRule::exists('gym_members')],
            'customer_name' => [$preview ? 'nullable' : 'required_if:customer_mode,new', 'nullable', 'string', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'customer_email' => ['nullable', 'email', 'max:160'],
            'issue_date' => [$preview ? 'nullable' : 'required', 'date', ...($issue ? ['before_or_equal:today'] : [])],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:500'],
            'internal_notes' => ['nullable', 'string', 'max:500'],
            'lines' => [$preview ? 'nullable' : 'required', 'array', ...($preview ? [] : ['min:1']), 'max:50'],
            'lines.*.product_id' => ['nullable', TenantRule::exists('products')],
            'lines.*.description' => [$preview ? 'nullable' : 'required', 'string', 'max:250'],
            'lines.*.quantity' => [$preview ? 'nullable' : 'required', 'numeric', $preview ? 'min:0' : 'min:0.001', 'max:10000'],
            'lines.*.unit_price' => [$preview ? 'nullable' : 'required', 'numeric', 'min:0', 'max:99999'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            ...($preview ? [] : [
                'attachments' => ['nullable', 'array', 'max:6'],
                'attachments.*' => $file,
                'remove_attachments' => ['nullable', 'array'],
                'remove_attachments.*' => ['integer'],
                'payment_method' => ['nullable', 'in:'.implode(',', ManualInvoices::METHODS)],
                'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:999999'],
                'payment_date' => ['nullable', 'date', 'before_or_equal:today'],
                'payment_reference' => ['nullable', 'string', 'max:120'],
                'payment_proof' => ['nullable', ...$file],
            ]),
        ];
    }

    /** What the editor works with: the catalogue, the club's tax and terms, and what this user may do. */
    private function editor(Request $request): array
    {
        $user = $request->user();
        $tenant = app(TenantContext::class)->require();
        $branch = app(BranchContext::class)->assign();
        $stock = PermissionRegistry::moduleEnabled('sales') ? app(StockService::class)->quantities($branch) : [];
        $products = PermissionRegistry::moduleEnabled('sales')
            ? Product::where('is_active', true)->orderBy('name')->limit(501)->get(['id', 'name', 'sku', 'barcode', 'price', 'track_stock'])
            : collect();

        return [
            'products' => $products->take(500)->map(fn (Product $p) => $p->only('id', 'name', 'sku', 'barcode', 'price', 'track_stock') + ['stock' => $p->track_stock ? (float) ($stock[$p->id] ?? 0) : null])->values(),
            'catalog_truncated' => $products->count() > 500,
            'tax_rate' => (float) $tenant->setting('vat_rate', 0),
            'due_days' => (int) $tenant->setting('invoice_due_days', 0),
            'today' => today()->toDateString(),
            'methods' => $user->can('payments.record') ? ManualInvoices::METHODS : ['credit'],
            'may' => ['issue' => $user->can('invoices.manage'), 'pay' => $user->can('payments.record'), 'design' => $user->can('papers.manage')],
        ];
    }

    /** A draft of this club, in the user's branch (a draft is not an invoice: its own lookup, past the drafts scope). */
    private function draftOf(Request $request, int $id): Invoice
    {
        $draft = Invoice::drafts()->findOrFail($id);
        abort_unless(app(BranchContext::class)->allows($request->user(), $draft->owningBranchId()), 404);

        return $draft;
    }

    public function show(Request $request, Invoice $invoice, OnlinePaymentService $online): Response
    {
        $invoice->load(['items', 'payments.receiver:id,name', 'creator:id,name', 'creditNotes.creator:id,name', 'attachments']);

        return Inertia::render('Finance/Invoices/Show', [
            'invoice' => self::row($invoice) + [
                'notes' => $invoice->notes, 'void_reason' => $invoice->void_reason, 'creator' => $invoice->creator?->name,
                // The team's own: a note never printed, and the files kept with the invoice.
                'internal_notes' => $invoice->internal_notes, 'payment_terms_days' => $invoice->payment_terms_days,
                'attachments' => $invoice->attachments->map(fn ($a) => $a->only('id', 'name', 'size') + ['url' => route('club.invoices.attachments.show', $a)])->all(),
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
