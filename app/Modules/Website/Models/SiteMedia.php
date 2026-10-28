<?php

namespace App\Modules\Website\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class SiteMedia extends Model
{
    use BelongsToTenant;

    protected $table = 'site_media';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['in_gallery' => true, 'sort' => 0];

    protected function casts(): array
    {
        return ['in_gallery' => 'boolean'];
    }
}
