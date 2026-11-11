<?php

namespace Tests\Feature;

use App\Modules\Payments\Models\PaymentWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Paymob callback that is not signed, or names no payment we know, is logged by what identifies it (type, transaction,
 * order, amount, result), not by its whole body: anyone may post to the webhook, and a body of their choosing (nginx
 * takes up to 20 MB, 300 a minute) was stored as sent.
 */
class PaymobUnsignedCallbackStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_callback_for_no_known_payment_is_logged_without_its_body(): void
    {
        $this->postJson('/webhooks/paymob', [
            'type' => 'TRANSACTION',
            'obj' => ['id' => 9911, 'amount_cents' => 1500, 'success' => true, 'order' => ['id' => 4411, 'merchant_order_id' => 'NOPE'], 'junk' => str_repeat('A', 200_000)],
            'junk' => str_repeat('B', 200_000),
        ])->assertNotFound();

        $event = PaymentWebhookEvent::sole();
        $this->assertSame('unknown_intent', $event->outcome);
        $this->assertLessThan(2_000, strlen(json_encode($event->payload)), 'the body is not kept');
        $this->assertSame(['type' => 'TRANSACTION', 'obj' => ['id' => 9911, 'amount_cents' => 1500, 'success' => true, 'order' => ['id' => 4411, 'merchant_order_id' => 'NOPE']], 'trimmed' => true], $event->payload);
    }

    public function test_a_failed_result_is_kept_in_the_log(): void
    {
        $this->postJson('/webhooks/paymob', ['type' => 'TRANSACTION', 'obj' => ['id' => 9912, 'success' => false, 'pending' => false, 'order' => ['merchant_order_id' => 'NOPE-2']]])->assertNotFound();

        $this->assertSame(['id' => 9912, 'success' => false, 'pending' => false, 'order' => ['merchant_order_id' => 'NOPE-2']], PaymentWebhookEvent::sole()->payload['obj']);
    }
}
