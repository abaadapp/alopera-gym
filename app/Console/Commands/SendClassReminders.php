<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Messaging\Services\MemberNotifier;

/**
 * A WhatsApp reminder before each booked class (setting class_reminder_hours; 0 = off). Sent once per booking
 * (dedupe key), and only for a booking made before the reminder window opened — someone who books an hour before
 * the class already has the booking confirmation.
 */
class SendClassReminders extends ForEachTenant
{
    protected $signature = 'gym:class-reminders {--tenant=}';

    protected $description = 'Queue WhatsApp reminders before booked classes';

    protected function handleTenant(Tenant $tenant): string
    {
        $hours = (int) $tenant->setting('class_reminder_hours', 0);
        if ($hours <= 0) {
            return 'off';
        }

        $notifier = app(MemberNotifier::class);
        $sent = 0;
        Booking::with(['member', 'session.program'])
            ->where('status', Booking::BOOKED)
            ->whereHas('session', fn ($q) => $q->where('status', ClassSession::SCHEDULED)
                ->where('starts_at', '>', now())->where('starts_at', '<=', now()->addHours($hours)))
            ->each(function (Booking $booking) use ($notifier, $hours, &$sent) {
                if ($booking->created_at->greaterThan($booking->session->starts_at->subHours($hours))) {
                    return;
                }
                $notifier->classReminder($booking);
                $sent++;
            });

        return "class_reminders={$sent}";
    }
}
