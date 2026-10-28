<?php

namespace App\Modules\Website\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SiteSetting extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['is_published' => true];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'working_hours' => 'array',
            'social_links' => 'array',
            'stats' => 'array',
        ];
    }

    public static function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
