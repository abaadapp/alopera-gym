<?php

namespace App\Core\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as owned by a club.
 *
 * - Reads are filtered by {@see TenantScope}.
 * - New rows take the tenant from context; writing a row for a different
 *   tenant than the one in context is refused.
 * - tenant_id can never be changed after creation.
 *
 * @property int $tenant_id
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);

            if (empty($model->tenant_id)) {
                $model->tenant_id = $context->require()->id;

                return;
            }

            if ($context->check() && ! $context->bypassed() && (int) $model->tenant_id !== $context->id()) {
                throw new CrossTenantWriteException(sprintf(
                    'Refusing to create %s for tenant %d while acting as tenant %d.',
                    $model::class, $model->tenant_id, $context->id()
                ));
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new CrossTenantWriteException('tenant_id cannot be changed on '.$model::class);
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
