<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Messaging\Jobs\SendWhatsAppMessage;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Services\WhatsAppService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A signed, successful Paymob callback meeting a failing database or a failing step, at each point: the answer Paymob gets
 * follows what the books hold (recorded: 200; not recorded, or not known: 500, never a redirect), the money is recorded
 * once whatever is resent, and a step that fails after the payment committed (a WhatsApp message the queue refuses) is
 * reported and retried on its own, never turning the payment into «not recorded». Paymob is faked.
 */
class PaymobWebhookFailureTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** Statements starting with this fail, as a database failing at that point would. */
    private ?string $failOn = null;

    /** @var list<int> the Paymob order id each new checkout gets, in turn */
    private array $orderIds = [4800, 4801, 4802];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*/v1/intention/' => fn () => Http::response(['client_secret' => 'cs_fail', 'intention_order_id' => array_shift($this->orderIds)], 201)]);
        DB::beforeExecuting(function (string $sql) {
            if ($this->failOn !== null && str_starts_with($sql, $this->failOn)) {
                throw new \PDOException('SQLSTATE[XX000]: simulated database failure on: '.$this->failOn);
            }
        });
    }

    private function shop(Tenant $club): Product
    {
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'hmac-test', 'integration_ids' => '111'],
        ]));

        return $this->asTenant($club, function () {
            $product = Product::create(['name' => 'Bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($product, 5, 'adjustment', null, 'opening', 0.8);

            return $product->fresh();
        });
    }

    /** @return array{0: Sale, 1: PaymentIntent} */
    private function onlineOrder(Tenant $club, Product $product): array
    {
        $this->post(route('site.shop.order.store', ['club' => $club->slug]), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'name' => 'Online', 'phone' => '91234567', 'payment_method' => 'paymob',
        ])->assertRedirectContains('clientSecret=cs_fail');

        return $this->asTenant($club, fn () => [Sale::latest('id')->firstOrFail(), PaymentIntent::latest('id')->firstOrFail()]);
    }

    /** @return array<string, mixed> Paymob's transaction object for a successful payment of $intent */
    private function obj(PaymentIntent $intent, int $transaction): array
    {
        return ['id' => $transaction, 'pending' => false, 'amount_cents' => (int) round((float) $intent->amount * 1000), 'success' => true,
            'is_voided' => false, 'is_refunded' => false, 'error_occured' => false, 'currency' => 'OMR',
            'order' => ['id' => (int) $intent->provider_order_id, 'merchant_order_id' => $intent->reference], 'created_at' => '2026-11-14T10:00:00',
            'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true, 'is_3d_secure' => true, 'integration_id' => 111,
            'has_parent_transaction' => false, 'owner' => 1, 'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card']];
    }

    /** As Paymob posts it (a JSON body, without asking for JSON back), each callback a request of its own. */
    private function send(array $obj, ?string $hmac = null): TestResponse
    {
        $this->app->forgetScopedInstances();

        return $this->call('POST', route('webhooks.paymob').'?hmac='.($hmac ?? PaymobSignature::compute($obj, 'hmac-test')), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => '*/*'], json_encode(['type' => 'TRANSACTION', 'obj' => $obj]));
    }

    /** @return array{payments: int, credit: string, invoice: string, order_paid: bool, intent: string, events: list<string>, books: bool} */
    private function books(Tenant $club, Sale $sale, PaymentIntent $intent): array
    {
        return $this->asTenant($club, fn () => [
            'payments' => Payment::where('gateway', 'paymob')->count(),
            'credit' => (string) Payment::where('gateway', 'paymob')->sum('credit_amount'),
            'invoice' => Invoice::findOrFail($sale->invoice_id)->status,
            'order_paid' => $sale->fresh()->paid_at !== null,
            'intent' => $intent->fresh()->status,
            'events' => PaymentWebhookEvent::orderBy('id')->pluck('outcome')->all(),
            'books' => JournalLine::sum('debit') === JournalLine::sum('credit'),
        ]);
    }

    /** A database connection nobody answers on, and a queue kept on it: every push to it is refused. */
    private function deadConnection(): void
    {
        config(['database.connections.dead' => ['host' => '127.0.0.1', 'port' => 1] + config('database.connections.pgsql')]);
        config(['queue.connections.dead' => ['driver' => 'database', 'connection' => 'dead', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]]);
    }

    public function test_the_database_unreachable_is_answered_500_records_nothing_and_the_resend_records_once(): void
    {
        $club = $this->club();
        [$sale, $intent] = $this->onlineOrder($club, $this->shop($club));
        $obj = $this->obj($intent, 910001);

        $this->deadConnection();
        config(['database.default' => 'dead']);
        $down = $this->send($obj);
        config(['database.default' => 'pgsql']);

        $this->assertSame(500, $down->getStatusCode(), 'no success and no redirect while the database is down');
        $this->assertSame(['payments' => 0, 'credit' => '0', 'invoice' => Invoice::ISSUED, 'order_paid' => false, 'intent' => PaymentIntent::PENDING, 'events' => [], 'books' => true],
            $this->books($club, $sale, $intent), 'nothing recorded, and no event either: the database that would keep it is the one down');

        $this->send($obj)->assertOk()->assertJson(['outcome' => 'processed']); // back up, Paymob sends it again
        $this->send($obj)->assertOk()->assertJson(['outcome' => 'duplicate']);
        $b = $this->books($club, $sale, $intent);
        $this->assertSame([1, '0.000', Invoice::PAID, true, PaymentIntent::PAID, true], [$b['payments'], $b['credit'], $b['invoice'], $b['order_paid'], $b['intent'], $b['books']]);
    }

    public function test_the_payment_insert_failing_rolls_back_keeps_the_callback_and_the_resend_records_once(): void
    {
        $club = $this->club();
        [$sale, $intent] = $this->onlineOrder($club, $this->shop($club));
        $obj = $this->obj($intent, 910002);

        $this->failOn = 'insert into "payments"';
        $this->send($obj)->assertStatus(500);
        $this->failOn = null;
        $this->assertSame(['payments' => 0, 'credit' => '0', 'invoice' => Invoice::ISSUED, 'order_paid' => false, 'intent' => PaymentIntent::PENDING, 'events' => ['error'], 'books' => true],
            $this->books($club, $sale, $intent));

        $this->send($obj)->assertOk()->assertJson(['outcome' => 'processed']);
        $this->assertSame(1, $this->books($club, $sale, $intent)['payments']);
    }

    public function test_the_callback_log_failing_after_the_payment_committed_is_answered_as_recorded(): void
    {
        $club = $this->club();
        [$sale, $intent] = $this->onlineOrder($club, $this->shop($club));
        $obj = $this->obj($intent, 910003);

        $this->failOn = 'insert into "payment_webhook_events"';
        $this->send($obj)->assertOk()->assertJson(['outcome' => 'processed']);
        $this->failOn = null;
        $b = $this->books($club, $sale, $intent);
        $this->assertSame([1, Invoice::PAID, true, PaymentIntent::PAID, []], [$b['payments'], $b['invoice'], $b['order_paid'], $b['intent'], $b['events']]);

        $this->send($obj)->assertOk()->assertJson(['outcome' => 'duplicate']);
        $this->assertSame(1, $this->books($club, $sale, $intent)['payments']);
    }

    public function test_a_step_failing_after_the_commit_leaves_the_payment_recorded_and_answered_as_recorded(): void
    {
        $club = $this->club();
        [$sale, $intent] = $this->onlineOrder($club, $this->shop($club));
        $obj = $this->obj($intent, 910004);

        $fail = true;
        Event::listen(InvoicePaid::class, function () use (&$fail) {
            if ($fail) {
                DB::afterCommit(fn () => throw new RuntimeException('a step after the commit failed'));
            }
        });

        $this->send($obj)->assertOk()->assertJson(['ok' => true, 'outcome' => 'recorded_with_error']);
        $fail = false;
        $this->assertSame(['payments' => 1, 'credit' => '0.000', 'invoice' => Invoice::PAID, 'order_paid' => true, 'intent' => PaymentIntent::PAID,
            'events' => ['recorded_with_error'], 'books' => true], $this->books($club, $sale, $intent), 'recorded, and never shown as «not recorded»');

        $this->send($obj)->assertOk()->assertJson(['outcome' => 'duplicate']);
        $this->send($obj)->assertOk()->assertJson(['outcome' => 'duplicate']);
        $b = $this->books($club, $sale, $intent);
        $this->assertSame([1, '0.000', Invoice::PAID], [$b['payments'], $b['credit'], $b['invoice']], 'no second payment, no credit, the invoice paid once');

        $this->actingAs($this->owner($club))->get(route('club.integrations.paymob'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('events.2.ok', true)->where('events.2.outcome', fn ($label) => str_contains($label, 'سُجّلت الدفعة')));
    }

    public function test_a_whatsapp_message_the_queue_refuses_after_the_commit_does_not_touch_the_payment_and_is_retried_once(): void
    {
        $club = $this->club();
        [$sale, $intent] = $this->onlineOrder($club, $this->shop($club));
        $obj = $this->obj($intent, 910005);

        // Written with the payment, as a receipt is, and handed to the queue after the commit, which refuses it.
        $this->deadConnection();
        Event::listen(InvoicePaid::class, function (InvoicePaid $event) {
            $message = Message::create(['direction' => 'outbound', 'source' => Message::SOURCE_PLATFORM, 'type' => 'template', 'status' => Message::QUEUED,
                'template_key' => 'payment_receipt', 'related_type' => 'invoice', 'related_id' => $event->invoice->id]);
            config(['queue.default' => 'dead']);
            WhatsAppService::queueAfterCommit($message);
        });

        $this->send($obj)->assertOk()->assertJson(['outcome' => 'processed']);
        config(['queue.default' => 'sync']);
        $b = $this->books($club, $sale, $intent);
        $this->assertSame([1, Invoice::PAID, true, PaymentIntent::PAID, ['processed']], [$b['payments'], $b['invoice'], $b['order_paid'], $b['intent'], $b['events']]);

        $message = $this->asTenant($club, fn () => Message::sole());
        $this->assertSame(Message::SCHEDULED, $message->status, 'put back to be handed over again');
        $this->assertTrue($message->scheduled_at->lte(now()));

        // The every-minute run hands it over once; a second run finds nothing to hand over.
        Queue::fake();
        Artisan::call('messaging:dispatch-scheduled');
        Artisan::call('messaging:dispatch-scheduled');
        Queue::assertPushed(SendWhatsAppMessage::class, 1);
        Queue::assertPushed(SendWhatsAppMessage::class, fn (SendWhatsAppMessage $job) => $job->messageId === $message->id);
        $this->assertSame(Message::QUEUED, $this->asTenant($club, fn () => $message->fresh()->status));
        $this->assertSame(1, $this->asTenant($club, fn () => Payment::count()), 'the retry is the message\'s, never the payment\'s');
    }

    public function test_the_scheduled_run_meeting_a_refusing_queue_keeps_the_message_scheduled(): void
    {
        $club = $this->club();
        $message = $this->asTenant($club, fn () => Message::create(['direction' => 'outbound', 'source' => Message::SOURCE_PLATFORM, 'type' => 'template',
            'status' => Message::SCHEDULED, 'scheduled_at' => now()->subMinute(), 'template_key' => 'payment_receipt']));

        $this->deadConnection();
        config(['queue.default' => 'dead']);
        $this->assertSame(0, Artisan::call('messaging:dispatch-scheduled'));
        config(['queue.default' => 'sync']);

        $this->assertSame(Message::SCHEDULED, $this->asTenant($club, fn () => $message->fresh()->status), 'still waiting to be handed over');
    }

    public function test_a_tampered_unsigned_or_another_orders_callback_records_nothing(): void
    {
        $club = $this->club();
        $bar = $this->shop($club);
        [$saleA, $intentA] = $this->onlineOrder($club, $bar);
        $this->send($this->obj($intentA, 910006))->assertOk()->assertJson(['outcome' => 'processed']);

        // A's genuine signed callback replayed with B's reference (the reference is not signed): B's checkout has its own order.
        [$saleB, $intentB] = $this->onlineOrder($club, $bar);
        $replay = $this->obj($intentA, 910006);
        $replay['order']['merchant_order_id'] = $intentB->reference;
        $this->send($replay, PaymobSignature::compute($this->obj($intentA, 910006), 'hmac-test'))->assertOk()->assertJson(['outcome' => 'order_mismatch']);
        $this->assertSame([PaymentIntent::PENDING, false, 1], $this->asTenant($club, fn () => [$intentB->fresh()->status, $saleB->fresh()->paid_at !== null, Payment::count()]));

        // A signed field changed (the amount), or no signature at all.
        $tampered = $this->obj($intentB, 910007);
        $hmac = PaymobSignature::compute($tampered, 'hmac-test');
        $tampered['amount_cents'] = 1;
        $this->send($tampered, $hmac)->assertStatus(401);
        $this->send($this->obj($intentB, 910008), '')->assertStatus(401);
        $this->assertSame(1, $this->asTenant($club, fn () => Payment::count()));
    }
}
