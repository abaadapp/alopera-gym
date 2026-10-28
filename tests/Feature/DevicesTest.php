<?php

namespace Tests\Feature;

use App\Modules\Gym\Models\AccessCredential;
use App\Modules\Gym\Models\AccessDevice;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Models\StaffAttendance;
use App\Modules\Gym\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Spec 5: QR/barcode readers, gates, fingerprint staff attendance, entry by membership status. */
class DevicesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{0: AccessDevice, 1: string} */
    private function device($club, string $purpose = 'both'): array
    {
        return $this->asTenant($club, function () use ($purpose) {
            $device = new AccessDevice(['name' => 'Main gate', 'type' => 'turnstile', 'purpose' => $purpose, 'direction' => 'in']);
            $token = $device->issueToken();
            $device->save();

            return [$device, $token];
        });
    }

    public function test_devices_must_authenticate_with_their_own_token(): void
    {
        $this->postJson(route('devices.access'), ['credential_type' => 'qr', 'credential' => 'x'])->assertStatus(401);
        $this->withToken('dev_wrong')->postJson(route('devices.access'), ['credential_type' => 'qr', 'credential' => 'x'])->assertStatus(401);

        $club = $this->club();
        [$device, $token] = $this->device($club);
        $this->assertNotSame($token, $device->token_hash, 'Only the hash is stored.');
        $this->withToken($token)->postJson(route('devices.heartbeat'))->assertOk()->assertJson(['ok' => true]);

        $this->asTenant($club, fn () => $device->update(['is_active' => false]));
        $this->withToken($token)->postJson(route('devices.heartbeat'))->assertStatus(401);
    }

    public function test_door_allows_active_members_and_denies_expired_ones_with_a_reason(): void
    {
        $club = $this->club();
        [, $token] = $this->device($club);
        $active = $this->member($club);
        $this->activeMembership($club, $active);
        $expired = $this->member($club);
        $this->activeMembership($club, $expired, $this->plan($club, ['duration_value' => 1, 'duration_unit' => 'day']));
        $this->travel(2)->days();
        $this->asTenant($club, fn () => app(MembershipService::class)->runDailyTransitions());
        $this->activeMembership($club, $active, $this->plan($club, ['duration_value' => 1, 'duration_unit' => 'month']));

        $this->withToken($token)->postJson(route('devices.access'), ['credential_type' => 'qr', 'credential' => $active->qr_token])
            ->assertOk()->assertJson(['allowed' => true, 'member' => ['id' => $active->id]]);
        $this->withToken($token)->postJson(route('devices.access'), ['credential_type' => 'qr', 'credential' => $expired->qr_token])
            ->assertOk()->assertJson(['allowed' => false, 'reason' => 'membership_expired']);
        $this->withToken($token)->postJson(route('devices.access'), ['credential_type' => 'card', 'credential' => 'UNKNOWN-CARD'])
            ->assertOk()->assertJson(['allowed' => false, 'reason' => 'unknown_credential']);

        $this->asTenant($club, function () {
            $this->assertSame(1, CheckIn::where('result', 'allowed')->count());
            $this->assertSame(2, CheckIn::where('result', 'denied')->count());
            $this->assertStringNotContainsString('UNKNOWN-CARD', (string) CheckIn::whereNull('member_id')->value('credential_hint'));
        });
    }

    public function test_a_device_cannot_admit_a_member_of_another_club(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        [, $token] = $this->device($a);
        $foreign = $this->member($b);
        $this->activeMembership($b, $foreign);

        $this->withToken($token)->postJson(route('devices.access'), ['credential_type' => 'qr', 'credential' => $foreign->qr_token])
            ->assertOk()->assertJson(['allowed' => false, 'reason' => 'unknown_credential']);
    }

    public function test_fingerprint_punches_staff_in_then_out(): void
    {
        // A morning shift: an 8-hour shift started late in the day would cross midnight into a new day.
        $this->travelTo(now()->setTime(8, 0));
        $club = $this->club();
        [, $token] = $this->device($club, 'staff');
        $reception = $this->staff($club, 'reception');
        $this->asTenant($club, fn () => AccessCredential::create(['holder_type' => 'user', 'holder_id' => $reception->id, 'type' => 'fingerprint', 'identifier' => 'FP-17']));

        $this->withToken($token)->postJson(route('devices.staff-punch'), ['credential_type' => 'fingerprint', 'credential' => 'FP-17'])->assertJson(['ok' => true, 'type' => 'in']);
        $this->travel(8)->hours();
        $this->withToken($token)->postJson(route('devices.staff-punch'), ['credential_type' => 'fingerprint', 'credential' => 'FP-17'])->assertJson(['ok' => true, 'type' => 'out']);

        $this->asTenant($club, function () use ($reception) {
            $row = StaffAttendance::where('user_id', $reception->id)->firstOrFail();
            $this->assertNotNull($row->clock_out_at);
        });
    }

    public function test_a_repeat_scan_inside_the_window_lets_her_in_but_is_logged_and_not_counted(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $this->activeMembership($club, $member);
        [, $token] = $this->device($club);
        $scan = fn () => $this->withToken($token)->postJson(route('devices.access'), ['credential_type' => 'qr', 'credential' => $member->qr_token]);

        $scan()->assertJson(['allowed' => true, 'reason' => 'ok']);
        $scan()->assertJson(['allowed' => true, 'reason' => 'duplicate_scan']);
        $this->travel(2)->minutes();
        $scan()->assertJson(['allowed' => true, 'reason' => 'duplicate_scan']);

        $this->asTenant($club, function () use ($member) {
            $this->assertSame(1, CheckIn::where('member_id', $member->id)->where('result', 'allowed')->count(), 'counted once');
            $this->assertSame(2, CheckIn::where('member_id', $member->id)->where('result', 'duplicate')->count(), 'every attempt is kept');
        });

        $this->travel(6)->minutes(); // past the default 5-minute window
        $scan()->assertJson(['allowed' => true, 'reason' => 'ok']);
        $this->assertSame(2, $this->asTenant($club, fn () => CheckIn::where('member_id', $member->id)->where('result', 'allowed')->count()));
    }

    public function test_duplicate_window_is_configurable_and_can_be_switched_off(): void
    {
        $club = $this->club();
        $club->forceFill(['settings' => array_replace($club->settings ?? [], ['duplicate_scan_minutes' => 0])])->save();
        $member = $this->member($club);
        $this->activeMembership($club, $member);
        $reception = $this->staff($club, 'reception');

        $this->actingAs($reception)->postJson(route('club.checkins.store'), ['qr' => $member->qr_token])->assertJson(['reason' => 'ok']);
        $this->actingAs($reception)->postJson(route('club.checkins.store'), ['qr' => $member->qr_token])->assertJson(['reason' => 'ok']);
        $this->assertSame(2, $this->asTenant($club, fn () => CheckIn::where('member_id', $member->id)->where('result', 'allowed')->count()));

    }

    public function test_reception_qr_check_in_endpoint(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $this->activeMembership($club, $member);

        $this->actingAs($this->staff($club, 'reception'))
            ->postJson(route('club.checkins.store'), ['qr' => $member->qr_token])
            ->assertOk()->assertJson(['allowed' => true]);
    }
}
