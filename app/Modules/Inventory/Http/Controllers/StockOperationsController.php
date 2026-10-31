<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Inventory\Http\Controllers\Concerns\ScopesBranches;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\ProductStock;
use App\Modules\Inventory\Models\Stocktake;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplyRequest;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\SupplyService;
use App\Modules\Inventory\Services\TransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The inventory section's documents: stock counts, transfers between branches and supply requests. Each step is
 * done by the services (locked, checked again, logged); this controller lists, validates and shows.
 */
class StockOperationsController extends Controller
{
    use ScopesBranches;

    // ── Stock counts ─────────────────────────────────────────────────────────

    public function counts(Request $request): Response
    {
        $branch = $this->listBranch($request);
        $status = in_array($request->query('status'), [Stocktake::DRAFT, Stocktake::APPROVED, Stocktake::CANCELLED], true) ? $request->query('status') : '';

        return Inertia::render('Inventory/Stock/Counts', [
            'counts' => app(BranchContext::class)->narrow(Stocktake::query(), $branch)->with(['branch:id,name', 'creator:id,name', 'approver:id,name'])
                ->withCount(['lines', 'lines as counted' => fn ($q) => $q->whereNotNull('counted_quantity'), 'lines as differences' => fn ($q) => $q->where('difference', '!=', 0)])
                ->when($status, fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(25)->withQueryString()
                ->through(fn (Stocktake $c) => $c->only('id', 'number', 'status', 'lines_count', 'counted', 'differences') + [
                    'branch' => $c->branch?->name, 'by' => $c->creator?->name, 'approver' => $c->approver?->name,
                    'at' => $c->created_at->format('Y-m-d H:i'), 'approved_at' => $c->approved_at?->format('Y-m-d H:i'),
                ]),
            'filters' => ['status' => $status],
            'branch' => $this->branchFilter($request, $branch),
            'categories' => ProductCategory::ordered()->get(['id', 'name']),
        ]);
    }

    public function startCount(Request $request, InventoryService $inventory): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', ...app(BranchContext::class)->rule()],
            'category_id' => ['nullable', TenantRule::exists('product_categories')],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $ids = ! empty($data['category_id']) ? Product::where('product_category_id', $data['category_id'])->pluck('id')->all() : [];
        $count = $inventory->startCount((int) $data['branch_id'], $ids === [] && ! empty($data['category_id']) ? [0] : $ids, $data['notes'] ?? null);

        return redirect()->route('club.inventory.counts.show', $count)->with('success', 'بدأ الجرد '.$count->number.'. أدخل الكميات المعدودة ثم اعتمده.');
    }

    public function showCount(Stocktake $stocktake): Response
    {
        $stocktake->load(['branch:id,name', 'creator:id,name', 'approver:id,name', 'lines.product:id,name,sku,cost']);

        return Inertia::render('Inventory/Stock/CountShow', [
            'count' => $stocktake->only('id', 'number', 'status', 'notes') + [
                'branch' => $stocktake->branch?->name, 'by' => $stocktake->creator?->name, 'approver' => $stocktake->approver?->name,
                'at' => $stocktake->created_at->format('Y-m-d H:i'), 'approved_at' => $stocktake->approved_at?->format('Y-m-d H:i'),
                'lines' => $stocktake->lines->map(fn ($l) => $l->only('id', 'system_quantity', 'counted_quantity', 'difference', 'unit_cost', 'reason', 'note') + ['product' => $l->product?->name, 'sku' => $l->product?->sku]),
            ],
            'reasons' => array_diff_key(StockService::REASONS, ['opening' => true]),
        ]);
    }

    public function saveCount(Request $request, Stocktake $stocktake, InventoryService $inventory): RedirectResponse
    {
        $data = $request->validate([
            'counts' => ['nullable', 'array', 'max:2000'],
            'counts.*.counted' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'counts.*.reason' => ['nullable', 'string', 'max:30'],
            'counts.*.note' => ['nullable', 'string', 'max:200'],
            'approve' => ['boolean'],
        ]);
        $inventory->saveCounts($stocktake, $data['counts'] ?? []);
        if ($request->boolean('approve')) {
            abort_unless($request->user()->can('inventory.approve'), 403);
            $inventory->approveCount($stocktake);

            return back()->with('success', 'اعتُمد الجرد '.$stocktake->number.' وسُجّلت الفروقات في سجل المخزون.');
        }

        return back()->with('success', 'حُفظت الكميات المعدودة.');
    }

