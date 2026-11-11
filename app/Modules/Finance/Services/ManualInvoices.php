<?php

namespace App\Modules\Finance\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Tenancy\BranchContext;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\InvoiceAttachment;
use App\Modules\Gym\Models\Member;
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
 * @phpstan-type Data array{customer_mode: string, member_id?: int|null, customer_name?: string|null, customer_phone?: string|null, customer_email?: string|null, issue_date: string, due_date?: string|null, payment_terms_days?: int|string|null, notes?: string|null, internal_notes?: string|null, paper_lang?: string|null, lines: list<array{product_id?: int|string|null, description: string, quantity: string|float, unit_price: string|float, discount?: string|float|null, addons?: list<array{addon_id: int|string, quantity: int|string}>}>}
 */
final class ManualInvoices
{
    /** How money taken on issue may be paid; «credit» (آجل) leaves the whole amount owed. */
    public const METHODS = ['credit', 'cash', 'card', 'bank_transfer'];

    /** Staff sell at the counter and on invoices alike: an invoice offers the add-ons offered at the point of sale. */
    public const ADDON_CHANNEL = 'pos';

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments,
        private readonly StockService $stock,
        private readonly BranchContext $branches,
        private readonly AuditLogger $audit,
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
            $invoice = $this->invoices->issue($member, $lines, $options, $name);

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
                        'proof_path' => $proof instanceof UploadedFile ? $this->store($proof, 'tenants/'.$invoice->tenant_id.'/payment-proofs') : null,
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

        $lines = [];
        foreach ($rows as $i => $l) {
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
                'path' => $this->store($file, 'tenants/'.$invoice->tenant_id.'/invoices'),
                'name' => Str::limit($file->getClientOriginalName(), 190, ''),
                'mime' => $file->getMimeType(),
                'size' => (int) $file->getSize(),
                'uploaded_by' => Auth::guard('web')->id(),
            ]);
        }
    }

    /** Files written while saving: removed again if the save fails, so nothing is left without its invoice. */
    private array $stored = [];

    private function store(UploadedFile $file, string $folder): string
    {
        return $this->stored[] = $file->storeAs($folder, Str::random(40).'.'.$file->guessExtension(), 'local');
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
            DB::afterCommit(fn () => Storage::disk('local')->delete($file->path));
        }
    }
}
