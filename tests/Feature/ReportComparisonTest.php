<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Every report figure is compared with a period: the same length just before, or one the user picks. */
class ReportComparisonTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function compareRange(array $query): array
    {
        $club = $this->club();
        $range = [];
        $this->actingAs($this->owner($club))
            ->get(route('club.reports.show', ['report' => 'members', 'from' => '2026-10-01', 'to' => '2026-10-28'] + $query))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$range) {
                $range = [$page->toArray()['props']['filters']['compare'], $page->toArray()['props']['data']['report']['range']['previous_from'], $page->toArray()['props']['data']['report']['range']['previous_to']];
            });

        return $range;
    }

    public function test_default_is_the_previous_period_of_the_same_length(): void
    {
        $this->assertSame(['previous', '2026-09-03', '2026-09-30'], $this->compareRange([]));
    }

    public function test_same_dates_last_month_and_last_year(): void
    {
        $this->assertSame(['month', '2026-09-01', '2026-09-28'], $this->compareRange(['compare' => 'month']));
        $this->assertSame(['year', '2025-10-01', '2025-10-28'], $this->compareRange(['compare' => 'year']));
    }

    public function test_custom_comparison_and_bad_input_falls_back_to_default(): void
    {
        $this->assertSame(['custom', '2026-06-01', '2026-06-30'], $this->compareRange(['compare' => 'custom', 'compare_from' => '2026-06-30', 'compare_to' => '2026-06-01']));
        $this->assertSame(['previous', '2026-09-03', '2026-09-30'], $this->compareRange(['compare' => 'custom', 'compare_from' => 'nonsense']));
    }
}
