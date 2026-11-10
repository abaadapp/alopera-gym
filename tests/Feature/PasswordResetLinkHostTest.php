<?php

namespace Tests\Feature;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The staff password-reset e-mail links to the panel's own address (APP_URL), never to the Host the request that asked
 * for it came with: a request sent with a Host of someone's choosing would otherwise put the owner's reset token in a
 * genuine e-mail pointing at their server.
 */
class PasswordResetLinkHostTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_the_reset_link_is_on_the_panels_address_whatever_host_asked_for_it(): void
    {
        Notification::fake();
        $owner = $this->owner($this->club());

        $this->post('http://attacker.example/forgot-password', ['email' => $owner->email])->assertRedirect();

        Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $notification) use ($owner) {
            $link = $notification->toMail($owner)->actionUrl;
            $this->assertSame(parse_url(config('app.url'), PHP_URL_HOST), parse_url($link, PHP_URL_HOST), $link);
            $this->assertStringContainsString('/reset-password/'.$notification->token, $link);
            $this->assertStringContainsString('email='.urlencode($owner->email), $link);

            return true;
        });
    }

    public function test_the_link_from_the_panels_own_address_still_works(): void
    {
        Notification::fake();
        $owner = $this->owner($this->club());

        $this->post('/forgot-password', ['email' => $owner->email])->assertRedirect();

        Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $notification) use ($owner) {
            $link = $notification->toMail($owner)->actionUrl;
            $path = parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY);
            $this->get($path)->assertOk();

            return true;
        });
    }
}
