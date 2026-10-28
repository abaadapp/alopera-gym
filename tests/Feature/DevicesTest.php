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
