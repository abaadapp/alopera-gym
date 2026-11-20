<?php

namespace App\Modules\Payments\Models;

use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * A payment link made from the Paymob page. Its money, checkout and callback are its invoice's (OnlinePaymentService);
 * the link adds only who made it, until when it opens a payment page, and its cancellation.
 */
class PaymentLink extends Model implements BranchOwned
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    /** A payment of its invoice waits for the bank's final word (a signed «pending», or a payment awaiting confirmation). */
    public const PROCESSING = 'processing';

    /** Paymob money on its invoice that did not match is under review (PR #216): settled only with Paymob's evidence. */
    public const REVIEW = 'review';

    /** How long a new link opens a payment page, in days: what the form offers. */
    public const VALID_DAYS = [1, 3, 7, 30];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What the log shows, from the invoice as the books hold it: paid once nothing is owed, cancelled once the link (or
     * its invoice) is cancelled, expired once its time is over with money still owed, and waiting otherwise.
     */
    /**
     * Where the link stands. Cancelled stays cancelled (cancelling ends the link only; its invoice may be paid later
     * by other means), then the invoice's own state: paid, money under review, money at the bank, past its time, or
     * waiting. $known passes what the log already read for many links at once.
     *
     * @param  array{review?: bool, processing?: bool}  $known
     */
    public function status(array $known = []): string
    {
        $invoice = $this->invoice;
        if ($this->cancelled_at !== null || $invoice === null || $invoice->status === Invoice::VOID) {
            return self::CANCELLED;
        }
        if ($invoice->status === Invoice::PAID) {
            return self::PAID;
        }
        if ($known['review'] ?? PaymentReview::where('invoice_id', $invoice->id)->open()->exists()) {
            return self::REVIEW;
        }
        if ($known['processing'] ?? self::processing([$invoice->id])->isNotEmpty()) {
            return self::PROCESSING;
        }

        return $invoice->isOpen() && ! Money::isZero($invoice->balance()) && $this->expires_at->isPast() ? self::EXPIRED : self::PENDING;
    }

    /**
     * Of these invoices, those with money at the bank: a payment awaiting confirmation, or an attempt with a signed
     * «pending» callback and no final result yet (OnlinePaymentService::unresolvedAtBank, for many at once).
     *
     * @param  list<int>  $invoiceIds
     * @return Collection<int, int>
     */
    public static function processing(array $invoiceIds): Collection
    {
        if ($invoiceIds === []) {
            return collect();
        }
        $awaiting = Payment::whereIn('invoice_id', $invoiceIds)->where('status', Payment::PENDING)->pluck('invoice_id');
        $atBank = PaymentIntent::whereIn('invoice_id', $invoiceIds)->where('status', PaymentIntent::PENDING)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('payment_webhook_events')
                ->whereColumn('payment_webhook_events.tenant_id', 'payment_intents.tenant_id')
                ->whereColumn('payment_webhook_events.reference', 'payment_intents.reference')
                ->where('payment_webhook_events.outcome', 'pending')->where('payment_webhook_events.signature_valid', true))
            ->pluck('invoice_id');

        return $awaiting->merge($atBank)->map(fn ($id) => (int) $id)->unique()->values();
    }

    public function owningBranchId(): ?int
    {
        $branch = Invoice::whereKey($this->invoice_id)->value('branch_id');

        return $branch ? (int) $branch : null;
    }

    /** Can it still open a payment page? Not cancelled and not past its time. */
    public function opensCheckout(): bool
    {
        return $this->cancelled_at === null && $this->expires_at->isFuture();
    }
}
