<?php

namespace App\Modules\Inventory\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\NumberSequence;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Stocktake;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A branch's stock work: one-off adjustments (damaged, lost, expired, internal use, a correction) and stock counts.
 * A count is a document: counted while a draft, then approved once by someone allowed to — each difference is
 * posted as an adjustment movement with the count's number. Nothing here edits a movement.
 */
final class InventoryService
{
    /** Reasons that can only take stock away. */
    public const OUT_ONLY = ['damaged', 'lost', 'expired', 'internal'];

    /** pg_advisory_xact_lock key space (with the branch id): starting and approving counts in one branch, one at a time. */
    public const COUNT_LOCKS = 74204;

    public function __construct(private readonly StockService $stock, private readonly AuditLogger $audit) {}

    /**
     * Record an adjustment on a branch's shelf.
     *
     * @param  float  $quantity  signed; damaged/lost/expired/internal always take away
     */
    public function adjust(Product $product, int $branchId, float $quantity, string $reason, ?string $note = null): StockMovement
    {
        if (! isset(StockService::REASONS[$reason]) || $reason === 'opening') {
            throw new BusinessRuleException('stock.reason', 'اختر سبب التعديل.', 'reason');
        }
        if (! $product->track_stock) {
            throw new BusinessRuleException('stock.untracked', '«'.$product->name.'» غير مربوط بالمخزون.', 'product_id');
        }
        $quantity = in_array($reason, self::OUT_ONLY, true) ? -abs($quantity) : $quantity;
        if ($quantity == 0.0) {
            throw new BusinessRuleException('stock.zero', 'أدخل الكمية.', 'quantity');
        }

        return DB::transaction(function () use ($product, $branchId, $quantity, $reason, $note) {
            $movement = $this->stock->move($product, $quantity, 'adjustment', null, $note, branchId: $branchId, reason: $reason, document: NumberSequence::next('stock_adjustment'));
            $this->audit->log('stock.adjusted', $product, ['branch' => $branchId, 'quantity' => $quantity, 'reason' => $reason, 'document' => $movement->document]);

            return $movement;
        });
    }

    /**
     * Start a count of a branch: the products chosen (all its stocked products if none), each with the branch's
     * book quantity now.
     *
     * @param  list<int>  $productIds
     */
    public function startCount(int $branchId, array $productIds = [], ?string $notes = null): Stocktake
    {
        return DB::transaction(function () use ($branchId, $productIds, $notes) {
            // Two starts at once in one branch: the second waits here, then sees the first one's open count below.
            $this->lockCounts($branchId);
            $products = Product::where('track_stock', true)->when($productIds !== [], fn ($q) => $q->whereIn('id', $productIds))->orderBy('name')->get();
            if ($products->isEmpty()) {
                throw new BusinessRuleException('stocktake.empty', 'لا توجد منتجات مربوطة بالمخزون لجردها.', 'products');
            }
            // One open count per product and branch: two would each post the same difference.
            $open = Stocktake::where('branch_id', $branchId)->where('status', Stocktake::DRAFT)
                ->whereHas('lines', fn ($q) => $q->whereIn('product_id', $products->modelKeys()))->value('number');
            if ($open) {
                throw new BusinessRuleException('stocktake.open', 'يوجد جرد مفتوح لهذه المنتجات في هذا الفرع ('.$open.'): اعتمده أو ألغه أولًا.', 'products');
            }
            $count = new Stocktake(['branch_id' => $branchId, 'number' => NumberSequence::next('stocktake'), 'notes' => $notes, 'created_by' => Auth::guard('web')->id()]);
            $count->forceFill(['status' => Stocktake::DRAFT])->save();
            $books = $this->stock->quantities($branchId);
            foreach ($products as $product) {
                $count->lines()->create(['product_id' => $product->id, 'system_quantity' => $books[$product->id] ?? 0, 'unit_cost' => $product->cost]);
            }

            return $count;
        });
    }

