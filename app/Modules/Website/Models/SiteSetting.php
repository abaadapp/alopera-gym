<?php

namespace App\Modules\Website\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\ImageStore;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SiteSetting extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    /** The content documents are logged by SiteContent as short events (saved, published, restored), not copied whole. */
    protected array $auditExclude = ['draft', 'published', 'draft_version', 'published_draft_version', 'draft_saved_at', 'draft_saved_by', 'content_published_at', 'content_published_by'];

    protected $attributes = ['is_published' => true];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sections' => 'array',
            'working_hours' => 'array',
            'social_links' => 'array',
            'stats' => 'array',
            'features' => 'array',
            'draft' => 'array',
            'published' => 'array',
            'draft_version' => 'integer',
            'published_draft_version' => 'integer',
            'draft_saved_at' => 'datetime',
            'content_published_at' => 'datetime',
        ];
    }

    /** The public address of a file on the public disk. */
    public static function url(?string $path): ?string
    {
        return ImageStore::url($path);
    }
}
