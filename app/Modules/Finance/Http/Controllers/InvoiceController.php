<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Core\Auth\PermissionRegistry;
use App\Core\Papers\Papers;
use App\Core\Papers\PaperTypes;
use App\Core\Support\Money;
use App\Core\Support\Period;
use App\Core\Support\SubmittedOnce;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Rules\OpenPeriod;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceAttachment;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\ManualInvoices;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductAddon;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\ProductAddons;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Messaging\Services\InvoiceMessage;
use App\Modules\Messaging\Services\MemberNotifier;
use App\Modules\Messaging\Services\WhatsAppAutomation;
use App\Modules\Messaging\Services\WhatsAppService;
use App\Modules\Payments\Http\Controllers\PaymentLinkController;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Models\PaymentReview;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Payments\Services\PaymentLinks;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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

    /** The editor's payment terms, in days (the due date follows the one chosen until it is typed by hand). */
    public const TERMS = [0, 7, 15, 30, 45, 60, 90];

    /** The invoice editor, empty — with its paper already drawn: the club, the title, the dates, nothing on it yet. */
    public function create(Request $request, ManualInvoices $invoices): Response
    {
        $editor = $this->editor($request);

        return Inertia::render('Finance/Invoices/Create', $editor + ['paper' => $this->paper($request, $invoices, [
            'customer_mode' => 'member',
            'issue_date' => $editor['today'],
            'due_date' => today()->addDays($editor['due_days'])->toDateString(),
            'payment_terms_days' => in_array($editor['due_days'], self::TERMS, true) ? $editor['due_days'] : null,
            'paper_lang' => $editor['paper_lang'],
            'lines' => [],
        ])]);
    }

    /**
     * The services the invoice editor's catalogue offers this user, each with what the picker shows: membership plans
     * (memberships.sell) and personal-training packages (pt.sell) on sale, sold to a member and activated once the
     * invoice is paid in full; programs on sale with their price (none: shown, billed only by whoever manages them).
     *
     * @return array{plans: list<array<string, mixed>>, pt: list<array<string, mixed>>, programs: list<array<string, mixed>>, price_programs: bool}
     */
    private function services($user): array
    {
        $kinds = ['class' => 'حصة جماعية', 'program' => 'برنامج تدريبي', 'pt' => 'تدريب شخصي'];

        return [
            'plans' => $user->can('memberships.sell') ? MembershipPlan::where('is_active', true)->orderBy('sort')->orderBy('name')->get()
                ->map(fn (MembershipPlan $p) => ['id' => $p->id, 'name' => $p->name, 'price' => Money::normalize($p->price),
                    'detail' => $p->durationLabel().($p->sessions_limit ? ' · '.$p->sessions_limit.' حصة' : ''),
                    'note' => Money::isZero($p->signup_fee) ? null : 'رسوم تسجيل '.Money::normalize($p->signup_fee).' لأول اشتراك'])->values()->all() : [],
            'pt' => $user->can('pt.sell') ? PtPackage::where('is_active', true)->orderBy('name')->get()
                ->map(fn (PtPackage $p) => ['id' => $p->id, 'name' => $p->name, 'price' => Money::normalize($p->price),
                    'detail' => $p->sessions_count.' جلسات · '.$p->session_minutes.' دقيقة · صالحة '.$p->validity_days.' يومًا', 'note' => null])->values()->all() : [],
            'programs' => Program::where('is_active', true)->orderBy('sort')->orderBy('name')->get()
                ->map(fn (Program $p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price !== null ? Money::normalize($p->price) : null,
                    'detail' => ($kinds[$p->category] ?? 'برنامج').' · '.$p->duration_minutes.' دقيقة', 'note' => null])->values()->all(),
            'price_programs' => (bool) $user->can('classes.manage'),
        ];
    }

    /** A saved draft, reopened in the editor with its paper as it was left. */
    public function edit(Request $request, ManualInvoices $invoices, int $draft): Response
    {
        $invoice = $this->draftOf($request, $draft)->load(['items' => fn ($q) => $q->orderBy('id'), 'items.itemable', 'billable', 'attachments']);
        $member = $invoice->billable instanceof Member ? $invoice->billable : null;
        $editor = $this->editor($request);

        $form = [
            'id' => $invoice->id,
            // A new customer's draft selling a plan or a package stays a new customer's (the invoice registers them).
            'customer_mode' => $member ? 'member' : ($invoice->customer_phone || $invoice->customer_email || ($invoice->customer_name !== 'عميل نقدي' && $invoice->items->contains(fn ($i) => $i->itemable instanceof MembershipPlan || $i->itemable instanceof PtPackage)) ? 'new' : 'walk_in'),
            'member' => $member ? ['id' => $member->id, 'label' => $member->full_name.' · '.$member->member_no] : null,
            'customer_name' => $member ? '' : ($invoice->customer_name === 'عميل نقدي' ? '' : $invoice->customer_name),
            'customer_phone' => $member ? '' : (string) $invoice->customer_phone,
            'customer_email' => $member ? '' : (string) $invoice->customer_email,
            'issue_date' => $invoice->issue_date->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'payment_terms_days' => $invoice->payment_terms_days,
            'notes' => (string) $invoice->notes,
            'internal_notes' => (string) $invoice->internal_notes,
            'paper_lang' => $invoice->paper_lang,
            // Each product line with the add-ons sold with it (how many per piece), as the editor chose them; each
            // service as the catalogue row it was taken from (a plan's signup fee is its sale's, not a line of its own).
            'lines' => $invoice->items->whereNull('parent_id')
                ->reject(fn ($i) => $i->itemable instanceof MembershipPlan && $invoice->items->first(fn ($o) => $o->itemable instanceof MembershipPlan)?->id !== $i->id)
                ->values()->map(fn ($i) => [
                    'product_id' => $i->itemable instanceof Product ? (string) $i->itemable->id : '',
                    'item_type' => match (true) {
                        $i->itemable instanceof MembershipPlan => 'plan',
                        $i->itemable instanceof PtPackage => 'pt',
                        $i->itemable instanceof Program => 'program',
                        default => '',
                    },
                    'item_id' => $i->itemable instanceof MembershipPlan || $i->itemable instanceof PtPackage || $i->itemable instanceof Program ? (string) $i->itemable->id : '',
                    'description' => $i->description,
                    'quantity' => Money::normalize($i->quantity),
                    'unit_price' => Money::normalize($i->unit_price),
                    'discount' => Money::normalize($i->discount),
                    'addons' => $invoice->items->where('parent_id', $i->id)->values()->map(fn ($a) => [
                        'id' => (int) $a->itemable_id,
                        'qty' => max(1, (int) round((float) $a->quantity / max((float) $i->quantity, 0.001))),
                        'name' => $a->itemable instanceof ProductAddon ? $a->itemable->name : ltrim(explode(' — ', $a->description)[0], '+ '),
                        'price' => Money::normalize($a->unit_price),
                    ])->all(),
                ])->all(),
            'attachments' => $invoice->attachments->map(fn ($a) => $a->only('id', 'name', 'size') + ['url' => route('club.invoices.attachments.show', $a)])->all(),
            'updated_at' => $invoice->updated_at?->format('Y-m-d H:i'),
        ];

        // The paper reads the form as the editor sends it: each add-on as its id and how many per piece.
        $lines = collect($form['lines'])->map(fn ($l) => ['addons' => array_map(fn ($a) => ['addon_id' => $a['id'], 'quantity' => $a['qty']], $l['addons'])] + $l)->all();

        return Inertia::render('Finance/Invoices/Create', $editor + [
            'draft' => $form,
            'paper' => $this->paper($request, $invoices, ['member_id' => $member?->id, 'paper_lang' => $form['paper_lang'] ?? $editor['paper_lang'], 'lines' => $lines] + $form),
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
     * by the invoice paper's own builder and view, in the face chosen beside it (Arabic or English). Nothing is numbered
     * or saved; an unfinished line is left out. Before the first line, and before a customer is chosen, the whole sheet
     * is still drawn — the club, the title, the dates, an empty table and totals at zero — so the paper is there from
     * the moment the editor opens. Saving and issuing keep their own rules: an invoice is never issued without a line.
     */
    public function preview(Request $request, ManualInvoices $invoices): JsonResponse
    {
        return response()->json($this->paper($request, $invoices, $request->validate($this->rules(preview: true), self::MESSAGES)));
    }

    /**
     * The editor's paper and totals for a form as it stands (see preview()).
     *
     * @param  array<string, mixed>  $data
     * @return array{html: string, size: string, totals: array<string, mixed>}
     */
    private function paper(Request $request, ManualInvoices $invoices, array $data): array
    {
        $data['lines'] = collect($data['lines'] ?? [])
            ->filter(fn ($l) => filled($l['description'] ?? null) && (float) ($l['quantity'] ?? 0) > 0)
            ->map(fn ($l) => [
                'product_id' => $l['product_id'] ?? null,
                // A catalogue service is drawn as issuing bills it (its price, a plan's period and signup fee).
                'item_type' => $l['item_type'] ?? null,
                'item_id' => $l['item_id'] ?? null,
                'description' => $l['description'],
                'quantity' => $l['quantity'],
                'unit_price' => $l['unit_price'] ?? 0,
                // A discount typed larger than its line would be refused on issue; the preview shows it at most whole.
                'discount' => min((float) ($l['discount'] ?? 0), (float) Money::mul($l['unit_price'] ?? 0, (float) $l['quantity'])),
                'addons' => $l['addons'] ?? [],
            ])->values()->all();
        $data['customer_mode'] ??= 'walk_in';
        $data['issue_date'] ??= today()->toDateString();
        // No customer yet (a member not picked, a new customer not named): the paper leaves the name to come.
        $unnamed = ($data['customer_mode'] === 'member' && empty($data['member_id'])) || ($data['customer_mode'] === 'new' && blank($data['customer_name'] ?? null));
        if ($data['customer_mode'] === 'member' && empty($data['member_id'])) {
            $data['customer_mode'] = 'walk_in';
        }

        $draft = $invoices->preview($data);
        if ($unnamed) {
            $draft->customer_name = '';
        }
        $draft->setRelation('payments', new EloquentCollection)->setRelation('branch', null)->setRelation('creator', $request->user());
        $paper = Papers::for('invoice', $draft);

        return [
            'html' => $paper->html(),
            'size' => $paper->size,
            'totals' => $draft->only('subtotal', 'discount_total', 'tax_rate', 'tax_total', 'total'),
        ];
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
        $data = $request->validate($this->rules(issue: $issue), self::MESSAGES);
        // A discount larger than its line is named on that line, not refused for the whole invoice. A service's line is
        // priced by the server, which names it there (ManualInvoices).
        foreach ($data['lines'] as $i => $line) {
            if (! in_array($line['item_type'] ?? null, ['plan', 'pt', 'program'], true) && Money::toMinor($line['discount'] ?? 0) > Money::toMinor(Money::mul($line['unit_price'], (float) $line['quantity']))) {
                throw ValidationException::withMessages(["lines.$i.discount" => 'الخصم أكبر من قيمة البند.']);
            }
        }
        $files = $request->file('attachments', []);
        $remove = array_map('intval', $data['remove_attachments'] ?? []);
        $method = $data['payment_method'] ?? 'credit';
        abort_if($issue && $method !== 'credit' && ! $request->user()->can('payments.record'), 403);
        $withLink = $issue && (bool) ($data['with_link'] ?? false);
        abort_if($withLink && ! $request->user()->can('payments.links'), 403);
        // Owner's decision (2026-11-21), as the reference: issuing with a payment link is for an invoice on credit
        // («آجل — لا يُقبض الآن»), the link asking what it owes; money taken now is recorded on its own, with no link.
        if ($withLink && $method !== 'credit') {
            throw ValidationException::withMessages(['payment_method' => 'رابط الدفع لفاتورةٍ آجلة — اختر «آجل».']);
        }

        // The form sent twice (a double click, a retry) saves or issues once: the second gets the first's result.
        // With its payment link: one transaction, so a link refused (nothing left to pay, online payments off…) issues nothing.
        [$kind, $id, $number] = SubmittedOnce::run($data['client_key'] ?? null, 'invoice-editor:'.($issue ? ($withLink ? 'issue-link' : 'issue') : 'draft').':'.($draft->id ?? 'new'), function () use ($invoices, $data, $files, $draft, $remove, $issue, $method, $request, $withLink) {
            if (! $issue) {
                return ['draft', $invoices->saveDraft($data, $files, $draft, $remove)->id, null];
            }
            $invoice = $invoices->issue($data, [
                'method' => $method,
                'amount' => $data['paid_amount'] ?? null,
                'paid_at' => $data['payment_date'] ?? null,
                'reference' => $data['payment_reference'] ?? null,
                'proof' => $request->file('payment_proof'),
            ], $files, $draft, $remove);
            if ($withLink) {
                app(PaymentLinks::class)->open($invoice, (int) $data['link_days']);
            }

            return ['invoice', $invoice->id, $invoice->number];
        });

        return $kind === 'draft'
            ? redirect()->route('club.invoices.drafts.edit', $id)->with('success', 'حُفظت المسودة. لا رقم لها ولا قيد حتى تُصدر.')
            : redirect()->route('club.invoices.show', $id)->with('success', 'تم إصدار الفاتورة '.$number.($withLink ? ' وأُنشئ رابط الدفع: انسخه أو أرسله بواتساب.' : ''));
    }

    /** The editor's figures, named as its fields are. */
    private const MESSAGES = [
        'lines.*.quantity.decimal' => 'الكمية بثلاث منازل عشرية على الأكثر.',
        'lines.*.unit_price.decimal' => 'السعر بثلاث منازل عشرية على الأكثر.',
        'lines.*.discount.decimal' => 'الخصم بثلاث منازل عشرية على الأكثر.',
        'paid_amount.decimal' => 'المبلغ المدفوع بثلاث منازل عشرية على الأكثر.',
        'payment_date.after_or_equal' => 'تاريخ الدفع لا يكون قبل تاريخ الفاتورة.',
        'due_date.after_or_equal' => 'تاريخ الاستحقاق لا يكون قبل تاريخ الفاتورة.',
    ];

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
            // «إشعارات العضو الجديد»: read only when issuing registers the customer as a member (ManualInvoices).
            'new_member' => ['nullable', 'array'],
            'new_member.whatsapp_opt_in' => ['boolean'],
            'new_member.message_language' => ['nullable', 'in:'.implode(',', array_keys(Member::MESSAGE_LANGUAGES))],
            'new_member.send_invoice' => ['boolean'],
            'new_member.welcome' => ['boolean'],
            'issue_date' => [$preview ? 'nullable' : 'required', 'date', ...($issue ? ['before_or_equal:today', new OpenPeriod] : [])],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:500'],
            'internal_notes' => ['nullable', 'string', 'max:500'],
            // The face of its paper: «ar» Arabic first, «en» English first (left to right); none follows the template.
            'paper_lang' => ['nullable', 'in:ar,en'],
            'lines' => [$preview ? 'nullable' : 'required', 'array', ...($preview ? [] : ['min:1']), 'max:50'],
            'lines.*.product_id' => ['nullable', TenantRule::exists('products')],
            // A service from the catalogue (plan, personal training, program): which one; what it is and its price are
            // the server's (ManualInvoices), checked against the club's own catalogue.
            'lines.*.item_type' => ['nullable', 'in:'.implode(',', ManualInvoices::ITEM_TYPES)],
            'lines.*.item_id' => ['nullable', 'required_if:lines.*.item_type,plan,pt,program', 'integer', 'min:1'],
            'lines.*.description' => [$preview ? 'nullable' : 'required', 'string', 'max:250'],
            // Three decimals at most, as money is kept: the editor's line totals are then the server's to the baisa.
            'lines.*.quantity' => [$preview ? 'nullable' : 'required', 'numeric', 'decimal:0,3', $preview ? 'min:0' : 'min:0.001', 'max:10000'],
            'lines.*.unit_price' => [$preview ? 'nullable' : 'required', 'numeric', 'decimal:0,3', 'min:0', 'max:99999'],
            'lines.*.discount' => ['nullable', 'numeric', 'decimal:0,3', 'min:0'],
            // A catalogue product's add-ons: which, and how many per piece. Their prices and rules are the server's.
            'lines.*.addons' => ['nullable', 'array', 'max:20'],
            'lines.*.addons.*.addon_id' => ['required', 'integer', TenantRule::exists('product_addons')],
            'lines.*.addons.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            ...($preview ? [] : [
                'client_key' => SubmittedOnce::RULE,
                // «إصدار الفاتورة وإنشاء رابط دفع»: issued and its payment link made together, or neither.
                'with_link' => ['nullable', 'boolean'],
                'link_days' => ['nullable', 'required_if_accepted:with_link', 'in:'.implode(',', PaymentLink::VALID_DAYS)],
                'attachments' => ['nullable', 'array', 'max:6'],
                'attachments.*' => $file,
                'remove_attachments' => ['nullable', 'array'],
                'remove_attachments.*' => ['integer'],
                'payment_method' => ['nullable', 'in:'.implode(',', ManualInvoices::METHODS)],
                'paid_amount' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:999999'],
                // Not before the invoice: the money would reach the books before the debt it pays.
                'payment_date' => ['nullable', 'date', 'after_or_equal:issue_date', 'before_or_equal:today', new OpenPeriod],
                'payment_reference' => ['nullable', 'string', 'max:120'],
                'payment_proof' => ['nullable', ...$file],
            ]),
        ];
    }

    /**
     * What the editor works with: the catalogue and the add-ons that go with its products (those offered at the point
     * of sale, with what the branch's shelf allows), the club's tax and terms, and what this user may do.
     */
    private function editor(Request $request): array
    {
        $user = $request->user();
        $tenant = app(TenantContext::class)->require();
        $branch = app(BranchContext::class)->assign();
        $lang = (string) PaperTypes::settings($tenant, 'invoice')['lang'];
        $selling = PermissionRegistry::moduleEnabled('sales');
        $stock = $selling ? app(StockService::class)->quantities($branch) : [];
        $products = $selling
            ? Product::where('is_active', true)->orderBy('name')->limit(501)->get(['id', 'name', 'sku', 'barcode', 'price', 'track_stock', 'product_category_id'])
            : collect();
        $addons = $selling
            ? app(ProductAddons::class)->offered(ManualInvoices::ADDON_CHANNEL)->load(['products:products.id,products.name', 'categories:product_categories.id,product_categories.name'])
            : collect();

        return [
            'products' => $products->take(500)->map(fn (Product $p) => $p->only('id', 'name', 'sku', 'barcode', 'price', 'track_stock') + [
                'stock' => $p->track_stock ? (float) ($stock[$p->id] ?? 0) : null,
                'addons' => $addons->filter(fn (ProductAddon $a) => $a->fits($p))->pluck('id')->values(),
            ])->values(),
            'catalog_truncated' => $products->count() > 500,
            // The club's services on the same invoice (owner's decision, 2026-11-21), for whoever may sell each: what
            // the picker shows — name, price, period or sessions. The server prices them again when saving.
            'services' => $this->services($user),
            'addons' => $addons->map(fn (ProductAddon $a) => $a->only('id', 'name', 'description', 'price') + [
                'max' => max(1, $a->max_quantity),
                'available' => app(ProductAddons::class)->available($a, $stock),
                // How many of it the branch's shelf allows (none: it uses no counted stock).
                'left' => $a->stockProduct?->track_stock ? (int) floor(round(($stock[$a->stockProduct->id] ?? 0) / max((float) $a->stock_usage, 0.001), 3)) : null,
                'scope' => match ($a->applies_to) {
                    ProductAddon::ALL => 'مع كل المنتجات',
                    ProductAddon::CATEGORIES => 'مع '.$a->categories->pluck('name')->join('، '),
                    default => 'مع '.$a->products->pluck('name')->join('، '),
                },
            ])->values(),
            'tax_rate' => (float) $tenant->setting('vat_rate', 0),
            'due_days' => (int) $tenant->setting('invoice_due_days', 0),
            // The paper's face a new invoice starts with: the invoice template's.
            'paper_lang' => str_starts_with($lang, 'en') ? 'en' : 'ar',
            'today' => today()->toDateString(),
            // «إشعارات العضو الجديد»: what can go out for a member the invoice registers (WhatsApp on for the club, the
            // welcome not turned off in its automation).
            'whatsapp' => ['on' => app(WhatsAppService::class)->enabled(), 'welcome' => WhatsAppAutomation::on($tenant, 'member_welcome')],
            'terms' => self::TERMS,
            'methods' => $user->can('payments.record') ? ManualInvoices::METHODS : ['credit'],
            'may' => ['issue' => $user->can('invoices.manage'), 'pay' => $user->can('payments.record'), 'design' => $user->can('papers.manage'),
                // A new customer sold a plan or a package is registered as a member on issue: by whoever may register them.
                'register' => $user->can('members.manage'),
                // «إصدار الفاتورة وإنشاء رابط دفع»: with the links permission, while online payments are on.
                'link' => $user->can('payments.links') && app(OnlinePaymentService::class)->available()],
            // Why that button is not offered, shown in its place rather than leaving it out without a word.
            'link_reason' => ! $user->can('payments.links')
                ? 'إنشاء رابط دفع مع الفاتورة يتطلب صلاحية «روابط الدفع». اطلبها من مدير النادي.'
                : (($why = app(OnlinePaymentService::class)->unavailableReason()) !== null ? 'لا يُنشأ رابط دفع مع الفاتورة الآن: '.$why : null),
            'link_days' => PaymentLink::VALID_DAYS,
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
        $invoice->load(['items', 'payments.receiver:id,name', 'payments.account:id,name', 'creator:id,name', 'creditNotes.creator:id,name', 'attachments']);
        $credits = $invoice->payments->filter(fn (Payment $p) => $p->status === Payment::COMPLETED && ! Money::isZero($p->credit_amount));

        return Inertia::render('Finance/Invoices/Show', [
            'invoice' => self::row($invoice) + [
                'notes' => $invoice->notes, 'void_reason' => $invoice->void_reason, 'creator' => $invoice->creator?->name,
                // The team's own: a note never printed, and the files kept with the invoice.
                'internal_notes' => $invoice->internal_notes, 'payment_terms_days' => $invoice->payment_terms_days,
                'attachments' => $invoice->attachments->map(fn ($a) => $a->only('id', 'name', 'size') + ['url' => route('club.invoices.attachments.show', $a)])->all(),
                'paid_at' => $invoice->paid_at?->format('Y-m-d H:i'), 'tax_rate' => $invoice->tax_rate,
                // An add-on line is marked, under the product line it was sold with when the editor recorded it.
                'items' => $invoice->items->map(fn ($i) => $i->only('id', 'parent_id', 'description', 'quantity', 'unit_price', 'discount', 'line_total') + ['addon' => $i->itemable_type === 'product_addon']),
                'payments' => $invoice->payments->map(fn ($p) => $p->only('id', 'receipt_number', 'kind', 'method', 'status', 'amount', 'reference', 'notes', 'rejection_reason', 'cancel_reason', 'from_credit') + [
                    'paid_at' => $p->paid_at?->format('Y-m-d H:i'), 'receiver' => $p->receiver?->name, 'has_proof' => $p->proof_path !== null,
                    'account' => $p->account?->name, 'gateway' => $p->gateway !== null,
                    'credit_left' => $credits->contains('id', $p->id) ? $p->creditLeft() : null,
                ]),
                // Credit of the customer's other payments applied to this invoice.
                'allocations' => PaymentAllocation::with('payment:id,receipt_number')->where('invoice_id', $invoice->id)->get()
                    ->map(fn ($a) => ['id' => $a->id, 'amount' => $a->amount, 'from' => $a->payment?->receipt_number, 'date' => $a->created_at->format('Y-m-d H:i')]),
                'credit_notes' => $invoice->creditNotes->map(fn ($n) => $n->only('id', 'number', 'amount', 'refunded_total', 'reason') + [
                    'refundable' => $n->refundable(), 'issued_at' => $n->created_at->format('Y-m-d H:i'), 'creator' => $n->creator?->name,
                ]),
                'pay_link' => $invoice->isOpen() ? app(MemberNotifier::class)->paymentLink($invoice) : null,
                'public_link' => app(MemberNotifier::class)->invoiceLink($invoice),
            ],
            'intents' => PaymentIntent::where('invoice_id', $invoice->id)->latest()->limit(10)->get()->map(fn ($i) => $i->only('id', 'reference', 'amount', 'channel', 'failure_reason') + ['status' => $i->displayStatus(), 'created_at' => $i->created_at->format('Y-m-d H:i')]),
            // Paymob money confirmed for this invoice that did not match it: what staff need to look it up at Paymob.
            'paymentReviews' => PaymentReview::with('intent:id,reference', 'closer:id,name')->where('invoice_id', $invoice->id)->latest('id')->limit(20)->get()->map->present(),
            'onlinePayments' => $online->available(),
            // Where a payment lands when chosen (empty: the method's account in the finance settings).
            'moneyAccounts' => Account::money()->postable()->orderBy('code')->get(['id', 'name', 'kind']),
            // Open invoices of the same customer this invoice's credit can be applied to.
            // Confirmed gateway money on this invoice while it is open goes to this invoice only (PaymentService::allocate).
            'creditTargets' => $invoice->isOpen() && $credits->contains(fn (Payment $p) => $p->gateway === 'paymob')
                ? [['id' => $invoice->id, 'number' => $invoice->number, 'balance' => $invoice->balance()]]
                : ($credits->isNotEmpty() && $invoice->billable_id !== null
                    ? Invoice::where(['billable_type' => $invoice->billable_type, 'billable_id' => $invoice->billable_id])->whereIn('status', Invoice::OPEN_STATUSES)->whereKeyNot($invoice->id)
                        ->orderBy('id')->get()->map(fn (Invoice $i) => ['id' => $i->id, 'number' => $i->number, 'balance' => $i->balance()])->values()
                    : []),
            // Its payment link (v2): the live one with copy, WhatsApp and cancel, a new one when allowed, and every link it had.
            'paymentLink' => PaymentLinkController::forInvoice($invoice, app(PaymentLinks::class), $online),
            'isSale' => Sale::where('invoice_id', $invoice->id)->exists(),
            'whatsappEnabled' => app(WhatsAppService::class)->enabled(),
            // The invoice as it prints: the same paper as the print page, the PDF and the customer's link.
            'paper' => Papers::for('invoice', $invoice)->toArray(),
        ]);
    }

    /** The print page: the invoice paper with the browser's print dialog. */
    public function print(Request $request, Invoice $invoice): HttpResponse
    {
        return Papers::for('invoice', $invoice)->respond($request);
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

        // The invoice's own values, in the member's language (the approved Arabic template when hers is not approved);
        // refused for an invoice with no live payment link, as before (InvoiceMessage::refusal).
        $sent = app(InvoiceMessage::class)->sendByHand($member, $invoice, (string) ($member->message_language ?: 'ar'));

        return $sent['error'] !== null
            ? back()->with('error', 'لم تُرسل الفاتورة: '.$sent['error'])
            : back()->with('success', 'أُضيفت الفاتورة إلى قائمة الإرسال عبر واتساب.'.($sent['fallback'] ? ' '.$sent['fallback'] : ''));
    }
}
