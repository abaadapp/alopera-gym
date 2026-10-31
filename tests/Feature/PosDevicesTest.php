<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\PosDevice;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\PosDevices;
use App\Modules\Inventory\Services\PosService;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Point-of-sale devices: added with a one-time pairing code (only hashes kept), paired on the device (an encrypted
 * cookie), recorded on every sale with its branch, cut off when revoked or moved, required when the club says so,
 * a retried checkout is one sale, peripherals, permissions apart from selling, and isolation between clubs.
 */
class PosDevicesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function product(Tenant $club, int $branch, int $qty = 10): Product
    {
        return $this->asTenant($club, function () use ($branch, $qty) {
            $product = Product::create(['name' => 'Water', 'price' => 0.5]);
            app(StockService::class)->move($product, $qty, 'adjustment', null, 'opening', 0.2, branchId: $branch);

            return $product;
        });
    }

    /** Add a device for a branch, pair it with its code, return the device and this browser's cookie value. */
    private function pairedDevice(Tenant $club, Branch $branch, ?int $settlement = null): array
    {
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.settings.pos-devices.store'), ['name' => 'كاشير', 'branch_id' => $branch->id, 'settlement_account_id' => $settlement])->assertSessionHas('pairing_code');
        $code = session('pairing_code')['code'];
        $device = $this->asTenant($club, fn () => PosDevice::latest('id')->firstOrFail());
        $this->assertSame([PosDevice::PENDING, hash('sha256', PosDevices::normalize($code))], [$device->status, $device->pairing_hash]);

        $response = $this->actingAs($this->staff($club, 'reception', ['branch_id' => $branch->id]))->post(route('club.pos.device.pair'), ['code' => strtolower($code)]);
        $response->assertRedirect(route('club.pos.index'));
        $cookie = $response->getCookie(PosDevices::COOKIE)->getValue();

        return [$device->fresh(), $cookie];
    }

    public function test_a_device_is_paired_with_a_one_time_code_and_every_sale_records_it_and_its_branch(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->asTenant($club, fn () => [Branch::firstOrFail(), Branch::create(['name' => 'فرع الخوض', 'is_active' => true])]);
        $water = $this->product($club, $khoud->id);
        $bank = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('bank'));
        [$device, $cookie] = $this->pairedDevice($club, $khoud, $bank->id);

        $this->assertSame(PosDevice::ACTIVE, $device->status);
        $this->assertNull($device->pairing_hash, 'the code works once');
        [$id, $token] = explode('|', $cookie);
        $this->assertSame([(string) $device->id, hash('sha256', $token)], [$id, $device->token_hash]);
        $this->assertNotSame($token, $device->token_hash, 'the secret itself is not stored');
        $this->actingAs($this->staff($club, 'reception'))->post(route('club.pos.device.pair'), ['code' => 'ABCD-EFGH'])->assertSessionHasErrors('code');

        // A club-wide cashier on the Al Khoud device sells from Al Khoud's shelf, whatever the top bar shows.
        $cashier = $this->staff($club, 'reception');
        $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($cashier)->get(route('club.pos.index'))->assertInertia(fn ($page) => $page->where('device.name', 'كاشير')->where('products.0.stock_quantity', '10.000'));
        $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($cashier)->post(route('club.pos.checkout'), ['items' => [['product_id' => $water->id, 'quantity' => 1]], 'payment_method' => 'card', 'card_reference' => 'T1'])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($device, $khoud, $cashier, $bank, $water) {
            $sale = Sale::sole();
            $this->assertSame([$device->id, $khoud->id, $cashier->id, $bank->id], [$sale->pos_device_id, $sale->branch_id, $sale->cashier_id, $sale->settlement_account_id]);
            $this->assertSame('9.000', app(StockService::class)->quantity($water, $khoud->id));
            $this->assertNotNull($device->fresh()->last_seen_at);
        });

        // A cashier tied to the main branch may not sell on an Al Khoud device.
        $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($this->staff($club, 'reception', ['branch_id' => $main->id]))->post(route('club.pos.checkout'), ['items' => [['product_id' => $water->id, 'quantity' => 1]], 'payment_method' => 'cash'])->assertSessionHasErrors();
    }

    public function test_a_revoked_or_moved_device_is_cut_off_and_another_clubs_cookie_means_nothing(): void
    {
        $club = $this->club();
        $branch = $this->asTenant($club, fn () => Branch::firstOrFail());
        [$device, $cookie] = $this->pairedDevice($club, $branch);
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.settings.pos-devices.revoke', $device), ['reason' => 'lost'])->assertSessionHasNoErrors();
        $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($owner)->get(route('club.pos.index'))->assertInertia(fn ($page) => $page->where('device', null));
        $this->asTenant($club, fn () => $this->assertSame([PosDevice::REVOKED, null], [$device->fresh()->status, $device->fresh()->token_hash]));

        // A new code pairs a replacement; the old cookie stays dead.
        $this->actingAs($owner)->post(route('club.settings.pos-devices.code', $device))->assertSessionHas('pairing_code');
        $new = $this->actingAs($owner)->post(route('club.pos.device.pair'), ['code' => session('pairing_code')['code']])->getCookie(PosDevices::COOKIE)->getValue();
        $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($owner)->get(route('club.pos.index'))->assertInertia(fn ($page) => $page->where('device', null));
        $this->withCookie(PosDevices::COOKIE, $new)->actingAs($owner)->get(route('club.pos.index'))->assertInertia(fn ($page) => $page->where('device.name', 'كاشير'));

        // Moving it to another branch revokes it until it is paired there.
        $other = $this->asTenant($club, fn () => Branch::create(['name' => 'صلالة', 'is_active' => true]));
        $this->actingAs($owner)->put(route('club.settings.pos-devices.update', $device), ['name' => 'كاشير', 'branch_id' => $other->id])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([PosDevice::REVOKED, $other->id], [$device->fresh()->status, $device->fresh()->branch_id]));

        // Another club's device cookie is nothing here.
        $club2 = $this->club('Other');
        [, $theirs] = $this->pairedDevice($club2, $this->asTenant($club2, fn () => Branch::firstOrFail()));
        $this->withCookie(PosDevices::COOKIE, $theirs)->actingAs($owner)->get(route('club.pos.index'))->assertInertia(fn ($page) => $page->where('device', null));
        $this->actingAs($owner)->get(route('club.settings.pos-devices'))->assertInertia(fn ($page) => $page->has('devices', 1));
    }

    public function test_the_club_can_require_a_device_and_a_retried_checkout_is_one_sale(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $club = $this->club();
        $branch = $this->asTenant($club, fn () => Branch::firstOrFail());
        $water = $this->product($club, $branch->id);
        $owner = $this->owner($club);
        $sell = ['items' => [['product_id' => $water->id, 'quantity' => 1]], 'payment_method' => 'cash'];

        // Without the policy, any browser sells; the same basket key twice is one sale, one invoice, one payment.
        $key = (string) Str::uuid();
        $this->actingAs($owner)->post(route('club.pos.checkout'), $sell + ['client_key' => $key])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.pos.checkout'), $sell + ['client_key' => $key])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([1, 1, 1, '9.000'], [Sale::count(), Invoice::count(), Payment::count(), $water->fresh()->stock_quantity]));

        // With it, only an activated device sells.
        $this->actingAs($owner)->put(route('club.settings.pos-devices.policy'), ['require_device' => true])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.pos.checkout'), $sell)->assertSessionHasErrors();
        $this->actingAs($owner)->get(route('club.pos.index'))->assertInertia(fn ($page) => $page->where('requireDevice', true)->where('device', null));
        [, $cookie] = $this->pairedDevice($club, $branch);
        $this->withCookie(PosDevices::COOKIE, $cookie)->actingAs($owner)->post(route('club.pos.checkout'), $sell)->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(2, Sale::count()));

        // Saving the shop settings keeps the device policy.
        $this->actingAs($owner)->put(route('club.settings.sales.update'), ['shop_enabled' => true, 'pickup_enabled' => true, 'online_payment_enabled' => false, 'payment_window_minutes' => 30])->assertSessionHasNoErrors();
        $this->defaultCookies = []; // a browser that is not the device
        $this->actingAs($owner)->post(route('club.pos.checkout'), $sell)->assertSessionHasErrors();
    }

    public function test_peripherals_keep_only_what_their_type_uses_and_devices_are_managed_apart_from_selling(): void
    {
        $club = $this->club();
        $branch = $this->asTenant($club, fn () => Branch::firstOrFail());
        [$device] = $this->pairedDevice($club, $branch);
        $owner = $this->owner($club);
        $url = route('club.settings.pos-devices.peripherals.store', $device);

        $this->actingAs($owner)->post($url, ['type' => 'printer', 'name' => 'Front printer', 'connection' => 'network', 'paper_width' => 58])->assertSessionHasErrors('address');
        $this->actingAs($owner)->post($url, ['type' => 'printer', 'name' => 'Front printer', 'brand' => 'Epson', 'model' => 'TM-T20', 'connection' => 'network', 'address' => '192.168.1.50', 'port' => 9100, 'paper_width' => 58, 'is_default' => true, 'auto_print' => true])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($url, ['type' => 'drawer', 'name' => 'Drawer', 'connection' => 'usb', 'address' => '10.0.0.1', 'paper_width' => 80, 'auto_print' => true])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($device) {
            [$printer, $drawer] = [$device->peripherals()->where('type', 'printer')->sole(), $device->peripherals()->where('type', 'drawer')->sole()];
            $this->assertSame(['192.168.1.50', 58, true], [$printer->address, $printer->paper_width, $printer->auto_print]);
            $this->assertSame([null, null, false], [$drawer->address, $drawer->paper_width, $drawer->auto_print]);
        });
        $printer = $this->asTenant($club, fn () => $device->peripherals()->where('type', 'printer')->first());
        $this->actingAs($owner)->get(route('club.settings.pos-devices.peripherals.test', [$device, $printer]))->assertOk()->assertInertia(fn ($page) => $page->where('printer.paper_width', 58));

        // The receipt follows the device printer's paper width.
        $water = $this->product($club, $branch->id);
        $sale = $this->asTenant($club, fn () => app(PosService::class)->checkout([['product_id' => $water->id, 'quantity' => 1]], 'cash', device: $device->fresh()));
        $this->actingAs($owner)->get(route('club.sales.receipt', $sale))->assertOk()->assertSee('size: 58mm auto', false)->assertDontSee('size: 80mm auto', false);

        // Reception sells but does not manage devices.
        $reception = $this->staff($club, 'reception');
        $this->actingAs($reception)->get(route('club.settings.pos-devices'))->assertForbidden();
        $this->actingAs($reception)->post(route('club.settings.pos-devices.revoke', $device))->assertForbidden();
        $this->actingAs($reception)->post(route('club.pos.device.activate'), ['name' => 'X', 'branch_id' => $branch->id])->assertForbidden();
        $this->actingAs($this->staff($club, 'manager'))->get(route('club.settings.pos-devices'))->assertOk();
    }

    public function test_a_busy_minute_of_stock_work_does_not_lock_the_user_out_of_pairing_a_till(): void
    {
        $club = $this->club();
        $branch = $this->asTenant($club, fn () => Branch::firstOrFail());
        $water = $this->product($club, $branch->id, 100);
        $owner = $this->owner($club);
        // Twelve stock adjustments in a minute (each limit has its own counter)…
        foreach (range(1, 12) as $i) {
            $this->actingAs($owner)->post(route('club.inventory.adjust'), ['product_id' => $water->id, 'branch_id' => $branch->id, 'direction' => 'out', 'quantity' => 1, 'reason' => 'internal'])->assertSessionHasNoErrors();
        }
        // …and pairing still answers.
        $this->actingAs($owner)->post(route('club.settings.pos-devices.store'), ['name' => 'كاشير', 'branch_id' => $branch->id])->assertSessionHas('pairing_code');
        $this->actingAs($owner)->post(route('club.pos.device.pair'), ['code' => session('pairing_code')['code']])->assertRedirect(route('club.pos.index'));
    }
}
