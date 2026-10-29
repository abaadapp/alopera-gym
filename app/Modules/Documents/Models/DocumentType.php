<?php

namespace App\Modules\Documents\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentType extends Model
{
    use BelongsToTenant;

    /** Starter types a club gets the first time it opens the section; it can rename, add or retire them. */
    public const DEFAULTS = [
        ['السجل التجاري', 12], ['الترخيص البلدي', 12], ['وثيقة التأمين', 12], ['شهادة الدفاع المدني والسلامة', 12],
        ['عقد الإيجار', 12], ['شهادات الموظفين', null], ['أخرى', null],
    ];

    protected $table = 'gym_document_types';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'renewal_months' => 'integer'];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(BusinessDocument::class, 'document_type_id');
    }

    public static function ensureDefaults(): void
    {
        if (self::query()->exists()) {
            return;
        }
        foreach (self::DEFAULTS as [$name, $months]) {
            self::create(['name' => $name, 'renewal_months' => $months]);
        }
    }
}
