<?php

namespace App\Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money paid to a supplier against a bill, from a cash box or bank account. Reversed, never edited or deleted. */
class SupplierPayment extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:3', 'paid_on' => 'immutable_date', 'reversed_at' => 'datetime'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
