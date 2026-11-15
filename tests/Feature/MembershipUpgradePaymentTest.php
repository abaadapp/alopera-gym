<?php

namespace Tests\Feature;

use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * An upgrade is priced less what was left of the membership it replaces (its credit). Its Paymob payment is applied —
 * the upgrade activated, the previous membership replaced — only while that previous membership still stands as it did
 * when the upgrade was sold; cancelled or ended meanwhile, the money is held for review (the credit is not given twice:
 * once off the upgrade, once again when the cancelled membership is paid back). Also: a second, different successful
 * transaction for an order already paid is recorded and held, never dropped and never applied twice. The concurrent
 * cases run in separate processes on PostgreSQL; the process holding a lock goes on only once PostgreSQL shows the
 * other one blocked by it (pg_blocking_pids), so the overlap is proven, not left to timing. Paymob is faked.
 */
class MembershipUpgradePaymentTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    private const HMAC = 'upgrade-hmac';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_up', 'intention_order_id' => 4992], 201)]);
    }

    /** @return array{0: Tenant, 1: Membership, 2: Membership, 3: PaymentIntent} the active membership, its pending upgrade, its checkout */
    private function upgradeWaitingForPayment(): array
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));
        $member = $this->member($club);
        $current = $this->activeMembership($club, $member, $this->plan($club, ['price' => 25]));
        $better = $this->plan($club, ['price' => 40]);
        [$upgrade, $intent] = $this->asTenant($club, function () use ($current, $better) {
            $upgrade = app(MembershipService::class)->upgrade($current, $better);

            return [$upgrade, app(OnlinePaymentService::class)->start($upgrade->invoice, 'portal')];
        });
        $this->assertTrue(Money::cmp($upgrade->credit_applied, '0') > 0, 'the upgrade carries the previous membership\'s credit');

        return [$club, $current, $upgrade, $intent];
    }

    /** @return array<string, mixed> */
    private function obj(PaymentIntent $intent, int $id = 563001): array
    {
        return [
            'id' => $id, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true,
            'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false,
            'is_3d_secure' => true, 'integration_id' => 111, 'has_parent_transaction' => false,
            'order' => ['id' => (int) $intent->provider_order_id, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-15T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ];
    }

    private static function deliver(array $obj): string
    {
        return app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], PaymobSignature::compute($obj, self::HMAC));
    }

    private static function membershipLock(int $id): callable
    {
        // That row's own lock: `where "gym_memberships"."id" = ?`, its id the first binding (the tenant's id comes after).
        return fn (string $sql, array $bindings) => str_contains($sql, 'from "gym_memberships" where "gym_memberships"."id" = ?') && ($bindings[0] ?? null) === $id;
    }

    private function assertBooks(Tenant $club): void
    {
        $this->asTenant($club, fn () => $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'), 'the books balance'));
    }

    public function test_an_upgrade_whose_previous_membership_was_cancelled_is_held_not_activated(): void
    {
        [$club, $current, $upgrade, $intent] = $this->upgradeWaitingForPayment();
        $this->asTenant($club, fn () => app(MembershipService::class)->cancel($current, 'إلغاء بطلب العضو'));

        $this->assertSame('held_for_review', self::deliver($this->obj($intent)));

        $this->asTenant($club, function () use ($current, $upgrade, $intent) {
            $payment = Payment::where('gateway', 'paymob')->sole();
            $this->assertSame([Membership::CANCELLED, Membership::PENDING], [$current->fresh()->status, $upgrade->fresh()->status], 'nothing activated');
            $this->assertSame([Invoice::ISSUED, $intent->amount, '0.000'], [Invoice::findOrFail($upgrade->invoice_id)->status, $payment->credit_amount, $payment->credit_used], 'recorded in full, not applied');
            $this->assertSame(0, Payment::where('kind', Payment::KIND_REFUND)->count(), 'nothing paid back by itself');
        });
        $this->assertBooks($club);
    }

    public function test_an_upgrade_whose_previous_membership_ended_is_held_not_activated(): void
    {
        [$club, $current, $upgrade, $intent] = $this->upgradeWaitingForPayment();
        $this->asTenant($club, fn () => $current->forceFill(['status' => Membership::EXPIRED])->save());

        $this->assertSame('held_for_review', self::deliver($this->obj($intent)));
        $this->asTenant($club, fn () => $this->assertSame([Membership::EXPIRED, Membership::PENDING], [$current->fresh()->status, $upgrade->fresh()->status]));
    }

    public function test_an_upgrade_paid_in_time_still_replaces_its_previous_membership(): void
    {
        [$club, $current, $upgrade, $intent] = $this->upgradeWaitingForPayment();

        $this->assertSame('processed', self::deliver($this->obj($intent)));
        $this->asTenant($club, fn () => $this->assertSame([Membership::CANCELLED, Membership::ACTIVE, Invoice::PAID],
            [$current->fresh()->status, $upgrade->fresh()->status, Invoice::findOrFail($upgrade->invoice_id)->status]));
        $this->assertBooks($club);
    }

    public function test_the_previous_membership_cancelled_while_the_upgrade_payment_comes_in_holds_the_money(): void
    {
        [$club, $current, $upgrade, $intent] = $this->upgradeWaitingForPayment();
        $obj = $this->obj($intent);

        $results = $this->concurrently([
            function (string $dir) use ($club, $current) {
                self::holdUntilAnotherWaits($dir, self::membershipLock($current->id)); // the cancellation holds it

                $status = $this->asTenant($club, fn () => app(MembershipService::class)->cancel($current, 'إلغاء')->status);

                return [$status, is_file("$dir/blocked")];
            },
            function (string $dir) use ($obj) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::deliver($obj) : 'not at the same time';
            },
        ]);

        $this->assertSame([[true, [Membership::CANCELLED, true]], [true, 'held_for_review']], array_map(fn ($r) => [$r['ok'], $r['value']], $results), json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([Membership::CANCELLED, Membership::PENDING, 1],
            [$current->fresh()->status, $upgrade->fresh()->status, Payment::where('gateway', 'paymob')->count()]));
        $this->assertBooks($club);
    }

    public function test_the_upgrade_payment_first_then_the_cancellation_of_the_replaced_membership_is_refused(): void
    {
        [$club, $current, $upgrade, $intent] = $this->upgradeWaitingForPayment();
        $obj = $this->obj($intent);

        $results = $this->concurrently([
            function (string $dir) use ($obj, $current) {
                self::holdUntilAnotherWaits($dir, self::membershipLock($current->id)); // the callback holds the previous one

                return [self::deliver($obj), is_file("$dir/blocked")];
            },
            function (string $dir) use ($club, $current) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $this->asTenant($club, fn () => app(MembershipService::class)->cancel($current, 'إلغاء')->status) : 'not at the same time';
            },
        ]);

        $this->assertSame([true, ['processed', true]], [$results[0]['ok'], $results[0]['value']], json_encode($results));
        $this->assertFalse($results[1]['ok'], json_encode($results));
        $this->assertStringContainsString('BusinessRuleException', (string) $results[1]['error'], json_encode($results)); // already replaced
        $this->asTenant($club, fn () => $this->assertSame([Membership::CANCELLED, Membership::ACTIVE],
            [$current->fresh()->status, $upgrade->fresh()->status]));
        $this->assertBooks($club);
    }

    public function test_a_second_different_successful_transaction_for_a_paid_checkout_is_recorded_and_held(): void
    {
        [$club, , $upgrade, $intent] = $this->upgradeWaitingForPayment();

        $results = $this->concurrently([
            function (string $dir) use ($intent) {
                self::holdUntilAnotherWaits($dir, fn (string $sql) => str_contains($sql, 'from "payment_intents"'));

                return [self::deliver($this->obj($intent, 563011)), is_file("$dir/blocked")];
            },
            function (string $dir) use ($intent) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::deliver($this->obj($intent, 563012)) : 'not at the same time';
            },
        ]);

        $this->assertSame([[true, ['processed', true]], [true, 'held_for_review']], array_map(fn ($r) => [$r['ok'], $r['value']], $results), json_encode($results));
        $this->asTenant($club, function () use ($upgrade, $intent) {
            $first = Payment::where('gateway_transaction_id', '563011')->sole();
            $second = Payment::where('gateway_transaction_id', '563012')->sole();
            $this->assertSame([Invoice::PAID, Membership::ACTIVE, $first->id], [Invoice::findOrFail($upgrade->invoice_id)->status, $upgrade->fresh()->status, $intent->fresh()->payment_id]);
            $this->assertSame(['0.000', $intent->amount], [$first->credit_amount, $second->credit_amount], 'the second is the customer\'s credit, held');
        });
        $this->assertBooks($club);
    }
}
