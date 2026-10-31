<?php

namespace App\Modules\Inventory\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\NumberSequence;
use App\Core\Tenancy\BranchContext;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplyRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A branch asks for stock; the management reviews it. Approving sets, line by line, the quantity and where it comes
 * from: another branch (one approved transfer per supplying branch, still to be dispatched and received), or a
 * purchase (a draft purchase order to the chosen supplier, received at the asking branch). Rejecting needs a reason.
 */
final class SupplyService
{
    public function __construct(
        private readonly TransferService $transfers,
        private readonly PurchaseService $purchases,
        private readonly BranchContext $branches,
        private readonly AuditLogger $audit,
    ) {}

    /** @param  list<array{product_id: int, quantity: float|string}>  $lines */
    public function create(int $branchId, array $lines, ?string $notes = null, ?string $neededBy = null): SupplyRequest
    {
        $user = Auth::guard('web')->user();
        if ($user instanceof User && ! $this->branches->allows($user, $branchId)) {
            throw new BusinessRuleException('supply.branch', 'تطلب لفرعك فقط.', 'branch_id');
        }
        $lines = collect($lines)->filter(fn ($l) => (float) $l['quantity'] > 0)->groupBy('product_id')->map(fn ($rows, $id) => ['product_id' => (int) $id, 'quantity' => round((float) $rows->sum('quantity'), 3)])->values();
        if ($lines->isEmpty()) {
            throw new BusinessRuleException('supply.empty', 'أضف منتجًا واحدًا على الأقل بكمية.', 'lines');
        }
        if (Product::where('track_stock', true)->whereIn('id', $lines->pluck('product_id'))->count() !== $lines->count()) {
            throw new BusinessRuleException('supply.untracked', 'اطلب ما هو مربوط بالمخزون فقط.', 'lines');
        }

        return DB::transaction(function () use ($branchId, $lines, $notes, $neededBy) {
            $request = SupplyRequest::create(['number' => NumberSequence::next('supply_request'), 'branch_id' => $branchId, 'status' => SupplyRequest::PENDING, 'notes' => $notes, 'needed_by' => $neededBy, 'requested_by' => Auth::guard('web')->id()]);
            foreach ($lines as $line) {
                $request->lines()->create($line);
            }

            return $request;
        });
    }

    /**
     * @param  array<int, array{quantity: float|string, source: string}>  $decisions  by line id; source is a branch id or "purchase"
     */
    public function approve(SupplyRequest $request, array $decisions, ?int $supplierId = null): SupplyRequest
    {
        return DB::transaction(function () use ($request, $decisions, $supplierId) {
            $request = SupplyRequest::with('lines.product')->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== SupplyRequest::PENDING) {
                throw new BusinessRuleException('supply.status', 'الطلب '.$request->number.' رُوجع مسبقًا.');
            }
            $byBranch = [];
            $toBuy = [];
            foreach ($request->lines as $line) {
                $decision = $decisions[$line->id] ?? null;
                $qty = round(max(0, (float) ($decision['quantity'] ?? 0)), 3);
                if ($qty <= 0) {
                    $line->update(['approved_quantity' => 0]);

                    continue;
                }
                $source = (string) ($decision['source'] ?? '');
                if ($source === 'purchase') {
                    $toBuy[] = ['product_id' => $line->product_id, 'quantity' => $qty, 'unit_cost' => $line->product->cost];
                    $line->update(['approved_quantity' => $qty, 'fulfil_by' => 'purchase', 'source_branch_id' => null]);
                } elseif (ctype_digit($source) && (int) $source !== $request->branch_id) {
                    $byBranch[(int) $source][] = ['product_id' => $line->product_id, 'quantity' => $qty];
                    $line->update(['approved_quantity' => $qty, 'fulfil_by' => 'transfer', 'source_branch_id' => (int) $source]);
                } else {
                    throw new BusinessRuleException('supply.source', 'اختر مصدر «'.$line->product->name.'»: فرع آخر أو شراء.', 'decisions');
                }
            }
            if ($byBranch === [] && $toBuy === []) {
                throw new BusinessRuleException('supply.nothing', 'لا توجد كمية معتمدة؛ ارفض الطلب بدلًا من ذلك.', 'decisions');
            }
            foreach ($byBranch as $source => $lines) {
                $transfer = $this->transfers->create($source, $request->branch_id, $lines, 'تزويد بطلب '.$request->number, supplyRequestId: $request->id);
                $this->transfers->approve($transfer, selfApprove: true);
            }
            if ($toBuy !== []) {
                $supplier = $supplierId ? Supplier::findOrFail($supplierId) : throw new BusinessRuleException('supply.supplier', 'اختر المورد لما سيُشترى.', 'supplier_id');
                $order = $this->purchases->create($supplier, $toBuy, ['branch_id' => $request->branch_id, 'notes' => 'تزويد بطلب '.$request->number]);
                $request->purchase_order_id = $order->id;
            }
            $request->forceFill(['status' => SupplyRequest::APPROVED, 'reviewed_by' => Auth::guard('web')->id(), 'reviewed_at' => now()])->save();
            $this->audit->log('supply.approved', $request, ['transfers' => count($byBranch), 'purchase' => $toBuy !== []]);

            return $request;
        });
    }

    public function reject(SupplyRequest $request, string $reason): SupplyRequest
    {
        return $this->close($request, SupplyRequest::REJECTED, $reason, 'supply.rejected');
    }

    public function cancel(SupplyRequest $request, string $reason): SupplyRequest
    {
        return $this->close($request, SupplyRequest::CANCELLED, $reason, 'supply.cancelled');
    }

    private function close(SupplyRequest $request, string $status, string $reason, string $event): SupplyRequest
    {
        return DB::transaction(function () use ($request, $status, $reason, $event) {
            $request = SupplyRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($request->status !== SupplyRequest::PENDING) {
                throw new BusinessRuleException('supply.status', 'الطلب '.$request->number.' رُوجع مسبقًا.');
            }
            $request->forceFill(['status' => $status, 'reason' => $reason, 'reviewed_by' => Auth::guard('web')->id(), 'reviewed_at' => now()])->save();
            $this->audit->log($event, $request, ['reason' => $reason]);

            return $request;
        });
    }
}
