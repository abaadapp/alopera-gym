<?php

namespace App\Modules\Payroll\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobTitle extends Model
{
    use BelongsToTenant;

    protected $table = 'gym_job_titles';

    protected $fillable = ['name', 'description', 'is_active'];

    protected $attributes = ['is_active' => true];

    /** A new club starts with the jobs a gym usually has. */
    public const DEFAULTS = ['مدرب', 'موظف استقبال', 'محاسب', 'مدير النادي', 'عامل نظافة'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Seed the defaults for a club that has no job titles yet. */
    public static function ensureDefaults(): void
    {
        if (! self::query()->exists()) {
            foreach (self::DEFAULTS as $name) {
                self::create(['name' => $name]);
            }
        }
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
