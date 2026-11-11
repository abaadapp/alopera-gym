<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * A scheduled run that dies holding its overlap lock (a reboot, an out-of-memory kill) blocks that job only until the
 * lock runs out: a little longer than the job's normal run, never Laravel's default of a whole day. Unpaid shop orders
 * are released, campaigns go out and reminders are sent again within hours.
 */
class ScheduledJobLockExpiryTest extends TestCase
{
    /** Left exactly as it is (the owner's decision on backups): not part of this rule. */
    private const UNCHANGED = ['backup:tenants'];

    public function test_a_run_killed_while_holding_its_lock_does_not_block_the_job_for_a_day(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn (Event $e) => $e->withoutOverlapping)
            ->reject(fn (Event $e) => collect(self::UNCHANGED)->contains(fn ($c) => str_contains((string) $e->command, $c)));
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $event->mutex->forget($event);
            $this->assertTrue($event->mutex->create($event), 'precondition: '.$event->command.' takes its lock');

            // The process is killed before it lets the lock go; three hours later the scheduler is due to run it again and
            // takes the lock as it does before every run (Event::run → mutex->create): refused while the dead run's lasts.
            $this->travel(3)->hours();
            $runs = $event->mutex->create($event);
            $this->travelBack();
            $event->mutex->forget($event);

            $this->assertTrue($runs, $event->command.' is still skipped 3 hours after a killed run (its lock lasts '.$event->expiresAt.' minutes)');
        }
    }
}