    /**
     * Save what was counted while the count is a draft.
     *
     * @param  array<int, array{counted?: float|string|null, reason?: string|null, note?: string|null}>  $counts  by line id
     */
    public function saveCounts(Stocktake $count, array $counts): Stocktake
    {
        return DB::transaction(function () use ($count, $counts) {
            $count = $this->lockDraft($count);
            $books = $this->stock->quantities($count->branch_id);
            foreach ($count->lines()->get() as $line) {
                if (! array_key_exists($line->id, $counts)) {
                    continue;
                }
                $entry = $counts[$line->id];
                $counted = ($entry['counted'] ?? null) === null || $entry['counted'] === '' ? null : round((float) $entry['counted'], 3);
                if ($counted !== null && $counted < 0) {
                    throw new BusinessRuleException('stocktake.negative', 'الكمية المعدودة لا تكون سالبة.', 'counts');
                }
                // The book is the one seen when this quantity was counted: saved again unchanged (as approving does,
                // with every line), it is kept, or the sales made since the count would be added back to the stock.
                $unchanged = $counted !== null && $line->counted_quantity !== null && round((float) $line->counted_quantity, 3) === $counted;
                $system = $unchanged ? (float) $line->system_quantity : (float) ($books[$line->product_id] ?? 0);
                $line->update([
                    'system_quantity' => $system,
                    'counted_quantity' => $counted,
                    'difference' => $counted === null ? 0 : round($counted - $system, 3),
                    'reason' => isset(StockService::REASONS[$entry['reason'] ?? '']) ? $entry['reason'] : 'count',
                    'note' => $entry['note'] ?? null,
                ]);
            }

            return $count;
        });
    }

    /**
     * Approve the count: each counted line's difference from the book at the time it was counted is posted once onto
     * today's balance, so what was sold or received since the count stays counted. Approving it a second time is refused.
     */
    public function approveCount(Stocktake $count): Stocktake
    {
        return DB::transaction(function () use ($count) {
            $this->lockCounts($count->branch_id);
            $count = $this->lockDraft($count);
            $lines = $count->lines()->with('product')->whereNotNull('counted_quantity')->get();
            if ($lines->isEmpty()) {
                throw new BusinessRuleException('stocktake.nothing', 'لم تُدخل أي كمية معدودة بعد.', 'counts');
            }
            // Another count of the same products in this branch approved after this one began has already posted the
            // differences this one measured against the same book: approving this one too would post them twice.
            // (Two such counts could be open together before one open count per product and branch was enforced.)
            $clash = Stocktake::where('branch_id', $count->branch_id)->whereKeyNot($count->id)->where('status', Stocktake::APPROVED)
                ->where('approved_at', '>', $count->created_at)
                ->whereHas('lines', fn ($q) => $q->whereIn('product_id', $lines->pluck('product_id'))->whereNotNull('counted_quantity'))
                ->orderBy('approved_at')->value('number');
            if ($clash) {
                throw new BusinessRuleException('stocktake.overlap', 'الجرد '.$clash.' لنفس المنتجات في هذا الفرع اعتُمد بعد بدء هذا الجرد، فاعتماد هذا يكرر تسوية المخزون. ألغِ الجرد '.$count->number.' وابدأ جردًا جديدًا.', 'counts');
            }
            $this->stock->lockInOrder($lines->pluck('product_id'));
            foreach ($lines as $line) {
                $difference = round((float) $line->counted_quantity - (float) $line->system_quantity, 3);
                $line->update(['difference' => $difference, 'unit_cost' => $line->product->cost]);
                if ($difference != 0.0) {
                    $this->stock->move($line->product, $difference, 'adjustment', $count, $line->note ?: 'جرد '.$count->number, allowNegative: true, branchId: $count->branch_id, reason: $line->reason ?: 'count');
                }
            }
            $count->forceFill(['status' => Stocktake::APPROVED, 'approved_by' => Auth::guard('web')->id(), 'approved_at' => now()])->save();
            $this->audit->log('stocktake.approved', $count, ['lines' => $lines->count(), 'differences' => $lines->where('difference', '!=', 0)->count()]);

            return $count;
        });
    }

    public function cancelCount(Stocktake $count): Stocktake
    {
        return DB::transaction(function () use ($count) {
            $count = $this->lockDraft($count);
            $count->forceFill(['status' => Stocktake::CANCELLED])->save();

            return $count;
        });
    }

    private function lockCounts(int $branchId): void
    {
        DB::select('select pg_advisory_xact_lock(?, ?)', [self::COUNT_LOCKS, $branchId]);
    }

    private function lockDraft(Stocktake $count): Stocktake
    {
        $count = Stocktake::whereKey($count->id)->lockForUpdate()->firstOrFail();
        if ($count->status !== Stocktake::DRAFT) {
            throw new BusinessRuleException('stocktake.closed', 'الجرد '.$count->number.' '.($count->status === Stocktake::APPROVED ? 'معتمد مسبقًا' : 'ملغى').'؛ لا يمكن تغييره.');
        }

        return $count;
    }
}
