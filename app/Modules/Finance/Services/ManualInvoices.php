<?php

namespace App\Modules\Finance\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Files\SafeFiles;
use App\Core\Support\Money;
use App\Core\Tenancy\BranchContext;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceAttachment;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Gym\Services\PtService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductAddon;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The invoice editor's two doors, over the invoice engine (InvoiceService) — the same arithmetic, tax and numbering as
 * every other invoice:
 *
 *   saveDraft  keeps the work as a draft: no number, nothing owed, no entry in the books, no stock taken, no payment.
 *   issue      issues it: numbered in sequence, owed, posted (by the ledger after commit); a product from the catalogue
 *              leaves the shelf of the invoice's branch, with whatever its add-ons use up; money taken now is recorded
 *              through PaymentService.
 *
 * A catalogue product's line may carry add-ons, as at the point of sale: each is billed on a line of its own under the
 * product's, priced from the database and checked as the point of sale checks it. A void puts back what they took.
 *
 * Issuing a saved draft issues a new invoice from it and removes the draft, its files moving to the invoice. Either
 * all of it happens or none (short stock, an amount larger than the invoice): one transaction.
 *
 * The catalogue's services go on the same one invoice (owner's decision, 2026-11-21), priced from the database, never
 * from the form: a membership plan and a personal-training package, for a member chosen, are made pending as their own
 * screens make them (MembershipService / PtService::pendingForInvoice) — no invoice of their own — and are activated by
 * ActivatePaidItems only once this invoice is paid in full; voiding it withdraws them. A program is billed at its price
 * (none set: only by whoever manages programs, at a price typed) and books nothing. None of them touches the stock.
 *
 * @phpstan-type Data array{customer_mode: string, member_id?: int|null, customer_name?: string|null, customer_phone?: string|null, customer_email?: string|null, issue_date: string, due_date?: string|null, payment_terms_days?: int|string|null, notes?: string|null, internal_notes?: string|null, paper_lang?: string|null, lines: list<array{product_id?: int|string|null, description: string, quantity: string|float, unit_price: string|float, discount?: string|float|null, addons?: list<array{addon_id: int|string, quantity: int|string}>}>}
 */
final class ManualInvoices
{
    /** How money taken on issue may be paid; «credit» (آجل) leaves the whole amount owed. */
    public const METHODS = ['credit', 'cash', 'card', 'bank_transfer'];

    /** Staff sell at the counter and on invoices alike: an invoice offers the add-ons offered at the point of sale. */
    public const ADDON_CHANNEL = 'pos';

    /** The catalogue's kinds of line, as the form names them (product: the catalogue product, with its add-ons). */
    public const ITEM_TYPES = ['product', 'plan', 'pt', 'program'];

