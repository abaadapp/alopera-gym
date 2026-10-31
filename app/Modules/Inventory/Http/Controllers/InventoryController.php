<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Controllers\Concerns\ScopesBranches;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The inventory section's balances (stocked products only, per branch or for all) and its stock log, with one-off
 * adjustments. The products themselves are managed in the products section; exports are on the reports page.
 */
class InventoryController extends Controller
{
    use ScopesBranches;

    public function index(Request $request): Response
    {
        $branch = $this->listBranch($request);
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['in', 'low', 'out'], true) ? $request->query('status') : '',
            'category' => $request->string('category')->toString(),
        ];
        [$qty, $min] = $this->columns($branch);

        $base = fn () => $this->stocked($branch)
            ->when($filters['q'], fn ($q, $t) => $q->where(fn ($w) => $w->where('products.name', 'ilike', '%'.$t.'%')->orWhere('products.sku', 'ilike', $t)->orWhere('products.barcode', $t)))
            ->when(ctype_digit($filters['category']), fn ($q) => $q->where('products.product_category_id', (int) $filters['category']));
        $counts = $base()->toBase()->selectRaw("count(*) as total, count(*) filter (where {$qty} <= 0) as out, count(*) filter (where {$qty} > 0 and {$qty} <= {$min}) as low, coalesce(sum(greatest({$qty}, 0) * products.cost), 0) as value")->first();

        $rows = $base()->with('category:id,name')
            ->select('products.*')->selectRaw("{$qty} as branch_quantity, {$min} as branch_min")
            ->when($filters['status'] === 'out', fn ($q) => $q->whereRaw("{$qty} <= 0"))
            ->when($filters['status'] === 'low', fn ($q) => $q->whereRaw("{$qty} > 0 and {$qty} <= {$min}"))
            ->when($filters['status'] === 'in', fn ($q) => $q->whereRaw("{$qty} > {$min}"))
            ->orderBy('products.name')->paginate(30)->withQueryString();
        $split = $branch ? [] : DB::table('product_stocks as s')->join('branches as b', 'b.id', '=', 's.branch_id')
            ->whereIn('s.product_id', $rows->pluck('id'))->where('s.tenant_id', $request->user()->tenant_id)
            ->orderByDesc('b.is_main')->orderBy('b.name')->get(['s.product_id', 'b.name', 's.quantity'])->groupBy('product_id');

        $inTransit = DB::table('stock_transfer_lines as l')->join('stock_transfers as t', 't.id', '=', 'l.stock_transfer_id')
            ->where('t.tenant_id', $request->user()->tenant_id)->whereIn('t.status', [StockTransfer::IN_TRANSIT, StockTransfer::DISCREPANCY])
            ->when($branch, fn ($q) => $q->where(fn ($w) => $w->where('t.to_branch_id', $branch)->orWhere('t.from_branch_id', $branch)))
            ->selectRaw('coalesce(sum(l.sent_quantity - l.received_quantity - l.lost_quantity - l.returned_quantity - l.late_quantity), 0) as qty, coalesce(sum((l.sent_quantity - l.received_quantity - l.lost_quantity - l.returned_quantity - l.late_quantity) * l.unit_cost), 0) as value')->first();

        return Inertia::render('Inventory/Stock/Index', [
            'rows' => $rows->through(fn (Product $p) => [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'category' => $p->category?->name, 'image_url' => $p->imageUrl(),
                'quantity' => round((float) $p->branch_quantity, 3), 'min' => round((float) $p->branch_min, 3), 'cost' => $p->cost,
                'value' => round(max(0, (float) $p->branch_quantity) * (float) $p->cost, 3),
                'status' => (float) $p->branch_quantity <= 0 ? 'out' : ((float) $p->branch_quantity <= (float) $p->branch_min ? 'low' : 'in'),
                'branches' => collect($split[$p->id] ?? [])->map(fn ($s) => ['name' => $s->name, 'quantity' => round((float) $s->quantity, 3)])->values(),
            ]),
            'summary' => ['total' => (int) $counts->total, 'out' => (int) $counts->out, 'low' => (int) $counts->low, 'value' => round((float) $counts->value, 3), 'in_transit' => round((float) $inTransit->qty, 3), 'in_transit_value' => round((float) $inTransit->value, 3)],
            'filters' => $filters,
            'branch' => $this->branchFilter($request, $branch),
            'categories' => ProductCategory::ordered()->get(['id', 'name']),
            'products' => $this->adjustable(),
            'reasons' => StockService::REASONS,
        ]);
    }

    public function movements(Request $request): Response
    {
        $branch = $this->listBranch($request);
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'type' => array_key_exists((string) $request->query('type'), StockMovement::TYPES) ? $request->query('type') : '',
            'reason' => array_key_exists((string) $request->query('reason'), StockService::REASONS) ? $request->query('reason') : '',
            'from' => $request->date('from')?->toDateString() ?? '',
            'to' => $request->date('to')?->toDateString() ?? '',
        ];
        // One branch (old rows without a branch are the main branch's), or all of them.
        $query = app(BranchContext::class)->narrow(StockMovement::query(), $branch, 'stock_movements.branch_id')
            ->when($filters['q'], fn ($q, $t) => $q->where(fn ($w) => $w->where('document', 'ilike', '%'.$t.'%')->orWhereHas('product', fn ($p) => $p->where('name', 'ilike', '%'.$t.'%'))))
            ->when($filters['type'], fn ($q, $t) => $q->where('type', $t))
            ->when($filters['reason'], fn ($q, $r) => $q->where('reason', $r))
            ->when($filters['from'], fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
        $losses = (clone $query)->where('type', 'adjustment')->where('quantity', '<', 0)->whereIn('reason', ['damaged', 'lost', 'expired', 'internal'])->toBase()
            ->selectRaw('count(*) as n, coalesce(sum(-quantity * unit_cost), 0) as value')->first();
        $gains = (clone $query)->where('type', 'adjustment')->where('quantity', '>', 0)->toBase()->selectRaw('coalesce(sum(quantity * unit_cost), 0) as value')->first();

        return Inertia::render('Inventory/Stock/Movements', [
            'rows' => (clone $query)->with(['product:id,name,sku', 'branch:id,name', 'user:id,name'])->latest('id')->paginate(40)->withQueryString()
                ->through(fn (StockMovement $m) => [
                    'id' => $m->id, 'at' => $m->created_at->format('Y-m-d H:i'), 'product' => $m->product?->name, 'sku' => $m->product?->sku,
                    'branch' => $m->branch?->name, 'type' => $m->type, 'type_label' => StockMovement::TYPES[$m->type] ?? $m->type,
                    'reason' => $m->reason ? (StockService::REASONS[$m->reason] ?? $m->reason) : null, 'quantity' => $m->quantity, 'unit_cost' => $m->unit_cost,
                    'balance' => $m->branch_balance ?? $m->balance_after, 'document' => $m->document, 'user' => $m->user?->name, 'note' => $m->note,
                ]),
            'summary' => ['count' => (clone $query)->count(), 'losses' => round((float) $losses->value, 3), 'loss_count' => (int) $losses->n, 'gains' => round((float) $gains->value, 3)],
            'filters' => $filters,
            'branch' => $this->branchFilter($request, $branch),
            'types' => StockMovement::TYPES,
            'reasons' => StockService::REASONS,
            'products' => $this->adjustable(),
        ]);
    }

    /** A one-off adjustment: damaged, lost, expired, internal use (taken away) or a correction (either way). */
    public function adjust(Request $request, InventoryService $inventory): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', TenantRule::exists('products')->whereNull('deleted_at')],
            'branch_id' => ['required', ...app(BranchContext::class)->rule()],
            'direction' => ['required', 'in:in,out'],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000'],
            'reason' => ['required', 'in:damaged,lost,expired,internal,correction'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $quantity = (float) $data['quantity'] * ($data['direction'] === 'out' ? -1 : 1);
        $movement = $inventory->adjust(Product::findOrFail($data['product_id']), (int) $data['branch_id'], $quantity, $data['reason'], $data['note'] ?? null);

        return back()->with('success', 'سُجّلت التسوية '.$movement->document.'.');
    }

    /** @return array{0: string, 1: string} the SQL for a product's quantity and its reorder level in the list's branch */
    private function columns(?int $branch): array
    {
        return $branch
            ? ['coalesce(ps.quantity, 0)', 'coalesce(ps.min_quantity, products.reorder_level)']
            : ['products.stock_quantity', 'products.reorder_level'];
    }

    private function stocked(?int $branch): Builder
    {
        return Product::query()->where('products.track_stock', true)
            ->when($branch, fn ($q) => $q->leftJoin('product_stocks as ps', fn ($j) => $j->on('ps.product_id', '=', 'products.id')->where('ps.branch_id', $branch)));
    }

    /** @return list<array{id: int, name: string, cost: string}> */
    private function adjustable(): array
    {
        return Product::where('track_stock', true)->orderBy('name')->get(['id', 'name', 'cost'])->map(fn ($p) => $p->only('id', 'name', 'cost'))->all();
    }
}
