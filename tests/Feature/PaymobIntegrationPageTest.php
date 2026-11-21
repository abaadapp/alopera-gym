<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Payments\Paymob\PaymobClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * «التطبيقات التكاملية ← Paymob» (owner, 2026-11-21): the settings read without a word to Paymob; the connection test —
 * a payment intention at Paymob — only once confirmed; the latest links and the way to «المالية ← روابط الدفع» for who
 * holds payments.links, no link made here, no option a member of staff cannot use; never a saved secret in the page.
 * Test keys only; nothing is paid.
 */
class PaymobIntegrationPageTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const SECRET = 'omn_sk_test_SECRETVALUE0001';

    private const PUBLIC = 'omn_pk_test_PUBLICVALUE0001';

    private const HMAC = 'HMACVALUE-0001-not-for-browsers';

    /** @param  array<string, string>  $credentials */
    private function paymob(Tenant $club, string $mode = 'test', array $credentials = [], bool $enabled = true): void
    {
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => TenantIntegration::PAYMOB, 'is_enabled' => $enabled, 'mode' => $mode,
            'credentials' => $credentials + ['secret_key' => self::SECRET, 'public_key' => self::PUBLIC, 'hmac_secret' => self::HMAC, 'method_card' => '4101', 'method_apple_pay' => '4103'],
        ]));
    }

    /** @param  array<string, string|null>  $credentials */
    private function change(Tenant $club, array $credentials, ?string $mode = null): void
    {
        $this->asTenant($club, function () use ($credentials, $mode) {
            $row = TenantIntegration::where('provider', 'paymob')->first();
            $row->forceFill(['credentials' => array_filter(array_merge($row->credentials ?? [], $credentials), fn ($v) => $v !== null)] + ($mode ? ['mode' => $mode] : []))->save();
        });
    }

    private function holding(Tenant $club, array $permissions): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.Str::lower(Str::random(8)), 'name' => 'مخصص', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
    }

    /** @return array<string, array{ok: bool, detail: string|null}> */
    private function review(Tenant $club): array
    {
        return $this->asTenant($club, fn () => collect(PaymobClient::settingsReview(TenantIntegration::where('provider', 'paymob')->first()))
            ->keyBy('label')->map(fn ($i) => ['ok' => $i['ok'], 'detail' => $i['detail']])->all());
    }

    public function test_the_settings_review_reads_what_is_saved_and_calls_nothing(): void
    {
        Http::fake();
        $club = $this->club();

        // Nothing saved: the keys and an id are asked for.
        $none = $this->asTenant($club, fn () => collect(PaymobClient::settingsReview(null))->keyBy('label'));
        $this->assertFalse($none['المفاتيح الثلاثة محفوظة']['ok']);
        $this->assertStringContainsString('Secret key', (string) $none['المفاتيح الثلاثة محفوظة']['detail']);
        $this->assertFalse($none['رقم تكامل لوسيلة دفع واحدة على الأقل']['ok']);

        // Test keys in test mode: all in order; Apple Pay said to stay out of a test checkout.
        $this->paymob($club);
        $review = $this->review($club);
        foreach (['المفاتيح الثلاثة محفوظة', 'رقم تكامل لوسيلة دفع واحدة على الأقل', 'المفتاحان السري والعام من بيئة واحدة', 'الوضع يطابق المفاتيح', 'الدفع الإلكتروني مفعّل'] as $label) {
            $this->assertTrue($review[$label]['ok'], $label);
        }
        $this->assertStringContainsString('لا يُعرض في وضع الاختبار', (string) $review['Apple Pay']['detail']);

        // A live public key with a test secret, under live mode: both said, and which is which.
        $this->change($club, ['public_key' => 'omn_pk_live_PUBLIC'], 'live');
        $review = $this->review($club);
        $this->assertFalse($review['المفتاحان السري والعام من بيئة واحدة']['ok']);
        $this->assertSame('المفتاح السري اختبار والعام حقيقي.', $review['المفتاحان السري والعام من بيئة واحدة']['detail']);
        $this->assertFalse($review['الوضع يطابق المفاتيح']['ok']);

        // No HMAC secret: named, never a value.
        $this->change($club, ['hmac_secret' => null]);
        $this->assertStringContainsString('HMAC secret', (string) $this->review($club)['المفاتيح الثلاثة محفوظة']['detail']);

        Http::assertNothingSent();
    }

    public function test_the_connection_test_opens_an_intention_only_when_confirmed(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_check', 'intention_order_id' => 1], 201)]);
        $club = $this->club();
        $owner = $this->owner($club);
        $this->paymob($club);

        // A stray request (no confirmation): nothing reaches Paymob, the staff are told why.
        $this->actingAs($owner)->post(route('club.integrations.verify', 'paymob'))->assertSessionHas('error', fn ($m) => str_contains($m, 'أكّده'));
        Http::assertNothingSent();

        // Confirmed: one intention of 1.000 with the test key, sent to no one; Apple Pay left out under test keys.
        $this->actingAs($owner)->post(route('club.integrations.verify', 'paymob'), ['confirm_intention' => 1])->assertSessionHas('success');
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Token '.self::SECRET) && $r['amount'] === 1000
            && $r['payment_methods'] === [4101] && ! isset($r['redirection_url']));

        // Only who manages the integrations.
        $this->actingAs($this->staff($club, 'manager'))->post(route('club.integrations.verify', 'paymob'), ['confirm_intention' => 1])->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_the_page_is_settings_check_and_latest_links_each_option_for_who_may_use_it_never_a_secret(): void
    {
        Http::fake();
        $club = $this->club();
        $owner = $this->owner($club);
        $this->paymob($club);

        $page = $this->actingAs($owner)->get(route('club.integrations.paymob'));
        $page->assertOk()->assertInertia(fn (Assert $p) => $p->component('Integrations/Paymob')
            ->where('review.0.label', 'المفاتيح الثلاثة محفوظة')->where('review.0.ok', true)
            ->where('links.manage_route', 'club.payment-links.index')->has('links.recent.rows')
            ->where('links.recent.can_cancel', true)->where('links.recent.can_open_invoice', true)
            ->missing('links.create_route')->missing('links.can_create')->where('usage.3.route', 'club.settings.sales')
            ->where('integration.present.secret_key', true)->where('integration.present.hmac_secret', true));
        foreach ([self::SECRET, self::HMAC, self::PUBLIC, '4101', '4103'] as $secret) {
            $this->assertStringNotContainsString($secret, $page->getContent());
        }

        // Keys without finance: the page, but neither the links nor the button that makes one.
        $keysOnly = $this->holding($club, ['integrations.manage']);
        $this->actingAs($keysOnly)->get(route('club.integrations.paymob'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('links.recent', null)->where('links.manage_route', null)->where('usage.3.route', null));

        // Links without the invoices: the links and their actions, but no way to an invoice page they cannot open.
        $linksOnly = $this->holding($club, ['integrations.manage', 'payments.links']);
        $this->actingAs($linksOnly)->get(route('club.integrations.paymob'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('links.manage_route', 'club.payment-links.index')
                ->where('links.recent.can_cancel', true)->where('links.recent.can_open_invoice', false));

        Http::assertNothingSent();
    }
}
