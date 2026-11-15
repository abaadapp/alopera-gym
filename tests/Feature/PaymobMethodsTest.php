<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Each Paymob payment method's state: a typed number is never «ready»; OmanNet unproven; Apple Pay live only. */
class PaymobMethodsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function paymob(Tenant $club, array $attributes = []): TenantIntegration
    {
        return $this->asTenant($club, fn () => TenantIntegration::create($attributes + [
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'h', 'integration_ids' => '111, 222'],
        ]));
    }

    /** @return array<string, array<string, mixed>> */
    private function states(Tenant $club): array
    {
        return $this->asTenant($club, fn () => collect(PaymobMethods::states(TenantIntegration::where('provider', 'paymob')->first()))->keyBy('key')->all());
    }

    private function event(TenantIntegration $row, bool $valid, string $outcome, int $integrationId): void
    {
        PaymentWebhookEvent::create([
            'tenant_id' => $row->tenant_id, 'provider' => 'paymob', 'transaction_id' => (string) random_int(1, 1_000_000_000), 'reference' => 'X',
            'signature_valid' => $valid, 'outcome' => $outcome, 'payload' => ['obj' => ['integration_id' => $integrationId]],
        ]);
    }

    public function test_a_typed_number_is_not_ready_omannet_stays_unavailable_and_apple_pay_needs_live(): void
    {
        $club = $this->club();
        $this->paymob($club, ['settings' => ['methods' => ['card' => '111', 'apple_pay' => '222']]]);

        $s = $this->states($club);
        $this->assertSame(PaymobMethods::SET, $s['card']['state']);
        $this->assertSame(111, $s['card']['id']);
        $this->assertSame(PaymobMethods::UNAVAILABLE, $s['omannet']['state']);
        $this->assertNull($s['omannet']['id']);
        $this->assertSame(PaymobMethods::UNAVAILABLE, $s['apple_pay']['state'], 'Apple Pay has live integration ids only');
    }

    public function test_a_label_of_an_id_no_longer_listed_labels_nothing(): void
    {
        $club = $this->club();
        $this->paymob($club, ['settings' => ['methods' => ['card' => '333']]]);

        $this->assertSame(PaymobMethods::NOT_SET, $this->states($club)['card']['state']);
        $this->assertNull($this->states($club)['card']['id']);
    }

    public function test_accepted_after_the_check_and_proven_only_by_a_signed_recorded_payment_of_this_club(): void
    {
        $club = $this->club();
        $other = $this->club('Other');
        $row = $this->paymob($club, ['verified_at' => now(), 'settings' => ['methods' => ['card' => '111']]]);
        $theirs = $this->paymob($other);
        $this->assertSame(PaymobMethods::ACCEPTED, $this->states($club)['card']['state']);

        $this->event($row, false, 'processed', 111);
        $this->event($row, true, 'amount_mismatch', 111);
        $this->event($row, true, 'processed', 222);
        $this->event($theirs, true, 'processed', 111);
        $this->assertSame(PaymobMethods::ACCEPTED, $this->states($club)['card']['state'], 'unsigned, unrecorded, another id or another club proves nothing');

        $this->event($row, true, 'processed', 111);
        $this->assertSame(PaymobMethods::PROVEN, $this->states($club)['card']['state']);
    }

    public function test_apple_pay_on_a_live_account_follows_the_same_steps(): void
    {
        $club = $this->club();
        $this->paymob($club, ['mode' => 'live', 'settings' => ['methods' => ['apple_pay' => '222']],
            'credentials' => ['secret_key' => 'x_sk_live_1', 'public_key' => 'x_pk_live_1', 'hmac_secret' => 'h', 'integration_ids' => '111,222']]);

        $this->assertSame(PaymobMethods::SET, $this->states($club)['apple_pay']['state']);
    }

    public function test_labelling_takes_only_listed_distinct_ids_and_changes_no_key_or_integration(): void
    {
        $club = $this->club();
        $row = $this->paymob($club);
        $owner = $this->owner($club);
        $before = $row->credentials;

        $this->actingAs($owner)->put(route('club.integrations.paymob.methods'), ['card' => 999])->assertSessionHasErrors('card');
        $this->actingAs($owner)->put(route('club.integrations.paymob.methods'), ['card' => 111, 'apple_pay' => 111])->assertSessionHas('error');
        $this->actingAs($owner)->put(route('club.integrations.paymob.methods'), ['card' => 111, 'apple_pay' => 222])->assertSessionHasNoErrors()->assertSessionHas('success');

        $row = $this->asTenant($club, fn () => TenantIntegration::where('provider', 'paymob')->first());
        $this->assertSame(['card' => '111', 'apple_pay' => '222'], $row->option('methods'));
        $this->assertSame($before, $row->credentials);
        $this->assertTrue($row->is_enabled);

        $this->actingAs($owner)->get(route('club.integrations.paymob'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('listedIds', [111, 222])->has('methods', 3));
    }

    public function test_staff_without_integrations_manage_cannot_label_methods(): void
    {
        $club = $this->club();
        $this->paymob($club);

        $this->actingAs($this->staff($club, 'manager'))->put(route('club.integrations.paymob.methods'), ['card' => 111])->assertForbidden();
    }
}
