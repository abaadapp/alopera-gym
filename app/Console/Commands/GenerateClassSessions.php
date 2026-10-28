<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\ScheduleService;

class GenerateClassSessions extends ForEachTenant
{
    protected $signature = 'gym:sessions {--tenant=}';

    protected $description = 'Create upcoming class sessions from the weekly timetable and close finished ones (no-shows)';

    protected function handleTenant(Tenant $tenant): string
    {
        $created = app(ScheduleService::class)->generate();
        $closed = app(BookingService::class)->closeFinishedSessions();

        return "created={$created} closed={$closed}";
    }
}
