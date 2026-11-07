<?php

namespace App\Modules\Messaging\Support;

use App\Core\Auth\SystemRoles;

/**
 * The WhatsApp permissions before the inbox was split up, read as the new ones. Nobody loses what they could do:
 * «صندوق المحادثات» (every chat, read and answered, and the message log) becomes every chat, replies and the reports;
 * «القوالب والردود الآلية» becomes templates and automation; whoever held the integrations keeps WhatsApp's settings.
 * Two starting roles follow what the owner asked for: reception sees the chats assigned to it and answers them; the
 * manager runs the chats and the team (assign, files, templates, automation, reports) but not the connection's keys.
 */
final class WhatsAppPermissionUpgrade
{
    public const RETIRED = ['whatsapp.inbox', 'templates.manage'];

    /**
     * @param  list<string>  $permissions  a stored list (a role's, or a person's own)
     * @return list<string>
     */
    public static function upgrade(array $permissions, ?string $roleKey): array
    {
        if (in_array('*', $permissions, true)) {
            return $permissions;
        }
        $has = fn (string $p) => in_array($p, $permissions, true);
        $add = [];

        if ($has('whatsapp.inbox')) {
            array_push($add, ...($roleKey === SystemRoles::RECEPTION
                ? ['whatsapp.view_assigned', 'whatsapp.reply']
                : ['whatsapp.view_all', 'whatsapp.reply', 'whatsapp.view_reports']));
        }
        if ($has('templates.manage')) {
            array_push($add, 'whatsapp.manage_templates', 'whatsapp.manage_automation');
        }
        if ($has('integrations.manage')) {
            $add[] = 'whatsapp.manage_settings';
        }
        if ($roleKey === SystemRoles::MANAGER) {
            array_push($add, 'whatsapp.view_all', 'whatsapp.reply', 'whatsapp.assign', 'whatsapp.send_media',
                'whatsapp.manage_templates', 'whatsapp.manage_automation', 'whatsapp.view_reports');
        }

        return array_values(array_unique([...array_diff($permissions, self::RETIRED), ...$add]));
    }
}
