<?php

use Illuminate\Support\Facades\Schedule;

/*
| Production cron: * * * * * php /var/www/alopera-gym/current/artisan schedule:run
| Times are in APP_TIMEZONE (Asia/Muscat).
*/

Schedule::command('gym:daily-memberships')->dailyAt('00:10')->withoutOverlapping()->onOneServer();
Schedule::command('gym:sessions')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('gym:send-reminders')->dailyAt('10:00')->withoutOverlapping()->onOneServer();
Schedule::command('gym:class-reminders')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('messaging:dispatch-scheduled')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('platform:billing')->dailyAt('01:00')->withoutOverlapping()->onOneServer();
Schedule::command('accounting:sync')->dailyAt('02:30')->withoutOverlapping()->onOneServer();
Schedule::command('sales:expire-orders')->everyFiveMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('documents:alerts')->dailyAt('07:30')->withoutOverlapping()->onOneServer();
Schedule::command('backup:tenants')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
Schedule::command('queue:prune-failed --hours=720')->daily();
