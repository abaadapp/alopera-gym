<?php

namespace App\Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductCategory extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
