<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A SaaS plan a club subscribes to. Not a gym membership plan. */
class PlatformPlan extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['is_active' => true, 'trial_days' => 14, 'currency' => 'OMR', 'sort' => 0];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:3',
            'price_yearly' => 'decimal:3',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function priceFor(string $cycle): string
    {
        return $cycle === TenantSubscription::YEARLY ? $this->price_yearly : $this->price_monthly;
    }
}