    public function cancelCount(Stocktake $stocktake, InventoryService $inventory): RedirectResponse
    {
        $inventory->cancelCount($stocktake);

        return redirect()->route('club.inventory.counts')->with('success', 'أُلغي الجرد '.$stocktake->number.'.');
    }

    // ── Transfers between branches ───────────────────────────────────────────

    public function transfers(Request $request): Response
    {
        $branch = $this->listBranch($request);
        $status = array_key_exists((string) $request->query('status'), StockTransfer::LABELS) ? $request->query('status') : '';
        $q = $request->string('q')->trim()->toString();
        $base = fn () => StockTransfer::query()
            ->when($branch, fn ($w) => $w->where(fn ($x) => $x->where('from_branch_id', $branch)->orWhere('to_branch_id', $branch)))
            ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('number', 'ilike', '%'.$q.'%')->orWhereHas('lines.product', fn ($p) => $p->where('name', 'ilike', '%'.$q.'%'))));
        $counts = $base()->toBase()->selectRaw("count(*) filter (where status = 'pending') as pending, count(*) filter (where status = 'approved') as approved, count(*) filter (where status = 'in_transit') as in_transit, count(*) filter (where status = 'discrepancy') as discrepancy")->first();

        return Inertia::render('Inventory/Stock/Transfers', [
            'transfers' => $base()->with(['fromBranch:id,name', 'toBranch:id,name', 'requester:id,name'])->withSum('lines as quantity', 'requested_quantity')
                ->when($status, fn ($w, $s) => $w->where('status', $s))->latest('id')->paginate(25)->withQueryString()
                ->through(fn (StockTransfer $t) => $t->only('id', 'number', 'status') + [
                    'status_label' => StockTransfer::LABELS[$t->status], 'from' => $t->fromBranch?->name, 'to' => $t->toBranch?->name,
                    'quantity' => round((float) $t->quantity, 3), 'by' => $t->requester?->name, 'at' => $t->created_at->format('Y-m-d H:i'),
                ]),
            'summary' => array_map('intval', (array) $counts),
            'filters' => ['status' => $status, 'q' => $q],
            'labels' => StockTransfer::LABELS,
            'branch' => $this->branchFilter($request, $branch),
            'branches' => Branch::where('is_active', true)->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'products' => $this->stockedWithBalances(),
        ]);
    }

    public function storeTransfer(Request $request, TransferService $transfers): RedirectResponse
    {
        $data = $request->validate([
            'from_branch_id' => ['required', ...app(BranchContext::class)->rule()],
            'to_branch_id' => ['required', 'different:from_branch_id', TenantRule::exists('branches')],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', TenantRule::exists('products')->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'direct' => ['boolean'],
            'client_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ], ['to_branch_id.different' => 'اختر فرعًا مستقبلًا غير الفرع المرسل.']);
        $direct = $request->boolean('direct');
        abort_if($direct && ! $request->user()->can('inventory.approve'), 403);
        $transfer = $transfers->create((int) $data['from_branch_id'], (int) $data['to_branch_id'], $data['lines'], $data['notes'] ?? null, $data['client_key'] ?? null, $direct);

        return redirect()->route('club.inventory.transfers.show', $transfer)->with('success', $direct ? 'أُرسل النقل '.$transfer->number.' وهو الآن قيد النقل.' : 'سُجّل طلب النقل '.$transfer->number.' بانتظار الموافقة.');
    }

    public function showTransfer(StockTransfer $transfer): Response
    {
        $this->ensureEitherBranch($transfer);
        $transfer->load(['fromBranch:id,name', 'toBranch:id,name', 'requester:id,name', 'approver:id,name', 'dispatcher:id,name', 'receiver:id,name', 'lines.product:id,name,sku']);
        $available = ProductStock::where('branch_id', $transfer->from_branch_id)->whereIn('product_id', $transfer->lines->pluck('product_id'))->pluck('quantity', 'product_id');
        $user = request()->user();
        $branches = app(BranchContext::class);

        return Inertia::render('Inventory/Stock/TransferShow', [
            'transfer' => $transfer->only('id', 'number', 'status', 'notes', 'reason') + [
                'status_label' => StockTransfer::LABELS[$transfer->status],
                'from' => $transfer->fromBranch?->name, 'to' => $transfer->toBranch?->name,
                'steps' => [
                    ['label' => 'طُلب', 'by' => $transfer->requester?->name, 'at' => $transfer->created_at->format('Y-m-d H:i')],
                    ['label' => 'اعتُمد', 'by' => $transfer->approver?->name, 'at' => $transfer->approved_at?->format('Y-m-d H:i')],
                    ['label' => 'أُرسل', 'by' => $transfer->dispatcher?->name, 'at' => $transfer->dispatched_at?->format('Y-m-d H:i')],
                    ['label' => 'استُلم', 'by' => $transfer->receiver?->name, 'at' => $transfer->received_at?->format('Y-m-d H:i')],
                ],
                'lines' => $transfer->lines->map(fn ($l) => $l->only('id', 'requested_quantity', 'approved_quantity', 'sent_quantity', 'received_quantity', 'lost_quantity', 'returned_quantity', 'late_quantity', 'unit_cost', 'note') + [
                    'product' => $l->product?->name, 'sku' => $l->product?->sku, 'outstanding' => $l->outstanding(), 'available' => round((float) ($available[$l->product_id] ?? 0), 3),
                ]),
            ],
            'can' => [
                'approve' => $user->can('inventory.approve') && $branches->allows($user, $transfer->from_branch_id),
                'send' => $user->can('inventory.transfer') && $branches->allows($user, $transfer->from_branch_id),
                'receive' => $user->can('inventory.transfer') && $branches->allows($user, $transfer->to_branch_id),
            ],
        ]);
    }

    public function transferStep(Request $request, StockTransfer $transfer, string $step, TransferService $transfers): RedirectResponse
    {
        $this->ensureEitherBranch($transfer);
        $needs = in_array($step, ['approve', 'reject'], true) ? 'inventory.approve' : 'inventory.transfer';
        abort_unless($request->user()->can($needs), 403);
        $data = $request->validate([
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'reason' => [in_array($step, ['reject', 'cancel'], true) ? 'required' : 'nullable', 'string', 'min:3', 'max:255'],
            'line_id' => ['nullable', 'integer'],
            'outcome' => ['nullable', 'in:lost,returned,late'],
            'quantity' => ['nullable', 'numeric', 'min:0.001'],
        ]);
        $q = array_filter($data['quantities'] ?? [], fn ($v) => $v !== null && $v !== '');
        $result = match ($step) {
            'approve' => $transfers->approve($transfer, $q),
            'dispatch' => $transfers->dispatch($transfer),
            'receive' => $transfers->receive($transfer, $q, $data['reason'] ?? null),
            'resolve' => $transfers->resolve($transfer, (int) $request->input('line_id'), (string) ($data['outcome'] ?? ''), (float) ($data['quantity'] ?? 0), (string) ($data['reason'] ?? '')),
            'reject' => $transfers->reject($transfer, $data['reason']),
            'cancel' => $transfers->cancel($transfer, $data['reason']),
            default => abort(404),
        };

        return back()->with('success', 'سند النقل '.$result->number.': '.StockTransfer::LABELS[$result->status].'.');
    }

    // ── Supply requests ──────────────────────────────────────────────────────

    public function supply(Request $request): Response
    {
        $branch = $this->listBranch($request);
        $status = in_array($request->query('status'), ['pending', 'approved', 'rejected', 'cancelled'], true) ? $request->query('status') : '';

        return Inertia::render('Inventory/Stock/Supply', [
            'requests' => SupplyRequest::query()->when($branch, fn ($q) => $q->where('branch_id', $branch))
                ->with(['branch:id,name', 'requester:id,name', 'transfers:id,supply_request_id,status', 'purchaseOrder:id,status'])->withSum('lines as quantity', 'quantity')
                ->when($status, fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(25)->withQueryString()
                ->through(fn (SupplyRequest $r) => $r->only('id', 'number', 'status') + [
                    'progress' => $r->progress(), 'branch' => $r->branch?->name, 'quantity' => round((float) $r->quantity, 3),
                    'by' => $r->requester?->name, 'at' => $r->created_at->format('Y-m-d H:i'), 'needed_by' => $r->needed_by?->toDateString(),
                ]),
            'filters' => ['status' => $status],
            'branch' => $this->branchFilter($request, $branch),
            'products' => $this->stockedWithBalances(),
        ]);
    }

    public function storeSupply(Request $request, SupplyService $supply): RedirectResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', ...app(BranchContext::class)->rule()],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_id' => ['required', TenantRule::exists('products')->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000'],
            'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $request = $supply->create((int) $data['branch_id'], $data['lines'], $data['notes'] ?? null, $data['needed_by'] ?? null);

        return redirect()->route('club.inventory.supply.show', $request)->with('success', 'أُرسل طلب التزويد '.$request->number.' للمراجعة.');
    }

    public function showSupply(SupplyRequest $supplyRequest): Response
    {
        $supplyRequest->load(['branch:id,name', 'requester:id,name', 'reviewer:id,name', 'lines.product:id,name,sku,cost', 'lines.sourceBranch:id,name', 'transfers.fromBranch:id,name', 'purchaseOrder:id,number,status']);
        $balances = ProductStock::whereIn('product_id', $supplyRequest->lines->pluck('product_id'))->get()->groupBy('product_id');

        return Inertia::render('Inventory/Stock/SupplyShow', [
            'request' => $supplyRequest->only('id', 'number', 'status', 'notes', 'reason') + [
                'progress' => $supplyRequest->progress(), 'branch_id' => $supplyRequest->branch_id, 'branch' => $supplyRequest->branch?->name,
                'by' => $supplyRequest->requester?->name, 'reviewer' => $supplyRequest->reviewer?->name,
                'at' => $supplyRequest->created_at->format('Y-m-d H:i'), 'reviewed_at' => $supplyRequest->reviewed_at?->format('Y-m-d H:i'), 'needed_by' => $supplyRequest->needed_by?->toDateString(),
                'lines' => $supplyRequest->lines->map(fn ($l) => $l->only('id', 'quantity', 'approved_quantity', 'fulfil_by') + [
                    'product' => $l->product?->name, 'sku' => $l->product?->sku, 'source' => $l->sourceBranch?->name,
                    'balances' => collect($balances[$l->product_id] ?? [])->mapWithKeys(fn ($s) => [$s->branch_id => round((float) $s->quantity, 3)]),
                ]),
                'transfers' => $supplyRequest->transfers->map(fn ($t) => ['id' => $t->id, 'number' => $t->number, 'from' => $t->fromBranch?->name, 'status' => StockTransfer::LABELS[$t->status]]),
                'order' => $supplyRequest->purchaseOrder?->only('id', 'number', 'status'),
            ],
            'branches' => Branch::where('is_active', true)->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canReview' => request()->user()->can('inventory.approve'),
        ]);
    }

    public function reviewSupply(Request $request, SupplyRequest $supplyRequest, string $decision, SupplyService $supply): RedirectResponse
    {
        if ($decision === 'cancel') {
            abort_unless(app(BranchContext::class)->allows($request->user(), $supplyRequest->branch_id), 404);
            $supply->cancel($supplyRequest, (string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason']);

            return back()->with('success', 'أُلغي الطلب.');
        }
        abort_unless($request->user()->can('inventory.approve'), 403);
        if ($decision === 'reject') {
            $supply->reject($supplyRequest, (string) $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason']);

            return back()->with('success', 'رُفض الطلب '.$supplyRequest->number.'.');
        }
        abort_unless($decision === 'approve', 404);
        $data = $request->validate([
            'decisions' => ['required', 'array'],
            'decisions.*.quantity' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'decisions.*.source' => ['required', 'string', 'max:20'],
            'supplier_id' => ['nullable', TenantRule::exists('suppliers')],
        ]);
        foreach ($data['decisions'] as $decisionRow) {
            abort_unless($decisionRow['source'] === 'purchase' || Branch::whereKey((int) $decisionRow['source'])->exists(), 422);
        }
        $supply->approve($supplyRequest, $data['decisions'], isset($data['supplier_id']) ? (int) $data['supplier_id'] : null);

        return back()->with('success', 'اعتُمد الطلب '.$supplyRequest->number.' وأُنشئت عمليات التزويد.');
    }

    /** A user tied to a branch reaches a transfer only if it is from or to their branch. */
    private function ensureEitherBranch(StockTransfer $transfer): void
    {
        $user = request()->user();
        $branches = app(BranchContext::class);
        abort_unless($branches->allows($user, $transfer->from_branch_id) || $branches->allows($user, $transfer->to_branch_id), 404);
    }

    /** @return list<array<string, mixed>> */
    private function stockedWithBalances(): array
    {
        $balances = ProductStock::get(['product_id', 'branch_id', 'quantity'])->groupBy('product_id');

        return Product::where('track_stock', true)->orderBy('name')->get(['id', 'name', 'sku'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'balances' => collect($balances[$p->id] ?? [])->mapWithKeys(fn ($s) => [$s->branch_id => round((float) $s->quantity, 3)])])->all();
    }
}
