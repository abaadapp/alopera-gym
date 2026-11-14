<?php

namespace Tests\Feature;

use App\Core\Support\Money;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * One lock order for an invoice and what hangs on it: the invoice first, then its membership, its payment. A membership
 * cancelled, a Paymob callback, a cash payment, a transfer confirmed or cancelled — each in its own process on PostgreSQL,
 * one held right after a lock while the other comes in: they wait for each other, PostgreSQL never has to end one of
 * them (SQLSTATE 40P01), no payment is recorded twice, a cancelled membership is never activated, held money is never
 * applied by itself, and the books balance. Paymob is faked.
 */
class MembershipCancelLockOrderTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    private const HMAC = 'lock-order-hmac';

    private const LOCK_INTENT = 'select * from "payment_intents" where "payment_intents"."id" = ? and "payment_intents"."tenant_id" = ? limit 1 for update';

    private const LOCK_MEMBERSHIP = 'select * from "gym_memberships" where "gym_memberships"."id" = ? and "gym_memberships"."tenant_id" = ? limit 1 for update';

    private const LOCK_PAYMENT = 'select * from "payments" where "payments"."id" = ? and "payments"."tenant_id" = ? limit 1 for update';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_lock', 'intention_order_id' => 4991], 201)]);
    }

    private function paymobClub(): Tenant
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));

        return $club;
    }

    /** @return array{0: Membership, 1: ?PaymentIntent} a membership of 25 waiting for its payment (online, when asked) */
    private function sold(Tenant $club, bool $online = true): array
    {
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        return $this->asTenant($club, function () use ($member, $plan, $online) {
            $membership = app(MembershipService::class)->sell($member, $plan);

            return [$membership, $online ? app(OnlinePaymentService::class)->start($membership->invoice, 'portal') : null];
        });
    }

    /** @return array<string, mixed> */
    private function obj(PaymentIntent $intent, int $id = 562001): array
    {
        return [
            'id' => $id, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true,
            'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false,
            'is_3d_secure' => true, 'integration_id' => 111, 'has_parent_transaction' => false,
            'order' => ['id' => (int) $intent->provider_order_id, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-14T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ];
    }

    private static function deliver(array $obj): string
    {
        return app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], PaymobSignature::compute($obj, self::HMAC));
    }

    /** Inside a task: hold right after the first row lock taken on an invoice, and say so ("inside"). */
    private static function holdAfterLockingTheInvoice(string $dir, float $seconds): void
    {
        DB::listen(function ($query) use ($dir, $seconds) {
            if (str_contains($query->sql, 'from "invoices"') && str_contains($query->sql, 'for update') && ! is_file("$dir/inside")) {
                touch("$dir/inside");
                usleep((int) ($seconds * 1_000_000));
            }
        });
    }

    private function cancel(Tenant $club, Membership $membership): string
    {
        return $this->asTenant($club, fn () => app(MembershipService::class)->cancel($membership, 'إلغاء بطلب العضو')->status);
    }

    /** Both ran, neither was ended by PostgreSQL: a refusal is a business rule, never a deadlock or a lock error. */
    private function assertNoDeadlock(array $results): void
    {
        $json = json_encode($results, JSON_UNESCAPED_UNICODE);
        foreach ($results as $result) {
            $this->assertNotSame('not at the same time', $result['value'] ?? null, $json);
            $this->assertNotSame('error', $result['value'] ?? null, 'the callback was not ended: '.$json);
            if (! $result['ok']) {
                $this->assertStringContainsString('BusinessRuleException', (string) $result['error'], $json);
            }
            $this->assertStringNotContainsString('40P01', (string) ($result['error'] ?? ''), $json);
            $this->assertStringNotContainsStringIgnoringCase('deadlock', (string) ($result['error'] ?? ''), $json);
        }
    }

    /** Whatever happened: each membership agrees with its invoice, each payment with itself, and the books balance. */
    private function assertWhole(Tenant $club): void
    {
        $this->asTenant($club, function () {
            foreach (Membership::with('invoice')->get() as $m) {
                if (in_array($m->status, [Membership::ACTIVE, Membership::SCHEDULED, Membership::FROZEN], true)) {
                    $this->assertNotSame(Invoice::VOID, $m->invoice?->status, "membership {$m->id} runs on a voided invoice");
                }
                if ($m->status === Membership::PENDING) {
                    $this->assertNotSame(Invoice::PAID, $m->invoice?->status, "membership {$m->id} paid but never activated");
                }
            }
            foreach (Payment::where('kind', Payment::KIND_PAYMENT)->get() as $p) {
                $this->assertTrue(Money::cmp($p->credit_amount, $p->amount) <= 0 && Money::cmp($p->credit_used, $p->credit_amount) <= 0, "payment {$p->id}: credit within the payment");
            }
            $this->assertSame(0, Payment::whereNotNull('gateway_transaction_id')->groupBy('gateway_transaction_id')->havingRaw('count(*) > 1')->count('gateway_transaction_id'), 'one payment per Paymob transaction');
            $this->assertSame(0, Payment::where('kind', Payment::KIND_REFUND)->count(), 'nothing paid back by itself');
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'), 'the books balance');
        });
    }

    // 1
    public function test_a_membership_cancelled_before_the_callback_is_not_activated_and_the_money_is_held(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->sold($club);
        $obj = $this->obj($intent);

        $results = $this->concurrently([
            function (string $dir) use ($club, $membership) {
                self::holdAfterLockingTheInvoice($dir, 1.0); // the cancellation holds the invoice

                return $this->cancel($club, $membership);
            },
            function (string $dir) use ($obj) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::deliver($obj) : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        $this->assertSame([Membership::CANCELLED, 'held_for_review'], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, function () use ($membership) {
            $payment = Payment::where('gateway', 'paymob')->sole();
            $this->assertSame([Membership::CANCELLED, Invoice::VOID, '25.000', '0.000'],
                [$membership->fresh()->status, Invoice::findOrFail($membership->invoice_id)->status, $payment->credit_amount, $payment->credit_used]);
            $this->assertNull($membership->fresh()->activated_at ?? null);
            $this->assertSame(PaymentIntent::PAID, PaymentIntent::sole()->status);
        });
        $this->assertWhole($club);
    }

    // 2
    public function test_a_callback_before_the_cancellation_activates_then_the_cancellation_follows_the_policy_and_keeps_the_money(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->sold($club);
        $obj = $this->obj($intent);

        $results = $this->concurrently([
            function (string $dir) use ($obj) {
                self::holdAfterLockingTheInvoice($dir, 1.0); // the callback holds the invoice

                return self::deliver($obj);
            },
            function (string $dir) use ($club, $membership) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $this->cancel($club, $membership) : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        $this->assertSame(['processed', Membership::CANCELLED], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, function () use ($membership) {
            $payment = Payment::where('gateway', 'paymob')->sole();
            // Paid, then cancelled as any paid membership is: the invoice stays paid, the money stays recorded, nothing
            // is paid back by itself (finance decides).
            $this->assertSame([Membership::CANCELLED, Invoice::PAID, '0.000', '25.000'],
                [$membership->fresh()->status, Invoice::findOrFail($membership->invoice_id)->status, $payment->credit_amount, $payment->amount]);
        });
        $this->assertWhole($club);
    }

    // 4
    public function test_a_cancellation_and_a_cash_payment_at_the_moment_that_deadlocked_wait_for_each_other(): void
    {
        $club = $this->club();
        [$membership] = $this->sold($club, online: false);

        $results = $this->concurrently([
            function (string $dir) use ($club, $membership) {
                self::pauseAfter(self::LOCK_MEMBERSHIP, $dir, 1.0); // the cancellation holds the membership (and the invoice)

                return $this->cancel($club, $membership);
            },
            function (string $dir) use ($club, $membership) {
                self::waitFor("$dir/inside");
                if (! is_file("$dir/inside")) {
                    return 'not at the same time';
                }

                return $this->asTenant($club, fn () => app(PaymentService::class)->record(Invoice::findOrFail($membership->invoice_id), '25', 'cash')->status);
            },
        ]);

        $this->assertNoDeadlock($results);
        // The cancellation went first and withdrew the sale: the cash finds the invoice voided and is not taken.
        $this->assertSame(Membership::CANCELLED, $results[0]['value'], json_encode($results));
        $this->assertFalse($results[1]['ok'], json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([Invoice::VOID, 0], [Invoice::findOrFail($membership->invoice_id)->status, Payment::count()]));
        $this->assertWhole($club);
    }

    // 4
    public function test_a_cash_payment_first_then_the_cancellation_waits_and_keeps_the_money(): void
    {
        $club = $this->club();
        [$membership] = $this->sold($club, online: false);

        $results = $this->concurrently([
            function (string $dir) use ($club, $membership) {
                self::holdAfterLockingTheInvoice($dir, 1.0); // the cash payment holds the invoice

                return $this->asTenant($club, fn () => app(PaymentService::class)->record(Invoice::findOrFail($membership->invoice_id), '25', 'cash')->status);
            },
            function (string $dir) use ($club, $membership) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $this->cancel($club, $membership) : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        $this->assertSame([Payment::COMPLETED, Membership::CANCELLED], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([Invoice::PAID, '25.000'], [Invoice::findOrFail($membership->invoice_id)->status, Invoice::findOrFail($membership->invoice_id)->paid_total]));
        $this->assertWhole($club);
    }

    // 5
    public function test_a_cancellation_while_a_bank_transfer_is_confirmed_waits_for_it(): void
    {
        $club = $this->club();
        [$membership] = $this->sold($club, online: false);
        $transfer = $this->asTenant($club, fn () => app(PaymentService::class)->record(Invoice::findOrFail($membership->invoice_id), '25', 'bank_transfer', ['reference' => 'TR-LOCK']));

        $results = $this->concurrently([
            function (string $dir) use ($club, $transfer) {
                self::pauseAfter(self::LOCK_PAYMENT, $dir, 1.0); // the confirmation holds the transfer (and its invoice)

                return $this->asTenant($club, fn () => app(PaymentService::class)->confirm($transfer)->status);
            },
            function (string $dir) use ($club, $membership) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $this->cancel($club, $membership) : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        // Confirmed first: the membership is paid and active, then cancelled as any paid membership; the money stays.
        $this->assertSame([Payment::COMPLETED, Membership::CANCELLED], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([Invoice::PAID, Payment::COMPLETED], [Invoice::findOrFail($membership->invoice_id)->status, $transfer->fresh()->status]));
        $this->assertWhole($club);
    }

    // B
    public function test_a_transfer_confirmed_and_cancelled_at_once_waits_instead_of_deadlocking(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        [$invoice, $transfer] = $this->asTenant($club, function () use ($member) {
            $invoice = app(InvoiceService::class)->issue($member, [['description' => 'خدمة', 'unit_price' => '40']], ['type' => 'other']);

            return [$invoice, app(PaymentService::class)->record($invoice, '40', 'bank_transfer', ['reference' => 'TR-B'])];
        });

        $results = $this->concurrently([
            function (string $dir) use ($club, $transfer) {
                self::pauseAfter(self::LOCK_PAYMENT, $dir, 1.0); // before the fix: holding the transfer, then asking for the invoice

                return $this->asTenant($club, fn () => app(PaymentService::class)->confirm($transfer)->status);
            },
            function (string $dir) use ($club, $transfer) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside")
                    ? $this->asTenant($club, fn () => app(PaymentService::class)->cancel($transfer, 'سُجّل خطأً')->status)
                    : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->asTenant($club, function () use ($invoice, $transfer, $results) {
            $status = $transfer->fresh()->status;
            // One after the other: confirmed, then cancelled (or the cancellation refused); never half of each.
            $this->assertSame($results[1]['ok'] ? Payment::CANCELLED : Payment::COMPLETED, $status, json_encode($results));
            $this->assertSame($status === Payment::COMPLETED ? '40.000' : '0.000', $invoice->fresh()->paid_total);
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }

    // 6, 7
    public function test_two_callbacks_for_one_transaction_at_once_and_a_resend_record_one_payment(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->sold($club);
        $obj = $this->obj($intent, 562006);

        $results = $this->concurrently([
            function (string $dir) use ($obj) {
                self::pauseAfter(self::LOCK_INTENT, $dir, 0.8);

                return self::deliver($obj);
            },
            function (string $dir) use ($obj) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::deliver($obj) : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        $this->assertSame(['processed', 'duplicate'], array_column($results, 'value'), json_encode($results));
        $lines = $this->asTenant($club, fn () => JournalLine::count());

        $this->assertSame('duplicate', self::deliver($obj), 'sent again later: nothing more');

        $this->asTenant($club, function () use ($membership, $lines) {
            $this->assertSame(1, Payment::where('gateway', 'paymob')->count());
            $this->assertSame($lines, JournalLine::count(), 'no second entry in the books');
            $this->assertSame([Membership::ACTIVE, Invoice::PAID], [$membership->fresh()->status, Invoice::findOrFail($membership->invoice_id)->status]);
        });
        $this->assertWhole($club);
    }

    // 8
    public function test_a_callback_that_gives_up_waiting_for_a_lock_writes_nothing_and_is_recorded_once_when_sent_again(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->sold($club);
        $obj = $this->obj($intent, 562008);
        $before = $this->asTenant($club, fn () => [JournalLine::count(), StaffNotification::count()]);

        $results = $this->concurrently([
            function (string $dir) use ($club, $membership) {
                // Someone holds the invoice longer than the callback is willing to wait.
                return $this->asTenant($club, fn () => DB::transaction(function () use ($dir, $membership) {
                    Invoice::whereKey($membership->invoice_id)->lockForUpdate()->first();
                    touch("$dir/inside");
                    usleep(1_500_000);

                    return 'held';
                }));
            },
            function (string $dir) use ($obj) {
                self::waitFor("$dir/inside");
                if (! is_file("$dir/inside")) {
                    return 'not at the same time';
                }
                DB::statement("set lock_timeout = '300ms'");

                return self::deliver($obj);
            },
        ]);

        $this->assertSame(['held', 'error'], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, function () use ($membership, $before) {
            // Rolled back whole: no payment, no entry, no alert, the checkout still waiting, the membership still pending.
            $this->assertSame([0, $before[0], $before[1]], [Payment::count(), JournalLine::count(), StaffNotification::count()]);
            $this->assertSame([PaymentIntent::PENDING, Membership::PENDING, Invoice::ISSUED],
                [PaymentIntent::sole()->status, $membership->fresh()->status, Invoice::findOrFail($membership->invoice_id)->status]);
            $this->assertSame(1, PaymentWebhookEvent::where('transaction_id', '562008')->where('outcome', 'error')->count(), 'the failure is on record');
        });

        $this->assertSame('processed', self::deliver($obj), 'sent again: recorded once');
        $this->asTenant($club, fn () => $this->assertSame([1, Membership::ACTIVE], [Payment::count(), $membership->fresh()->status]));
        $this->assertWhole($club);
    }
}
