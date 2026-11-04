<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A refused dialog shows its errors even when the bell (or a live preview) asked the server in between: the one-time
 * messages wait for the page. A page itself still takes them, once.
 */
class FlashSurvivesBackgroundTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_a_background_request_in_between_leaves_the_errors_for_the_page(): void
    {
        $this->actingAs($this->owner($this->club()));

        $this->from(route('club.branches.index'))->post(route('club.branches.store'), ['name' => ''])->assertSessionHasErrors('name');

        $this->getJson(route('club.notifications.feed'))->assertOk();
        $this->assertTrue(session()->has('errors'), 'the bell took the errors meant for the page');

        $this->get(route('club.branches.index'))->assertOk();
        $this->get(route('club.branches.index'))->assertOk();
        $this->assertFalse(session()->has('errors'), 'a page takes them once');
    }
}
