<?php

namespace App\Modules\Messaging\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A WhatsApp template as approved in Meta's WhatsApp Manager, mapped to the
 * event that sends it. `variables` lists which value fills {{1}}, {{2}}…
 */
class MessageTemplate extends Model
{
    use Auditable, BelongsToTenant;

    public const APPROVED = 'approved';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['is_active' => true, 'status' => 'draft', 'language' => 'ar', 'category' => 'utility'];

    protected function casts(): array
    {
        return ['variables' => 'array', 'is_active' => 'boolean'];
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->status === self::APPROVED;
    }

    /**
     * Render the preview body with actual values (for the message log).
     *
     * @param  list<string>  $params
     */
    public function render(array $params): string
    {
        $body = $this->body;
        foreach (array_values($params) as $i => $value) {
            $body = str_replace('{{'.($i + 1).'}}', $value, $body);
        }

        return $body;
    }
}