    /** How a program is named on the invoice, by its kind. */
    private const PROGRAM_KINDS = ['class' => 'حصة جماعية', 'program' => 'برنامج تدريبي', 'pt' => 'تدريب شخصي'];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly StockService $stock,
        private readonly BranchContext $branches,
        private readonly AuditLogger $audit,
        private readonly MembershipService $memberships,
        private readonly PtService $pt,
    ) {}

    /**
     * @param  Data  $data
     * @param  list<UploadedFile>  $files
     * @param  list<int>  $removeFiles  attachments of the draft to drop
     */
    public function saveDraft(array $data, array $files = [], ?Invoice $draft = null, array $removeFiles = []): Invoice
    {
        return $this->keepingFilesOnlyIfSaved(fn () => DB::transaction(function () use ($data, $files, $draft, $removeFiles) {
            $built = $this->build($data, $draft);
            $items = $built->getRelation('items');
            $built->unsetRelation('items');
            $attributes = ['status' => Invoice::DRAFT, 'number' => null, 'public_token' => null] + $built->getAttributes();

            if ($draft) {
                $draft = Invoice::drafts()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
                $draft->fill(collect($attributes)->except(['created_by'])->all())->save();
                $draft->items()->delete();
            } else {
                $draft = (new Invoice)->fill($attributes + ['created_by' => Auth::guard('web')->id()]);
                $draft->save();
            }
            InvoiceService::keepItems($draft, $items);

            $this->dropFiles($draft, $removeFiles);
            $this->keepFiles($draft, $files);
            $this->audit->log('invoice.draft_saved', $draft, ['total' => $draft->total]);

            return $draft;
        }));
    }

    /**
     * @param  Data  $data
     * @param  array{method: string, amount?: string|float|null, paid_at?: string|null, reference?: string|null, proof?: UploadedFile|null}  $payment
     * @param  list<UploadedFile>  $files
     * @param  list<int>  $removeFiles
     */
    public function issue(array $data, array $payment, array $files = [], ?Invoice $draft = null, array $removeFiles = []): Invoice
    {
        return $this->keepingFilesOnlyIfSaved(fn () => DB::transaction(function () use ($data, $payment, $files, $draft, $removeFiles) {
            if ($draft) {
                $draft = Invoice::drafts()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            }
            [$member, $lines, $options, $name] = $this->parts($data, $draft);
            // The products whose stock this invoice moves, locked by id before its number is taken, as a till sale takes
            // them (products, then the invoice): otherwise the two deadlock, on the products or on the invoice counter.
            $this->stock->lockInOrder(collect($lines)->map(fn (array $line) => match (true) {
                ($line['itemable'] ?? null) instanceof Product => $line['itemable']->id,
                ($line['itemable'] ?? null) instanceof ProductAddon => $line['itemable']->stock_product_id,
                default => null,
            }));
            // The services: memberships and packages made pending for this invoice, their lines in place of the
            // catalogue's — after the shelf's locks, before the number (the member's lock, as their own sale takes it).
            [$lines, $made] = $this->services($member, $lines, $options['branch_id']);
            $options['type'] = self::typeOf($lines);
            $invoice = $this->invoices->issue($member, $lines, $options, $name);
            foreach ($made as $record) {
                $record instanceof Membership ? $this->memberships->issuedOn($record, $invoice) : $this->pt->issuedOn($record, $invoice);
            }

            // Products from the catalogue leave the shelf of the invoice's branch (none: the main branch), and so does
            // the stock their add-ons use up.
            foreach ($lines as $line) {
                $item = $line['itemable'] ?? null;
                if ($item instanceof Product && $item->track_stock) {
                    $this->stock->move($item, -(float) $line['quantity'], 'invoice', $invoice, branchId: $invoice->branch_id);
                } elseif ($item instanceof ProductAddon && $item->stockProduct?->track_stock) {
                    $used = round((float) $line['quantity'] * (float) $item->stock_usage, 3);
                    if ($used > 0) {
                        $this->stock->move($item->stockProduct, -$used, 'invoice', $invoice, 'إضافة «'.$item->name.'» مع «'.$lines[$line['parent']]['description'].'»', branchId: $invoice->branch_id);
                    }
                }
            }

            if ($draft) {
                InvoiceAttachment::where('invoice_id', $draft->id)->update(['invoice_id' => $invoice->id]);
                $this->audit->log('invoice.draft_issued', $invoice, ['draft_id' => $draft->id]);
                $draft->items()->delete();
                $draft->delete();
            }
            $this->dropFiles($invoice, $removeFiles);
            $this->keepFiles($invoice, $files);

            $method = $payment['method'] ?? 'credit';
            if ($method !== 'credit' && $invoice->isOpen()) {
                $amount = filled($payment['amount'] ?? null) ? Money::normalize($payment['amount']) : $invoice->balance();
                if (Money::cmp($amount, $invoice->balance()) > 0) {
                    throw new BusinessRuleException('invoice.paid_amount', 'المبلغ المدفوع أكبر من إجمالي الفاتورة ('.$invoice->balance().').', 'paid_amount');
                }
                if (Money::toMinor($amount) > 0) {
                    $proof = $payment['proof'] ?? null;
                    $this->payments->record($invoice, $amount, $method, [
                        'reference' => $payment['reference'] ?? null,
                        'paid_at' => filled($payment['paid_at'] ?? null) ? $payment['paid_at'] : now(),
                        'proof_path' => $proof instanceof UploadedFile ? $this->store($proof, 'tenants/'.$invoice->tenant_id.'/payment-proofs', 'payment_proof') : null,
                        'notes' => 'عند إصدار الفاتورة',
                    ]);
                }
            }

            return $invoice->refresh();
        }));
    }

    /** A draft thrown away: its lines and files with it. */
    public function discard(Invoice $draft): void
    {
        DB::transaction(function () use ($draft) {
            $draft = Invoice::drafts()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $this->dropFiles($draft, $draft->attachments()->pluck('id')->all());
            $draft->items()->delete();
            $draft->delete();
            $this->audit->log('invoice.draft_discarded', null, ['draft_id' => $draft->id, 'customer' => $draft->customer_name]);
        });
    }

    /**
     * The invoice as it would be issued, unsaved: the editor's live paper and its totals (before the first line too). An
     * add-on that saving would refuse is left out of it, as an unfinished line is.
     */
    public function preview(array $data): Invoice
    {
        [$member, $lines, $options, $name] = $this->parts($data, null, strict: false);

        return $this->invoices->preview($member, $lines, $options, $name);
    }

    /** @param  Data  $data */
    private function build(array $data, ?Invoice $draft): Invoice
    {
        [$member, $lines, $options, $name] = $this->parts($data, $draft);

        return $this->invoices->draft($member, $lines, $options, $name);
    }

    /**
     * The engine's arguments from the editor's form: who is billed (a member, a new customer typed in, or a walk-in),
     * the lines — a catalogue product priced as the form says and linked to the product, each of its add-ons on a line
     * of its own under it — and the options.
     *
     * @param  Data  $data
     * @return array{0: Member|null, 1: list<array<string, mixed>>, 2: array<string, mixed>, 3: string|null}
     */
    private function parts(array $data, ?Invoice $draft, bool $strict = true): array
    {
        $mode = $data['customer_mode'] ?? 'walk_in';
        $member = $mode === 'member' && filled($data['member_id'] ?? null) ? Member::find($data['member_id']) : null;
        if ($mode === 'member' && ! $member) {
            throw new BusinessRuleException('invoice.member', 'اختر العضو.', 'member_id');
        }

        $rows = collect($data['lines'])->values();
        $products = Product::whereIn('id', $rows->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->all())->get()->keyBy('id');
        $wanted = $rows->flatMap(fn ($l) => array_column($l['addons'] ?? [], 'addon_id'))->map(fn ($id) => (int) $id)->unique()->values();
        $addons = $wanted->isEmpty() ? collect() : ProductAddon::offered(self::ADDON_CHANNEL)->whereIn('id', $wanted)
            ->with(['products:products.id', 'categories:product_categories.id', 'stockProduct'])->get()->keyBy('id');

        $of = fn (string $type) => $rows->filter(fn ($l) => ($l['item_type'] ?? null) === $type)->pluck('item_id')->filter()->map(fn ($id) => (int) $id)->all();
        $catalogue = [
            'plan' => MembershipPlan::whereIn('id', $of('plan'))->get()->keyBy('id'),
            'pt' => PtPackage::whereIn('id', $of('pt'))->get()->keyBy('id'),
            'program' => Program::whereIn('id', $of('program'))->get()->keyBy('id'),
        ];

        $lines = [];
        foreach ($rows as $i => $l) {
            $type = $l['item_type'] ?? null;
            if (in_array($type, ['plan', 'pt', 'program'], true)) {
                if ($line = $this->serviceLine($type, $catalogue[$type]->get((int) ($l['item_id'] ?? 0)), $l, $member, $rows, $strict, "lines.$i")) {
                    array_push($lines, ...$line);
                }

                continue;
            }
            $product = filled($l['product_id'] ?? null) ? $products->get((int) $l['product_id']) : null;
            $lines[] = [
                'description' => trim((string) $l['description']),
                'quantity' => $l['quantity'],
                'unit_price' => $l['unit_price'],
                'discount' => $l['discount'] ?? 0,
                'itemable' => $product,
            ];
            $parent = array_key_last($lines);
            foreach ($this->addonLines($product, $l, $addons, $strict, "lines.$i.addons") as $addon) {
                $lines[] = $addon + ['parent' => $parent];
            }
        }

        $options = [
            'type' => 'other',
            'branch_id' => $draft?->branch_id ?? $this->branches->assign(),
            'issue_date' => $data['issue_date'],
            'due_date' => $data['due_date'] ?? null,
            'notes' => $data['notes'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
            'payment_terms_days' => is_numeric($data['payment_terms_days'] ?? null) ? (int) $data['payment_terms_days'] : null,
            'paper_lang' => $data['paper_lang'] ?? null,
            'customer_phone' => $mode === 'new' ? ($data['customer_phone'] ?? null) : null,
            'customer_email' => $mode === 'new' ? ($data['customer_email'] ?? null) : null,
        ];
        $name = $member ? null : ($mode === 'new' ? ($data['customer_name'] ?? null) : (($data['customer_name'] ?? null) ?: 'عميل نقدي'));

        return [$member, $lines, $options, $name];
    }

    /**
     * A service from the catalogue as the invoice bills it: what it is and its price from the database (the form gives
     * only which one, how many of a program, and a discount); a plan's period and a first membership's signup fee as
     * its sale would bill them. Checked here, refused when saving (named on the line), left out of the paper while the
     * form is unfinished: a plan or a package is sold to a member chosen, once, by whoever may sell it; a plan or a
     * package or a program not on sale is not billed; a program without a price only by whoever manages programs.
     *
     * @param  array<string, mixed>  $line  the form's line
     * @param  Collection<int, array<string, mixed>>  $rows  the form's lines
     * @return list<array<string, mixed>>|null
     */
    private function serviceLine(string $type, MembershipPlan|PtPackage|Program|null $item, array $line, ?Member $member, Collection $rows, bool $strict, string $field): ?array
    {
        $user = Auth::guard('web')->user();
        $same = $rows->filter(fn ($l) => ($l['item_type'] ?? null) === $type && (int) ($l['item_id'] ?? 0) === (int) ($line['item_id'] ?? 0))->count();
        $quantity = (float) ($line['quantity'] ?? 0);
        $typed = Money::normalize(is_numeric($line['unit_price'] ?? null) ? $line['unit_price'] : 0);
        $refusal = match (true) {
            $item === null => ['item_id', 'هذا الصنف لم يعد في الكتالوج — احذف البند.'],
            ! $item->is_active => ['item_id', '«'.$item->name.'» غير متاح للبيع.'],
            $type === 'plan' && ! $user?->can('memberships.sell') => ['item_id', 'بيع العضويات يتطلب صلاحية «بيع وتجديد العضويات».'],
            $type === 'pt' && ! $user?->can('pt.sell') => ['item_id', 'بيع باقات التدريب الشخصي يتطلب صلاحية «بيع باقات التدريب الشخصي».'],
            $type !== 'program' && $member === null => ['item_id', '«'.$item->name.'» يُباع لعضو: اختر «عضو» ثم العضو، فتُنشأ له وتُفعَّل بعد السداد الكامل.'],
            $type === 'plan' && $rows->filter(fn ($l) => ($l['item_type'] ?? null) === 'plan')->count() > 1 => ['item_id', 'عضوية واحدة في الفاتورة: للعضو اشتراك واحد بانتظار الدفع.'],
            $type === 'pt' && $same > 1 => ['item_id', '«'.$item->name.'» مكررة في الفاتورة: باقة واحدة منها بانتظار الدفع.'],
            $type !== 'program' && abs($quantity - 1) > 0.0001 => ['quantity', 'الكمية واحدة: يُباع «'.$item->name.'» مرة في الفاتورة.'],
            $type === 'program' && $item->price === null && ! ($user?->can('classes.manage') && Money::toMinor($typed) > 0) => ['unit_price', 'لا سعر لـ«'.$item->name.'» في الكتالوج: حدّده من صفحة البرامج أولًا (أو يسعّره هنا من يدير البرامج).'],
            default => null,
        };
        $discount = $line['discount'] ?? 0;
        $price = $item === null ? '0.000' : ($item instanceof Program && $item->price === null ? $typed : Money::normalize($item->price));
        if ($refusal === null && Money::toMinor(is_numeric($discount) ? $discount : 0) > Money::toMinor(Money::mul($price, $type === 'program' ? $quantity : 1))) {
            $refusal = ['discount', 'الخصم أكبر من قيمة البند.'];
        }
        if ($refusal !== null) {
            if ($strict) {
                throw new BusinessRuleException('invoice.catalogue', $refusal[1], "$field.{$refusal[0]}");
            }

            return null;
        }

        if ($item instanceof MembershipPlan) {
            $quote = $member ? $this->memberships->quote($member, $item) : ['description' => MembershipService::KIND_LABELS['new'].' — '.$item->name, 'signup_fee' => '0.000'];
            $lines = [['description' => $quote['description'], 'quantity' => 1, 'unit_price' => Money::normalize($item->price), 'discount' => $discount, 'itemable' => $item, 'field' => $field]];
            if (! Money::isZero($quote['signup_fee'])) {
                $lines[] = ['description' => 'رسوم التسجيل', 'quantity' => 1, 'unit_price' => $quote['signup_fee'], 'discount' => 0, 'itemable' => $item, 'signup' => true];
            }

            return $lines;
        }
        if ($item instanceof PtPackage) {
            return [['description' => 'تدريب شخصي — '.$item->name.' ('.$item->sessions_count.' جلسات)', 'quantity' => 1, 'unit_price' => Money::normalize($item->price), 'discount' => $discount, 'itemable' => $item, 'field' => $field]];
        }

        // A program: its price, or the one typed by whoever manages programs when it has none. Selling it books nothing.
        return [[
            'description' => (self::PROGRAM_KINDS[$item->category] ?? 'برنامج').' — '.$item->name,
            'quantity' => $line['quantity'],
            'unit_price' => $price,
            'discount' => $discount,
            'itemable' => $item,
        ]];
    }

    /**
     * Issuing: each plan and package line made into a membership or a package pending for this invoice, the lines its
     * sale bills in place of the catalogue's (a plan's signup fee as its sale reckons it, under the member's lock), the
     * add-ons' parents moved with them. The records are tied to the invoice once it is issued.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: list<array<string, mixed>>, 1: list<Membership|PtSubscription>}
     */
    private function services(?Member $member, array $lines, ?int $branchId): array
    {
        $out = [];
        $made = [];
        $moved = [];
        foreach ($lines as $i => $line) {
            $item = $line['itemable'] ?? null;
            if (! empty($line['signup'])) {
                continue; // the plan's own sale bills it, as it reckons it now
            }
            $moved[$i] = count($out);
            try {
                if ($item instanceof MembershipPlan && $member) {
                    [$membership, $billed] = $this->memberships->pendingForInvoice($member, $item, ['discount' => $line['discount'] ?? 0, 'branch_id' => $branchId]);
                    $made[] = $membership;
                    foreach ($billed as $b) {
                        $out[] = $b + ['quantity' => 1];
                    }
                } elseif ($item instanceof PtPackage && $member) {
                    [$subscription, $billed] = $this->pt->pendingForInvoice($member, $item);
                    $made[] = $subscription;
                    $out[] = $billed + ['quantity' => 1, 'discount' => $line['discount'] ?? 0];
                } else {
                    $out[] = $line;
                }
            } catch (BusinessRuleException $e) {
                // Its sale's refusal (a membership already awaiting payment, days already covered…), on the line.
                throw new BusinessRuleException($e->rule, $e->getMessage(), isset($line['field']) ? $line['field'].'.item_id' : $e->field);
            }
        }
        foreach ($out as $k => $line) {
            if (isset($line['parent'])) {
                $out[$k]['parent'] = $moved[$line['parent']];
            }
        }

        return [$out, $made];
    }

    /**
     * The invoice's kind, which the books post its revenue by: a membership or a personal-training sale alone, as its
     * own screen issues it; anything else (products, programs, mixed lines) as every invoice of this editor is.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private static function typeOf(array $lines): string
    {
        $kinds = collect($lines)->map(fn (array $l) => match (true) {
            ($l['itemable'] ?? null) instanceof Membership => 'membership',
            ($l['itemable'] ?? null) instanceof PtSubscription => 'pt',
            default => 'other',
        })->unique();

        return $kinds->count() === 1 ? $kinds->first() : 'other';
    }

    /**
     * The add-ons chosen on a product line, each a line of its own: priced from the database and checked as the point of
     * sale checks them — offered there, going with this product, once each, within its quantity per piece. Only which
     * add-on and how many per piece come from the form; the line's quantity is that times the product's. Saving refuses
     * what breaks a rule (named on the line); the preview leaves it out.
     *
     * @param  array<string, mixed>  $line  the form's line
     * @param  Collection<int, ProductAddon>  $addons  the ones asked for that are offered, by id
     * @return list<array<string, mixed>>
     */
    private function addonLines(?Product $product, array $line, Collection $addons, bool $strict, string $field): array
    {
        $lines = [];
        $seen = [];
        foreach ($line['addons'] ?? [] as $pick) {
            $addon = $addons->get((int) $pick['addon_id']);
            $count = (int) $pick['quantity'];
            $refusal = match (true) {
                ! $product => 'الإضافات تُختار مع صنف من الكتالوج.',
                ! $addon => 'إحدى الإضافات المختارة لم تعد متاحة — احذفها من البند.',
                ! $addon->fits($product) => '«'.$addon->name.'» لا تُباع مع «'.$product->name.'».',
                isset($seen[$addon->id]) => '«'.$addon->name.'» مختارة مرتين على البند نفسه.',
                $count < 1 || $count > max(1, $addon->max_quantity) => 'الحد المسموح من «'.$addon->name.'» هو '.max(1, $addon->max_quantity).' لكل قطعة.',
                default => null,
            };
            if ($refusal !== null) {
                if ($strict) {
                    throw new BusinessRuleException('invoice.addon', $refusal, $field);
                }

                continue;
            }

            $seen[$addon->id] = true;
            $lines[] = [
                'description' => '+ '.$addon->name.' — '.trim((string) $line['description']),
                'quantity' => round($count * (float) $line['quantity'], 3),
                'unit_price' => $addon->price,
                'discount' => 0,
                'itemable' => $addon,
            ];
        }

        return $lines;
    }

    /** @param  list<UploadedFile>  $files */
    private function keepFiles(Invoice $invoice, array $files): void
    {
        if ($invoice->attachments()->count() + count($files) > 6) {
            throw new BusinessRuleException('invoice.attachments', 'حتى ستة مرفقات للفاتورة.', 'attachments');
        }
        foreach ($files as $file) {
            $invoice->attachments()->create([
                'path' => $this->store($file, 'tenants/'.$invoice->tenant_id.'/invoices', 'attachments'),
                'name' => Str::limit($file->getClientOriginalName(), 190, ''),
                'mime' => $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'uploaded_by' => Auth::guard('web')->id(),
            ]);
        }
    }

    /** Files written while saving: removed again if the save fails, so nothing is left without its invoice. */
    private array $stored = [];

    /** Kept whole or not at all (SafeFiles): a refused or cut copy fails the save, with the error on $field. */
    private function store(UploadedFile $file, string $folder, string $field): string
    {
        return $this->stored[] = SafeFiles::putUpload($file, $folder, $field, name: Str::random(40).'.'.$file->guessExtension());
    }

    /**
     * @template T
     *
     * @param  callable(): T  $save
     * @return T
     */
    private function keepingFilesOnlyIfSaved(callable $save): mixed
    {
        $this->stored = [];
        try {
            return $save();
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($this->stored);
            throw $e;
        } finally {
            $this->stored = [];
        }
    }

    /** @param  list<int>  $ids */
    private function dropFiles(Invoice $invoice, array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $gone = InvoiceAttachment::where('invoice_id', $invoice->id)->whereIn('id', $ids)->get();
        foreach ($gone as $file) {
            $file->delete();
            SafeFiles::deleteAfterCommit($file->path);
        }
    }
}
