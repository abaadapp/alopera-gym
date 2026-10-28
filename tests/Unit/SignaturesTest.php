<?php

namespace Tests\Unit;

use App\Modules\Messaging\Gateways\MetaCloudGateway;
use App\Modules\Payments\Paymob\PaymobSignature;
use PHPUnit\Framework\TestCase;

class SignaturesTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function paymobObj(): array
    {
        return [
            'id' => 192036465, 'pending' => false, 'amount_cents' => 25000, 'success' => true, 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false,
            'is_3d_secure' => true, 'integration_id' => 4097558, 'has_parent_transaction' => false,
            'order' => ['id' => 217503754, 'merchant_order_id' => 'PI-REF'], 'created_at' => '2026-10-28T10:00:00.000000',
            'currency' => 'OMR', 'error_occured' => false, 'owner' => 302852,
            'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'],
        ];
    }

    public function test_paymob_hmac_concatenates_fields_in_paymob_order(): void
    {
        $obj = self::paymobObj();
        $flat = '25000'.'2026-10-28T10:00:00.000000'.'OMR'.'false'.'false'.'192036465'.'4097558'.'true'.'false'.'false'.'false'.'true'
            .'false'.'217503754'.'302852'.'false'.'2346'.'MasterCard'.'card'.'true';

        $this->assertSame(hash_hmac('sha512', $flat, 'secret'), PaymobSignature::compute($obj, 'secret'));
    }

    public function test_paymob_verification_rejects_tampering_and_missing_secret(): void
    {
        $obj = self::paymobObj();
        $hmac = PaymobSignature::compute($obj, 'secret');

        $this->assertTrue(PaymobSignature::verify($obj, strtoupper($hmac), 'secret'));
        $this->assertFalse(PaymobSignature::verify(['amount_cents' => 1] + $obj, $hmac, 'secret'));
        $this->assertFalse(PaymobSignature::verify($obj, $hmac, 'other-secret'));
        $this->assertFalse(PaymobSignature::verify($obj, $hmac, null));
        $this->assertFalse(PaymobSignature::verify($obj, '', 'secret'));
    }

    public function test_meta_webhook_signature(): void
    {
        $body = '{"entry":[]}';
        $header = 'sha256='.hash_hmac('sha256', $body, 'app-secret');

        $this->assertTrue(MetaCloudGateway::verifySignature($header, $body, 'app-secret'));
        $this->assertFalse(MetaCloudGateway::verifySignature($header, $body.' ', 'app-secret'));
        $this->assertFalse(MetaCloudGateway::verifySignature($header, $body, null));
        $this->assertFalse(MetaCloudGateway::verifySignature(null, $body, 'app-secret'));
    }
}
