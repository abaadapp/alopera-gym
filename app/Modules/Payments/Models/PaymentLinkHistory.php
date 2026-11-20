<?php

namespace App\Modules\Payments\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An earlier link of an invoice (payment links v2, design B): what its payment_links row held when a new link took the
 * row's place — its address, what it asked, when it was issued, until when it ran, and how it ended (cancelled or
 * replaced). Written once, never changed, never deleted. Its address opens no payment page, ever; money Paymob took on
 * a page opened from it is matched by the page's own reference (PaymentIntent → invoice), not by the link.
 */
class PaymentLinkHistory extends Model implements BranchOwned
{
    use BelongsToTenant;

    protected $table = 'payment_link_history';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'replaced_at' => 'datetime',
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

    /** cancelled or replaced: how it ended. */
    public function status(): string
    {
        return $this->cancelled_at !== null ? PaymentLink::CANCELLED : PaymentLink::REPLACED;
    }

    public function owningBranchId(): ?int
    {
        $branch = Invoice::whereKey($this->invoice_id)->value('branch_id');

        return $branch ? (int) $branch : null;
    }
}
