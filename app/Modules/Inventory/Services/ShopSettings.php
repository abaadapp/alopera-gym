<?php

namespace App\Modules\Inventory\Services;

use App\Core\Auth\PermissionRegistry;
use App\Models\Tenant;
use App\Modules\Inventory\Models\Product;
use App\Modules\Payments\Services\OnlinePaymentService;

/**
 * A club's sales settings (tenant settings "sales") with their defaults, and what the website shop offers right
 * now. Online payment is offered only when Paymob is really set up for the club — never a promise it can't keep.
 */
final class ShopSettings
{
    public const DEFAULTS = [
        'shop_enabled' => true,          // the website shop appears as soon as a product is shown there
        'pickup_enabled' => true,        // order online, pay and collect at the club
        'online_payment_enabled' => true, // pay online with Paymob, when the club has it set up
        'payment_window_minutes' => 30,  // stock held for an unpaid online-payment order
        'pickup_note' => null,
    ];

    public function __construct(private readonly OnlinePaymentService $online) {}

    /** @return array{shop_enabled: bool, pickup_enabled: bool, online_payment_enabled: bool, payment_window_minutes: int, pickup_note: ?string} */
    public static function of(Tenant $tenant): array
    {
        $stored = (array) $tenant->setting('sales', []);

        return [
            'shop_enabled' => (bool) ($stored['shop_enabled'] ?? self::DEFAULTS['shop_enabled']),
            'pickup_enabled' => (bool) ($stored['pickup_enabled'] ?? self::DEFAULTS['pickup_enabled']),
            'online_payment_enabled' => (bool) ($stored['online_payment_enabled'] ?? self::DEFAULTS['online_payment_enabled']),
            'payment_window_minutes' => max(10, min(1440, (int) ($stored['payment_window_minutes'] ?? self::DEFAULTS['payment_window_minutes']))),
            'pickup_note' => $stored['pickup_note'] ?? null,
        ];
    }

    /** Is the shop open on this club's website? (module on, shop on, at least one product shown) */
    public function shopOpen(Tenant $tenant): bool
    {
        return PermissionRegistry::moduleEnabled('sales')
            && self::of($tenant)['shop_enabled']
            && Product::onWebsite()->exists();
    }

    /**
     * The ways a customer can pay for a website order right now, in display order.
     *
     * @return list<string> paymob|pickup
     */
    public function paymentMethods(Tenant $tenant): array
    {
        $settings = self::of($tenant);

        return array_values(array_filter([
            $settings['online_payment_enabled'] && $this->online->available() ? 'paymob' : null,
            $settings['pickup_enabled'] ? 'pickup' : null,
        ]));
    }
}
