<?php

namespace App\Console\Commands;

use App\Core\Demo\DemoMirror;
use App\Core\Tenancy\HomeGym;
use Illuminate\Console\Command;

/**
 * Keeps the demo club in step with the gym's setup (App\Core\Demo\DemoMirror): every five minutes from the scheduler,
 * one way, gym to demo. Does nothing while no demo club is set (GYM_DEMO_CLUB) or built.
 */
class SyncDemoClub extends Command
{
    protected $signature = 'gym:demo-sync';

    protected $description = 'Copy the gym\'s setup (settings, website, plans, prices, products, templates) to the demo club';

    public function handle(DemoMirror $mirror): int
    {
        $gym = HomeGym::tenant();
        $demo = HomeGym::demo();
        if (! $gym || ! $demo || $gym->is($demo) || ! $demo->setting('is_demo', false)) {
            $this->line('No demo club to keep in step.');

            return self::SUCCESS;
        }

        $changed = $mirror->sync($gym, $demo);
        $this->info($changed === [] ? 'The demo club is in step with the gym.' : 'Copied to the demo club: '.collect($changed)->map(fn ($n, $t) => "{$t} {$n}")->implode(', '));

        return self::SUCCESS;
    }
}
