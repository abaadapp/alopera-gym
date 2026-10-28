<?php

namespace App\Modules\Messaging;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Messaging\Gateways\LogGateway;
use App\Modules\Messaging\Gateways\MetaCloudGateway;
use App\Modules\Messaging\Gateways\WhatsAppGateway;
use App\Modules\Messaging\Listeners\SendDomainNotifications;
use Illuminate\Support\Facades\Event;

class MessagingServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    public function register(): void
    {
        $this->app->bind(WhatsAppGateway::class, fn () => match (config('services.whatsapp.driver')) {
            'meta' => new MetaCloudGateway,
            default => new LogGateway,
        });
    }

    protected function bootModule(): void
    {
        Event::subscribe(SendDomainNotifications::class);
    }
}
