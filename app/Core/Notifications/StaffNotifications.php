<?php

namespace App\Core\Notifications;

use App\Core\Auth\PermissionRegistry;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Documents\Models\DocumentAlert;
use App\Modules\Finance\Models\Payment;
use App\Modules\Integrations\Services\IntegrationHub;
use App\Modules\Inventory\Http\Controllers\SalesController;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Website\Models\ContactMessage;
use Carbon\CarbonImmutable;

/**
 * What the bell in the panel's top bar lists: things already waiting in the club's own screens, read as they are —
 * no new rules. Each source is shown only to whoever may act on it (its permission, its module, the documents they
 * may see, the orders of their branch), and disappears once it is handled there.
 *
 *   document alerts not yet looked at · new website orders · payments waiting for confirmation ·
 *   unread WhatsApp chats · new contact-form messages · integrations in error
 */
final class StaffNotifications
{
    private const LIMIT = 8;

    /** @return array{count: int, items: list<array<string, mixed>>} */
    public function for(User $user): array
    {
        $items = [];
        $today = CarbonImmutable::today();

        if ($user->can('documents.view')) {
            $visible = BusinessDocument::visibleTo($user)->where('status', 'active')->select('id');
            foreach (DocumentAlert::with('document:id,name,expiry_date')->whereNull('acknowledged_at')->whereIn('document_id', $visible)->latest('id')->limit(self::LIMIT)->get() as $alert) {
                $days = $alert->document?->daysLeft($today);
                $items[] = [
                    'key' => 'document-alert-'.$alert->id,
                    'kind' => 'document',
                    'count' => 1,
                    'text' => $days === null || $days < 0
                        ? 'انتهى المستند «'.$alert->document?->name.'»'
                        : 'المستند «'.$alert->document?->name.'» ينتهي '.($days === 0 ? 'اليوم' : 'خلال '.$days.' يوم'),
                    'time' => $alert->created_at->locale('ar')->diffForHumans(),
                    'url' => route('club.business-documents.index', ['view' => $days !== null && $days < 0 ? 'expired' : 'expiring']),
                    // Looking at it is the existing «acknowledge» of the documents section.
                    'dismiss' => route('club.business-documents.alerts.acknowledge', $alert),
                ];
            }
        }

        if (PermissionRegistry::moduleEnabled('sales') && $user->can('orders.manage')) {
            $orders = SalesController::scopedFor($user)->where('source', Sale::WEBSITE)->where('fulfillment', 'new')
                ->whereNotIn('status', [Sale::CANCELLED, Sale::VOIDED])->count();
            $this->count($items, 'orders', $orders, $orders === 1 ? 'طلب جديد من الموقع بانتظار التجهيز' : $orders.' طلبات جديدة من الموقع بانتظار التجهيز', route('club.sales.orders.index'));
        }

        if ($user->can('payments.confirm')) {
            $pending = Payment::where('status', Payment::PENDING)->count();
            $this->count($items, 'payments', $pending, $pending === 1 ? 'دفعة بانتظار التأكيد' : $pending.' دفعات بانتظار التأكيد', route('club.payments.index', ['status' => Payment::PENDING]));
        }

        if ($user->can('whatsapp.inbox')) {
            $unread = (int) Conversation::where('status', 'open')->sum('unread_count');
            $this->count($items, 'inbox', $unread, $unread === 1 ? 'رسالة واتساب غير مقروءة' : $unread.' رسائل واتساب غير مقروءة', route('club.inbox.index'));
        }

        if ($user->can('website.manage')) {
            $messages = ContactMessage::where('status', 'new')->count();
            $this->count($items, 'contact', $messages, $messages === 1 ? 'رسالة جديدة من نموذج التواصل' : $messages.' رسائل جديدة من نموذج التواصل', route('club.website.messages'));
        }

        if ($user->can('integrations.manage') || $user->can('devices.manage')) {
            $hub = app(IntegrationHub::class);
            $rows = $user->can('integrations.manage') ? TenantIntegration::all()->keyBy('provider') : collect();
            $states = array_filter([
                'Paymob' => $user->can('integrations.manage') ? [$hub->paymob($rows->get(TenantIntegration::PAYMOB)), 'club.integrations.paymob'] : null,
                'WhatsApp Business' => $user->can('integrations.manage') ? [$hub->whatsapp($rows->get(TenantIntegration::WHATSAPP)), 'club.integrations.whatsapp'] : null,
                'أجهزة البصمة والبوابات' => $user->can('devices.manage') ? [$hub->devices(), 'club.devices.index'] : null,
            ]);
            foreach ($states as $name => [$status, $route]) {
                if ($status['state'] === IntegrationHub::ERROR) {
                    $this->count($items, 'integration-'.$route, 1, 'خطأ في '.$name.': '.$status['detail'], route($route));
                }
            }
        }

        return [
            'count' => array_sum(array_column($items, 'count')),
            'items' => array_slice($items, 0, self::LIMIT),
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function count(array &$items, string $key, int $count, string $text, string $url): void
    {
        if ($count > 0) {
            $items[] = ['key' => $key, 'kind' => 'count', 'count' => $count, 'text' => $text, 'time' => null, 'url' => $url, 'dismiss' => null];
        }
    }
}
