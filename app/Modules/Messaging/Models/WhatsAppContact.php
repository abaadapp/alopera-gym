<?php

namespace App\Modules\Messaging\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A contact as saved in the WhatsApp Business app on the owner's phone (smb_app_state_sync), kept only when the
 * club has allowed the import. It names a chat; it never creates a member or a conversation.
 */
class WhatsAppContact extends Model
{
    use BelongsToTenant;

    protected $table = 'whatsapp_contacts';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['removed_at' => 'datetime'];
    }
}
