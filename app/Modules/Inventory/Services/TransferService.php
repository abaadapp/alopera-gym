<?php

namespace App\Modules\Inventory\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\NumberSequence;
use App\Core\Tenancy\BranchContext;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockTransfer;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Stock moving between branches. It moves only twice: out of the sending branch when dispatched (it is then in
 * transit, on no shelf, so nothing is counted twice) and into the receiving branch when received. Every step locks
 * the transfer and checks its status again, so two hands on one transfer move the stock once. Approving, dispatching
 * and cancelling are for the sending branch; receiving and settling for the receiving branch. Whoever asked for a
 * transfer does not approve it, unless they are the club owner.
 */
final class TransferService
{
    public function __construct(private readonly StockService $stock, private readonly BranchContext $branches, private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{product_id: int, quantity: float|string}>  $lines
     * @param  bool  $direct  approve and dispatch at once (someone allowed to approve)
     */
    public function create(int $from, int $to, array $lines, ?string $notes = null, ?string $clientKey = null, bool $direct = false, ?int $supplyRequestId = null): StockTransfer
    {
        if ($from === $to) {
            throw new BusinessRuleException('transfer.same', 'اختر فرعين مختلفين للنقل.', 'to_branch_id');
        }
        $this->guard($from, 'لا يمكنك النقل من فرع غير فرعك.');
        if ($clientKey && ($existing = StockTransfer::where('client_key', $clientKey)->first())) {
            return $existing;
        }
        $lines = collect($lines)->filter(fn ($l) => (float) $l['quantity'] > 0)->groupBy('product_id')->map(fn ($rows, $id) => ['product_id' => (int) $id, 'quantity' => round((float) $rows->sum('quantity'), 3)])->values();
        if ($lines->isEmpty()) {
            throw new BusinessRuleException('transfer.empty', 'أضف منتجًا واحدًا على الأقل بكمية.', 'lines');
        }
        $tracked = Product::where('track_stock', true)->whereIn('id', $lines->pluck('product_id'))->pluck('id');
        if ($tracked->count() !== $lines->count()) {
            throw new BusinessRuleException('transfer.untracked', 'لا يُنقل إلا ما هو مربوط بالمخزون.', 'lines');
        }

        try {
            $transfer = DB::transaction(function () use ($from, $to, $lines, $notes, $clientKey, $supplyRequestId) {
                $transfer = StockTransfer::create([
                    'number' => NumberSequence::next('stock_transfer'), 'from_branch_id' => $from, 'to_branch_id' => $to, 'status' => StockTransfer::PENDING,
                    'notes' => $notes, 'requested_by' => Auth::guard('web')->id(), 'client_key' => $clientKey, 'supply_request_id' => $supplyRequestId,
                ]);
                foreach ($lines as $line) {
                    $transfer->lines()->create(['product_id' => $line['product_id'], 'requested_quantity' => $line['quantity']]);
                }

                return $transfer;
            });
        } catch (UniqueConstraintViolationException $e) {
            return StockTransfer::where('client_key', $clientKey)->first() ?? throw $e;
        }

        if ($direct) {
            $this->approve($transfer, selfApprove: true);
            $this->dispatch($transfer);
        }

        return $transfer->fresh();
    }

    /** @param  array<int, float|string>  $quantities  approved quantity by line id (default: as requested) */
    public function approve(StockTransfer $transfer, array $quantities = [], bool $selfApprove = false): StockTransfer
    {
        return $this->step($transfer, [StockTransfer::PENDING], 'from', function (StockTransfer $t, User $user) use ($quantities, $selfApprove) {
            if ((int) $t->requested_by === $user->id && ! ($selfApprove || $user->isOwner())) {
                throw new BusinessRuleException('transfer.self', 'لا يعتمد صاحب الطلب طلبه؛ يعتمده مسؤول آخر.');
            }
            foreach ($t->lines as $line) {
                $line->update(['approved_quantity' => round(max(0, (float) ($quantities[$line->id] ?? $line->requested_quantity)), 3)]);
            }
            if ($t->lines->every(fn ($l) => (float) $l->approved_quantity == 0.0)) {
                throw new BusinessRuleException('transfer.zero', 'الكميات المعتمدة كلها صفر؛ ارفض الطلب بدلًا من ذلك.');
            }
            $t->forceFill(['status' => StockTransfer::APPROVED, 'approved_by' => $user->id, 'approved_at' => now()])->save();
        }, 'transfer.approved');
    }

    /** The goods leave the sending branch: its shelf goes down by what is sent; nothing is short-shipped silently. */
    public function dispatch(StockTransfer $transfer): StockTransfer
    {
        return $this->step($transfer, [StockTransfer::APPROVED], 'from', function (StockTransfer $t, User $user) {
            foreach ($t->lines()->with('product')->get() as $line) {
                $qty = (float) $line->approved_quantity;
                if ($qty <= 0) {
                    continue;
                }
                $this->stock->move($line->product, -$qty, 'transfer_out', $t, 'إلى '.$t->toBranch?->name, branchId: $t->from_branch_id);
                $line->update(['sent_quantity' => $qty, 'unit_cost' => $line->product->fresh()->cost]);
            }
            $t->forceFill(['status' => StockTransfer::IN_TRANSIT, 'dispatched_by' => $user->id, 'dispatched_at' => now()])->save();
        }, 'transfer.dispatched');
    }

    /**
     * The goods arrive: what came in goes on the receiving shelf. Less than sent leaves the transfer «received
     * short» until each missing quantity is settled; more than sent is refused.
     *
     * @param  array<int, float|string>  $quantities  received by line id (default: as sent)
     */
    public function receive(StockTransfer $transfer, array $quantities = [], ?string $note = null): StockTransfer
    {
        return $this->step($transfer, [StockTransfer::IN_TRANSIT], 'to', function (StockTransfer $t, User $user) use ($quantities, $note) {
            foreach ($t->lines()->with('product')->get() as $line) {
                $qty = round((float) ($quantities[$line->id] ?? $line->sent_quantity), 3);
                if ($qty < 0 || $qty > (float) $line->sent_quantity) {
                    throw new BusinessRuleException('transfer.over', 'لا يُستلم من «'.$line->product->name.'» أكثر مما أُرسل ('.(float) $line->sent_quantity.').', 'quantities');
                }
                if ($qty > 0) {
                    $this->stock->move($line->product, $qty, 'transfer_in', $t, 'من '.$t->fromBranch?->name, unitCost: null, branchId: $t->to_branch_id);
                }
                $line->update(['received_quantity' => $qty]);
            }
            $short = $t->lines()->get()->contains(fn ($l) => $l->outstanding() > 0);
            $t->forceFill(['status' => $short ? StockTransfer::DISCREPANCY : StockTransfer::RECEIVED, 'received_by' => $user->id, 'received_at' => now(), 'notes' => trim($t->notes."\n".$note) ?: null])->save();
        }, 'transfer.received');
    }

    /**
     * Settle what did not arrive on a line: lost (written off; it already left the sending shelf), returned to the
     * sending branch, or arrived late (onto the receiving shelf). Closes the transfer when nothing is missing.
     */
    public function resolve(StockTransfer $transfer, int $lineId, string $outcome, float $quantity, string $reason): StockTransfer
    {
        if (! in_array($outcome, ['lost', 'returned', 'late'], true) || $quantity <= 0) {
            throw new BusinessRuleException('transfer.resolve', 'اختر ما حدث للكمية الناقصة وأدخل الكمية.', 'outcome');
        }

        return $this->step($transfer, [StockTransfer::DISCREPANCY], $outcome === 'returned' ? 'from' : 'to', function (StockTransfer $t, User $user) use ($lineId, $outcome, $quantity, $reason) {
            $line = $t->lines()->with('product')->whereKey($lineId)->lockForUpdate()->firstOrFail();
            if ($quantity > $line->outstanding()) {
                throw new BusinessRuleException('transfer.resolve_over', 'الكمية أكبر من الناقص ('.$line->outstanding().').', 'quantity');
            }
            match ($outcome) {
                'returned' => $this->stock->move($line->product, $quantity, 'transfer_return', $t, 'عاد من النقل: '.$reason, branchId: $t->from_branch_id),
                'late' => $this->stock->move($line->product, $quantity, 'transfer_in', $t, 'وصل متأخرًا: '.$reason, branchId: $t->to_branch_id),
                'lost' => null,
            };
            $line->update([$outcome.'_quantity' => round((float) $line->{$outcome.'_quantity'} + $quantity, 3), 'note' => trim(($line->note ? $line->note.' · ' : '').$reason)]);
            if ($t->lines()->get()->every(fn ($l) => $l->outstanding() <= 0)) {
                $t->forceFill(['status' => StockTransfer::RECEIVED, 'closed_by' => $user->id, 'closed_at' => now()])->save();
            }
        }, 'transfer.resolved_'.$outcome);
    }

    public function reject(StockTransfer $transfer, string $reason): StockTransfer
    {
        return $this->step($transfer, [StockTransfer::PENDING], 'from', fn (StockTransfer $t, User $user) => $t->forceFill(['status' => StockTransfer::REJECTED, 'reason' => $reason, 'closed_by' => $user->id, 'closed_at' => now()])->save(), 'transfer.rejected');
    }

    /** Before dispatch only: nothing has moved yet. */
    public function cancel(StockTransfer $transfer, string $reason): StockTransfer
    {
        return $this->step($transfer, [StockTransfer::PENDING, StockTransfer::APPROVED], 'from', fn (StockTransfer $t, User $user) => $t->forceFill(['status' => StockTransfer::CANCELLED, 'reason' => $reason, 'closed_by' => $user->id, 'closed_at' => now()])->save(), 'transfer.cancelled');
    }

    /** Lock, check the status again, check the branch, do the step, log it. */
    private function step(StockTransfer $transfer, array $from, string $side, Closure $work, string $event): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $from, $side, $work, $event) {
            $t = StockTransfer::with(['lines', 'fromBranch:id,name', 'toBranch:id,name'])->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if (! in_array($t->status, $from, true)) {
                throw new BusinessRuleException('transfer.status', 'سند النقل '.$t->number.' حالته الآن «'.(StockTransfer::LABELS[$t->status] ?? $t->status).'»؛ لا يمكن تنفيذ هذه الخطوة.');
            }
            $this->guard($side === 'from' ? $t->from_branch_id : $t->to_branch_id, $side === 'from' ? 'هذه الخطوة للفرع المرسل.' : 'الاستلام للفرع المستقبل.');
            $user = Auth::guard('web')->user();
            $work($t, $user);
            $this->audit->log($event, $t, ['status' => $t->status]);

            return $t->fresh(['lines']);
        });
    }

    private function guard(int $branch, string $message): void
    {
        $user = Auth::guard('web')->user();
        if ($user instanceof User && ! $this->branches->allows($user, $branch)) {
            throw new BusinessRuleException('transfer.branch', $message);
        }
    }
}
