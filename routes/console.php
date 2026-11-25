<?php

use App\Core\Support\SubmittedOnceKey;
use Illuminate\Support\Facades\Schedule;

/*
| Production cron: * * * * * php /var/www/alopera-gym/current/artisan schedule:run
| Times are in APP_TIMEZONE (Asia/Muscat).
| withoutOverlapping(n): the overlap lock lasts n minutes, a little longer than the job's normal run, so a run killed
| while holding it (a reboot, an out-of-memory kill) blocks the job for minutes, not Laravel's default of a whole day.
*/

Schedule::command('gym:daily-memberships')->dailyAt('00:10')->withoutOverlapping(120)->onOneServer();
Schedule::command('gym:sessions')->hourly()->withoutOverlapping(55)->onOneServer();
// Hourly: each club's WhatsApp reminders go out at the hour it set (10:00 unless changed).
Schedule::command('gym:send-reminders --due')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('finance:remind-dues')->dailyAt('10:30')->withoutOverlapping(120)->onOneServer();
Schedule::command('gym:class-reminders')->everyFifteenMinutes()->withoutOverlapping(14)->onOneServer();
Schedule::command('messaging:dispatch-scheduled')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('whatsapp:sync-templates')->everyTenMinutes()->withoutOverlapping(9)->onOneServer();
Schedule::command('accounting:sync')->dailyAt('02:30')->withoutOverlapping(120)->onOneServer();
Schedule::command('sales:expire-orders')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
// Bookings left on a cancelled membership (a crash after a cancellation or an upgrade's payment): finished within a minute.
Schedule::command('gym:settle-bookings')->everyMinute()->withoutOverlapping(10)->onOneServer();
Schedule::command('documents:alerts')->dailyAt('07:30')->withoutOverlapping(120)->onOneServer();
Schedule::command('notifications:sync')->hourly()->withoutOverlapping(55)->onOneServer();
// The scheduler's own lock lasts two hours, not its default day: a run killed half-way (the server restarted) must not
// shut out the next night's. Each club's backup is one at a time anyway (TenantBackupService's PostgreSQL lock).
Schedule::command('backup:tenants')->dailyAt('03:00')->withoutOverlapping(120)->onOneServer();
// An hour and a half after: a club with no archive from today and no result told is told the nightly did not run.
Schedule::command('backup:check-daily')->dailyAt('04:30')->withoutOverlapping(30)->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('model:prune', ['--model' => [SubmittedOnceKey::class]])->dailyAt('04:20')->withoutOverlapping(120)->onOneServer();
Schedule::command('whatsapp:prune-webhooks')->dailyAt('04:10')->withoutOverlapping(120)->onOneServer();
