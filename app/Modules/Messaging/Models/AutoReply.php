<?php

namespace App\Modules\Messaging\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AutoReply extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id', 'hits'];

    protected $attributes = ['is_active' => true, 'match_type' => 'contains', 'hits' => 0];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function matches(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        $keyword = mb_strtolower(trim($this->keyword));

        return $this->match_type === 'exact' ? $text === $keyword : str_contains($text, $keyword);
    }
}
