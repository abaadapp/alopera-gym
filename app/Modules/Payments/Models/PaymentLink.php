<?php

namespace App\Modules\Payments\Models;

use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * An invoice's current payment link (payment links v2, design B): one row per invoice (its unique key), live, past its
 * time, or cancelled — as the code before v2 reads it too. Made from the Paymob page (with its invoice), from an issued
 * invoice, or with the invoice from the editor. Its money, checkout and callback are its invoice's (OnlinePaymentService):
 * what was paid and what is left are the invoice's.
 *
 * A new link of the invoice takes this row's place (PaymentLinks::open): what the row held goes to PaymentLinkHistory
 * first, with how it ended (cancelled, or replaced once its time was over), and the row gets the new link's address,
 * time and creator. So one live link per invoice holds by the row itself, and every earlier link is kept. A v2 link has
 * its own secure address (token); a link made before has none and opens its invoice's pay page, as sent.
 */
class PaymentLink extends Model implements BranchOwned
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    /** Its time was over and a newer link of the invoice took its place (PaymentLinkHistory): it opens nothing any more. */
    public const REPLACED = 'replaced';

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
            'issued_at' => 'datetime',
            'paymob_checked' => 'array',
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

    public function history(): HasMany
    {
        return $this->hasMany(PaymentLinkHistory::class);
    }

    /** Not cancelled (it may be past its time: then a new link replaces it). */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    /** The invoice's link row (its current link), if it ever had one — read under the invoice's lock where it decides anything. */
    public static function currentFor(int $invoiceId): ?self
    {
        return self::where('invoice_id', $invoiceId)->first();
    }

    /** When this link was issued: a link made before v2 has no issued_at, its row's creation is its issue. */
    public function issuedAt(): Carbon
    {
        return $this->issued_at ?? $this->created_at;
    }

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
     * «pending» callback, or a signed authorization not captured yet, and no final result (whatever time has passed).
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
                ->whereIn('payment_webhook_events.outcome', ['pending', 'authorized_only'])->where('payment_webhook_events.signature_valid', true))
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

    /**
     * The Paymob pages of its invoice handed out while this link was the invoice's (from its address or the invoice's)
     * that brought no recorded payment: Paymob's references, for the staff to look up at Paymob before the link is
     * ended. Their time running out is not taken as proof that Paymob took nothing on them (owner's decision,
     * 2026-11-20): without Paymob's own word for each (an inquiry needs the API key, not used), the staff check it.
     *
     * @return list<string>
     */
    public function unansweredPages(): array
    {
        return PaymentIntent::where('invoice_id', $this->invoice_id)->whereNotNull('checkout_url')
            ->where('status', '!=', PaymentIntent::PAID)->where('created_at', '>=', $this->issuedAt())
            ->orderBy('id')->pluck('reference')->all();
    }
}
