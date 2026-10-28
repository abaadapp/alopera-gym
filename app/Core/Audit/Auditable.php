<?php

namespace App\Core\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Records create/update/delete of a model in the audit trail.
 *
 * Hidden attributes (passwords, tokens) and anything listed in
 * `$auditExclude` are never written to the log.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model): void {
            app(AuditLogger::class)->log(static::auditEvent($model, 'created'), $model, new: $model->auditableValues($model->getAttributes()));
        });

        static::updated(function (Model $model): void {
            $changes = $model->auditableValues($model->getChanges());
            unset($changes['updated_at']);

            if ($changes === []) {
                return;
            }

            $old = array_intersect_key($model->auditableValues($model->getOriginal()), $changes);
            app(AuditLogger::class)->log(static::auditEvent($model, 'updated'), $model, old: $old, new: $changes);
        });

        static::deleted(function (Model $model): void {
            app(AuditLogger::class)->log(static::auditEvent($model, 'deleted'), $model, old: $model->auditableValues($model->getAttributes()));
        });
    }

    protected static function auditEvent(Model $model, string $action): string
    {
        return str(class_basename($model))->snake().'.'.$action;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function auditableValues(array $values): array
    {
        $exclude = array_merge($this->getHidden(), $this->auditExclude ?? [], ['created_at']);

        return array_map(
            fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : $value,
            array_diff_key($values, array_flip($exclude))
        );
    }
}
