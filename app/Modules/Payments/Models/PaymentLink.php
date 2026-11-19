<?php

namespace App\Modules\Payments\Models;

use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    public function status(): string
    {
        $invoice = $this->invoice;

        return match (true) {
            $invoice !== null && $invoice->status === Invoice::PAID => self::PAID,
            $this->cancelled_at !== null || $invoice === null || $invoice->status === Invoice::VOID => self::CANCELLED,
            $invoice->isOpen() && ! Money::isZero($invoice->balance()) && $this->expires_at->isPast() => self::EXPIRED,
            default => self::PENDING,
        };
    }

    /** Its invoice's branch: a user tied to another branch gets 404 for the link, as for the invoice. */
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
