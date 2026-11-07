<?php

namespace Tests\Feature;

use App\Core\Auth\SystemRoles;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The automatic notices: each switched on or off by the club, the last-day notice, the hour the reminders go out,
 * and none sent twice however often the job runs. The development driver is on: nothing leaves.
 */
class WhatsAppAutomationTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function connect(Tenant $club): void
    {
        $this->asTenant($club, function () {
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved']);
            TenantIntegration::create(['provider' => 'whatsapp', 'is_enabled' => true, 'public_identifier' => 'PNID-AUTO',
                'credentials' => ['phone_number_id' => 'PNID-AUTO', 'access_token' => 'x'], 'settings' => ['vendor' => 'meta']]);
        });
    }

    private function sent(Tenant $club, string $key): int
    {
        return $this->asTenant($club, fn () => Message::where('template_key', $key)->where('status', '!=', Message::SKIPPED)->count());
    }

    public function test_a_notice_switched_off_is_not_sent_and_one_switched_on_is(): void
    {
        $club = $this->club();
        $this->connect($club);
        $owner = $this->owner($club);

        $this->actingAs($owner)->put(route('club.automation.update'), [
            'on' => ['member_welcome' => false, 'payment_received' => true], 'expiry_reminder_days' => [7, 3], 'expired_followup_days' => [7], 'hour' => 9,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.automation_changed']);

        $club = $club->fresh(); // as every request loads it
        $this->member($club);
        $this->assertSame(0, $this->sent($club, 'member_welcome'));

        $this->actingAs($owner)->put(route('club.automation.update'), ['on' => ['member_welcome' => true], 'expiry_reminder_days' => [7, 3], 'hour' => 9])->assertSessionHasNoErrors();
        $club = $club->fresh();
        $this->member($club);
        $this->assertSame(1, $this->sent($club, 'member_welcome'));
        $this->assertSame([7, 3], $club->fresh()->setting('expiry_reminder_days'));
    }

    public function test_the_last_day_notice_goes_once_and_not_to_whoever_renewed(): void
    {
        $club = $this->club();
        $this->connect($club);
        $leaving = $this->member($club);
        $staying = $this->member($club);
        $plan = $this->plan($club, ['duration_value' => 10, 'duration_unit' => 'day']);
        $this->activeMembership($club, $leaving, $plan);
        $current = $this->activeMembership($club, $staying, $plan);
        $this->asTenant($club, function () use ($staying, $plan) {
            $renewal = app(MembershipService::class)->renew($staying, $plan);
            app(PaymentService::class)->record($renewal->invoice()->first(), $renewal->total, 'cash');
        });
        $this->travel(9)->days();

        $this->artisan('gym:send-reminders', ['--tenant' => $club->slug])->assertSuccessful();
        $this->artisan('gym:send-reminders', ['--tenant' => $club->slug])->assertSuccessful();

        $this->asTenant($club, function () use ($leaving) {
            $this->assertSame([$leaving->id], Message::where('template_key', 'membership_expires_today')->pluck('member_id')->all());
        });
    }

    public function test_the_reminders_go_out_at_the_hour_the_club_chose(): void
    {
        $club = $this->club();
        $this->connect($club);
        $member = $this->member($club);
        $this->activeMembership($club, $member, $this->plan($club, ['duration_value' => 10, 'duration_unit' => 'day']));
        $this->actingAs($this->owner($club))->put(route('club.automation.update'), ['on' => [], 'expiry_reminder_days' => [3], 'hour' => 18])->assertSessionHasNoErrors();

        $this->travelTo(now()->addDays(6)->setTime(10, 5));
        $this->artisan('gym:send-reminders', ['--tenant' => $club->slug, '--due' => true])->assertSuccessful();
        $this->assertSame(0, $this->sent($club, 'membership_expiring'), 'not at ten any more');

        $this->travelTo(now()->setTime(18, 5));
        $this->artisan('gym:send-reminders', ['--tenant' => $club->slug, '--due' => true])->assertSuccessful();
        $this->assertSame(1, $this->sent($club, 'membership_expiring'));
    }

    public function test_only_who_manages_automation_changes_it(): void
    {
        $club = $this->club();
        $reception = $this->staff($club, SystemRoles::RECEPTION);

        $this->actingAs($reception)->get(route('club.automation.index'))->assertForbidden();
        $this->actingAs($reception)->put(route('club.automation.update'), ['on' => ['member_welcome' => false], 'hour' => 9])->assertForbidden();
        $this->assertSame([], (array) $club->fresh()->setting('whatsapp_automation.off', []));

        $page = $this->actingAs($this->staff($club, SystemRoles::MANAGER))->get(route('club.automation.index'))->assertOk()->viewData('page')['props'];
        $this->assertSame(['member_welcome', 'membership_activated', 'membership_expiring', 'membership_expires_today', 'membership_expired', 'membership_followup', 'membership_renewed', 'payment_received'],
            collect($page['events'])->pluck('key')->all());
    }
}
