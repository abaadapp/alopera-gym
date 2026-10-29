<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Documents\Models\DocumentAlert;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The panel's frame, second stage: the branch picker narrows the pages and is enforced on the server; the bell keeps
 * read state, opens and marks notifications, never repeats one and never shows another club's; «حسابي»; the quick
 * search by permission; and the dashboard's comparisons and the user's own tiles.
 */
class PanelChromeTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{0: Branch, 1: Branch} */
    private function twoBranches(Tenant $club): array
    {
        return $this->asTenant($club, fn () => [
            Branch::create(['name' => 'فرع الخوض', 'is_active' => true]),
            Branch::create(['name' => 'فرع صلالة', 'is_active' => true]),
        ]);
    }

    /** @return array<string, mixed> */
    private function props(User $user, string $route, array $params = []): array
    {
        $props = [];
        $this->actingAs($user)->get(route($route, $params))->assertOk()->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    }

    private function websiteOrder(Tenant $club, ?int $branch = null): Sale
    {
        return $this->asTenant($club, function () use ($branch) {
            $product = Product::create(['name' => 'Protein bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($product, 10, 'adjustment', null, 'opening', 0.8);

            return app(OrderService::class)->place([['product_id' => $product->id, 'quantity' => 1]], ['name' => 'سالم', 'phone' => '96891234567'], 'pickup', null, $branch);
        });
    }

    // ─── Branches ─────────────────────────────────────────────────────────

    public function test_a_club_wide_user_switches_branch_and_the_lists_follow(): void
    {
        $club = $this->club();
        [$khoud, $salalah] = $this->twoBranches($club);
        $owner = $this->owner($club);
        $this->member($club, ['full_name' => 'عضو الخوض', 'branch_id' => $khoud->id]);
        $this->member($club, ['full_name' => 'عضو صلالة', 'branch_id' => $salalah->id]);
        $this->member($club, ['full_name' => 'عضو بلا فرع']);

        $props = $this->props($owner, 'club.members.index');
        $this->assertCount(3, $props['members']['data']);
        $this->assertNull($props['auth']['branch']['current']);
        $this->assertCount(3, $props['auth']['branch']['options'], 'the main branch and the two new ones');

        $this->actingAs($owner)->post(route('club.branch.switch'), ['branch_id' => $khoud->id])->assertSessionHas('success');
        $props = $this->props($owner, 'club.members.index');
        $this->assertSame('فرع الخوض', $props['auth']['branch']['current']['name']);
        $this->assertSame(['عضو الخوض'], array_column($props['members']['data'], 'full_name'));

        // The dashboard follows too.
        $this->assertSame(1, $this->props($owner, 'club.dashboard')['kpis']['new_members_month']);

        // A record without a branch counts as the main branch's: it shows there and nowhere else.
        $main = $this->asTenant($club, fn () => Branch::where('is_main', true)->first());
        $this->actingAs($owner)->post(route('club.branch.switch'), ['branch_id' => $main->id]);
        $this->assertSame(['عضو بلا فرع'], array_column($this->props($owner, 'club.members.index')['members']['data'], 'full_name'));

        // «All branches» brings everything back.
        $this->actingAs($owner)->post(route('club.branch.switch'), ['branch_id' => null]);
        $this->assertCount(3, $this->props($owner, 'club.members.index')['members']['data']);
    }

    public function test_a_branch_tied_user_cannot_leave_their_branch_and_no_one_picks_another_clubs_branch(): void
    {
        $club = $this->club();
        [$khoud, $salalah] = $this->twoBranches($club);
        $this->member($club, ['full_name' => 'عضو الخوض', 'branch_id' => $khoud->id]);
        $this->member($club, ['full_name' => 'عضو صلالة', 'branch_id' => $salalah->id]);
        $reception = $this->staff($club, 'reception', ['branch_id' => $khoud->id]);

        $this->actingAs($reception)->post(route('club.branch.switch'), ['branch_id' => $salalah->id])->assertForbidden();
        $props = $this->props($reception, 'club.members.index');
        $this->assertTrue($props['auth']['branch']['locked']);
        $this->assertSame([], $props['auth']['branch']['options']);
        $this->assertSame(['عضو الخوض'], array_column($props['members']['data'], 'full_name'));

        // Another club's branch: refused by validation, and a stale choice in the session is ignored.
        $other = $this->club('B');
        $foreign = $this->asTenant($other, fn () => Branch::create(['name' => 'فرع آخر', 'is_active' => true]));
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.branch.switch'), ['branch_id' => $foreign->id])->assertSessionHasErrors('branch_id');
        $this->actingAs($owner)->withSession(['club_branch.'.$club->id => $foreign->id])->get(route('club.members.index'))
            ->assertInertia(fn ($page) => $page->where('auth.branch.current', null)->has('members.data', 2));
    }

    // ─── Notifications ────────────────────────────────────────────────────

    public function test_a_website_order_reaches_who_handles_orders_once_and_is_resolved_when_handled(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $reception = $this->staff($club, 'reception');
        $trainer = $this->staff($club, 'trainer');
        $sale = $this->websiteOrder($club);

        foreach ([$owner, $reception] as $user) {
            $bell = $this->props($user, 'club.notifications.index')['notifications'];
            $this->assertSame(1, $bell['unread']);
            $this->assertStringContainsString($sale->number, $bell['items'][0]['text']);
        }
        $this->assertSame(0, $this->props($trainer, 'club.notifications.index')['notifications']['unread']);

        // The hourly re-check does not repeat it.
        Artisan::call('notifications:sync');
        Artisan::call('notifications:sync');
        $this->assertSame(1, $this->asTenant($club, fn () => StaffNotification::where('user_id', $owner->id)->where('key', 'order:'.$sale->id)->count()));

        // Preparing the order resolves it for everyone.
        $this->asTenant($club, fn () => app(OrderService::class)->advance($sale, 'preparing'));
        $this->assertSame(0, $this->props($reception, 'club.notifications.index')['unread']);
    }

    public function test_open_marks_read_and_leads_to_its_screen_and_mark_all_read(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->websiteOrder($club);
        $this->websiteOrder($club);
        $ids = $this->asTenant($club, fn () => StaffNotification::where('user_id', $owner->id)->orderBy('id')->pluck('id')->all());
        $this->assertCount(2, $ids);

        $this->actingAs($owner)->get(route('club.notifications.open', $ids[0]))->assertRedirect(route('club.sales.orders.index'));
        $this->assertSame(1, $this->props($owner, 'club.notifications.index')['unread']);
        $this->assertCount(1, $this->props($owner, 'club.notifications.index', ['view' => 'unread'])['list']['data']);

        $this->actingAs($owner)->post(route('club.notifications.read-all'))->assertSessionHas('success');
        $this->assertSame(0, $this->props($owner, 'club.notifications.index')['unread']);
    }

    public function test_notifications_stay_with_their_owner_and_their_club(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $ownerA = $this->owner($a);
        $ownerB = $this->owner($b);
        $this->websiteOrder($a);
        $id = $this->asTenant($a, fn () => StaffNotification::where('user_id', $ownerA->id)->value('id'));

        $this->assertSame(0, $this->props($ownerB, 'club.notifications.index')['notifications']['unread']);
        $this->actingAs($ownerB)->get(route('club.notifications.open', $id))->assertNotFound();
        $this->actingAs($ownerB)->post(route('club.notifications.read', $id))->assertNotFound();
        $manager = $this->staff($a, 'manager');
        $this->actingAs($manager)->post(route('club.notifications.read', $id))->assertNotFound(); // someone else's row
        $this->assertNull($this->asTenant($a, fn () => StaffNotification::find($id)->read_at));
    }

    public function test_documents_payments_and_contact_messages_reach_only_who_may_act(): void
    {
        Storage::fake('local');
        $club = $this->club();
        $owner = $this->owner($club);
        $manager = $this->staff($club, 'manager');
        $finance = $this->staff($club, 'finance');
        $reception = $this->staff($club, 'reception');

        // A confidential document's alert: the owner only (the manager does not see confidential documents).
        $this->actingAs($owner)->post(route('club.business-documents.store'), [
            'name' => 'عقد الإيجار', 'expiry_date' => today()->addDays(5)->toDateString(), 'is_confidential' => true,
            'file' => UploadedFile::fake()->create('lease.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        Artisan::call('documents:alerts');
        $kinds = fn (User $u) => $this->asTenant($club, fn () => StaffNotification::where('user_id', $u->id)->pluck('kind')->all());
        $this->assertSame(['document'], $kinds($owner));
        $this->assertSame([], $kinds($manager));

        // A bank transfer waiting for confirmation: payments.confirm (finance, owner), not reception.
        $member = $this->member($club);
        $payment = $this->asTenant($club, function () use ($member) {
            $invoice = app(InvoiceService::class)->issue($member, [['description' => 'Fee', 'quantity' => 1, 'unit_price' => 10]]);

            return app(PaymentService::class)->record($invoice, 10, 'bank_transfer', ['reference' => 'TRX-1']);
        });
        $this->assertContains('payment', $kinds($finance));
        $this->assertNotContains('payment', $kinds($reception));
        $this->asTenant($club, fn () => app(PaymentService::class)->confirm($payment));
        $this->assertSame(0, $this->asTenant($club, fn () => StaffNotification::where('key', 'payment-pending:'.$payment->id)->whereNull('read_at')->count()));

        // A contact-form message: the website is the owner's.
        $this->post(route('site.contact.send', ['club' => $club->slug]), ['name' => 'زائر', 'phone' => '96890000000', 'message' => 'هل لديكم حصص صباحية؟']);
        $this->assertContains('contact', $kinds($owner));
        $this->assertNotContains('contact', $kinds($manager));
    }

    public function test_an_alert_seen_in_the_documents_section_is_read_in_the_bell(): void
    {
        Storage::fake('local');
        $club = $this->club();
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.business-documents.store'), [
            'name' => 'السجل التجاري', 'expiry_date' => today()->addDays(5)->toDateString(),
            'file' => UploadedFile::fake()->create('cr.pdf', 20, 'application/pdf'),
        ]);
        Artisan::call('documents:alerts');
        $alert = $this->asTenant($club, fn () => DocumentAlert::firstOrFail());
        $this->assertSame(1, $this->props($owner, 'club.notifications.index')['unread']);

        $this->actingAs($owner)->post(route('club.business-documents.alerts.acknowledge', $alert));
        $this->assertSame(0, $this->props($owner, 'club.notifications.index')['unread']);
    }

    // ─── Account ──────────────────────────────────────────────────────────

    public function test_my_account_updates_the_name_and_the_password_with_the_current_one(): void
    {
        $club = $this->club();
        $user = $this->staff($club, 'reception');
        $this->props($user, 'club.account.show');

        $this->actingAs($user)->put(route('club.account.update'), ['name' => 'ريم السالمية', 'phone' => '+968 9111 2222'])->assertSessionHasNoErrors();
        $this->assertSame('ريم السالمية', $user->fresh()->name);
        $this->assertSame('96891112222', $user->fresh()->phone);

        $this->actingAs($user)->put(route('club.account.password'), ['current_password' => 'wrong', 'password' => 'NewPass#2026x', 'password_confirmation' => 'NewPass#2026x'])
            ->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put(route('club.account.password'), ['current_password' => 'password', 'password' => 'NewPass#2026x', 'password_confirmation' => 'NewPass#2026x'])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('NewPass#2026x', $user->fresh()->password));
    }

    // ─── Search ───────────────────────────────────────────────────────────

    public function test_search_returns_only_what_the_user_may_open_with_arabic_letters_and_digits(): void
    {
        $club = $this->club();
        $this->member($club, ['full_name' => 'أحمد البلوشي', 'phone' => '96891234567']);
        $other = $this->club('B');
        $this->member($other, ['full_name' => 'أحمد الآخر']);

        $search = fn (User $u, string $q) => collect($this->actingAs($u)->getJson(route('club.search', ['q' => $q]))->assertOk()->json('groups'))->keyBy('key');

        $owner = $this->owner($club);
        $this->assertSame(['أحمد البلوشي'], array_column($search($owner, 'احمد')['members']['items'], 'label'), 'alef forms fold, other clubs never show');
        $this->assertSame(['أحمد البلوشي'], array_column($search($owner, '٩١٢٣٤')['members']['items'], 'label'), 'Arabic-Indic digits search phones');

        $this->assertTrue($search($this->staff($club, 'reception'), 'احمد')->has('members'));
        $this->assertTrue($search($this->staff($club, 'trainer'), 'احمد')->isEmpty(), 'the trainer sees no members');
        $this->actingAs($this->staff($club, 'trainer'))->getJson(route('club.search', ['q' => 'a']))->assertOk()->assertJson(['groups' => []]);
    }

    public function test_search_finds_invoices_and_website_orders_by_number(): void
    {
        $club = $this->club();
        $sale = $this->websiteOrder($club);
        $groups = collect($this->actingAs($this->owner($club))->getJson(route('club.search', ['q' => $sale->number]))->json('groups'))->keyBy('key');
        $this->assertStringContainsString($sale->number, $groups['sales']['items'][0]['label']);

        $invoice = $this->asTenant($club, fn () => $sale->invoice()->first());
        $groups = collect($this->actingAs($this->staff($club, 'reception'))->getJson(route('club.search', ['q' => $invoice->number]))->json('groups'))->keyBy('key');
        $this->assertStringContainsString($invoice->number, $groups['invoices']['items'][0]['label']);
        $this->assertArrayNotHasKey('sales', $groups->all(), 'reception does not see sales figures, and this number is an invoice');
    }

    public function test_search_finds_products_payments_and_employees_without_their_pay(): void
    {
        $club = $this->club();
        $this->asTenant($club, function () {
            Product::create(['name' => 'مشروب الطاقة', 'price' => 1.2, 'sku' => 'NRG-1']);
            Employee::create(['name' => 'سالمة الهنائي', 'phone' => '96899887766', 'basic_salary' => 735.5, 'iban' => 'OM810180000001299123456']);
        });
        $invoice = $this->asTenant($club, fn () => app(InvoiceService::class)->issue(null, [['description' => 'تأجير', 'quantity' => 1, 'unit_price' => 30]], ['type' => 'other'], 'شركة النور'));
        $payment = $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice, 30, 'cash'));

        $search = fn (User $u, string $q) => collect($this->actingAs($u)->getJson(route('club.search', ['q' => $q]))->assertOk()->json('groups'))->keyBy('key');
        $manager = $this->staff($club, 'manager');

        $this->assertSame(['مشروب الطاقة'], array_column($search($manager, 'مشروب')['products']['items'], 'label'));
        $this->assertSame(['مشروب الطاقة'], array_column($search($manager, 'nrg')['products']['items'], 'label'), 'by SKU');

        $employees = $search($manager, 'سالمه');
        $this->assertSame(['سالمة الهنائي'], array_column($employees['employees']['items'], 'label'), 'taa marbuta folds');
        $raw = $this->actingAs($manager)->getJson(route('club.search', ['q' => 'سالمة']))->getContent();
        $this->assertStringNotContainsString('735', $raw, 'no salary in search');
        $this->assertStringNotContainsString('OM81', $raw, 'no bank account in search');

        $finance = $this->staff($club, 'finance');
        $this->assertStringContainsString('شركة النور', $search($finance, (string) $payment->receipt_number)['payments']['items'][0]['label']);
        $this->assertSame(route('club.invoices.show', $invoice), $search($finance, 'النور')['payments']['items'][0]['url']);

        // Reception collects payments but manages neither products nor staff files.
        $reception = $search($this->staff($club, 'reception'), 'سالمة');
        $this->assertArrayNotHasKey('employees', $reception->all());
        $this->assertArrayNotHasKey('products', $search($this->staff($club, 'reception'), 'مشروب')->all());
    }

    public function test_the_bell_refreshes_lightly_and_only_with_the_users_own_rows(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $reception = $this->staff($club, 'reception');
        $trainer = $this->staff($club, 'trainer');
        $this->websiteOrder($club);

        $feed = $this->actingAs($reception)->getJson(route('club.notifications.feed'))->assertOk()->json();
        $this->assertSame(1, $feed['unread']);
        $this->assertCount(1, $feed['items']);

        // Nothing changed since what the bell shows: no items travel.
        $again = $this->actingAs($reception)->getJson(route('club.notifications.feed', ['since' => $feed['latest'], 'unread' => 1]))->json();
        $this->assertNull($again['items']);

        // Read elsewhere: the count changes, and the items come again with their new state.
        $this->actingAs($owner)->post(route('club.notifications.read-all'));
        $this->actingAs($reception)->post(route('club.notifications.read-all'));
        $after = $this->actingAs($reception)->getJson(route('club.notifications.feed', ['since' => $feed['latest'], 'unread' => 1]))->json();
        $this->assertSame(0, $after['unread']);
        $this->assertTrue($after['items'][0]['read']);

        $this->assertSame(['unread' => 0, 'latest' => 0, 'items' => []], $this->actingAs($trainer)->getJson(route('club.notifications.feed'))->json());
    }

    // ─── Dashboard tiles ──────────────────────────────────────────────────

    public function test_comparisons_show_only_with_an_earlier_period_to_compare_with(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->assertSame([], $this->props($owner, 'club.dashboard')['trends'], 'a new club has nothing to compare with');

        $member = $this->member($club);
        $this->asTenant($club, function () use ($member) {
            foreach ([now()->subWeek(), now()->subWeek(), now()] as $at) {
                CheckIn::create(['member_id' => $member->id, 'method' => 'qr', 'direction' => 'in', 'result' => 'allowed', 'checked_in_at' => $at->copy()->subMinute()]);
            }
        });
        $trend = $this->props($owner, 'club.dashboard')['trends']['checkins_today'];
        $this->assertSame(-50.0, (float) $trend['change']);
        $this->assertSame('عن نفس الوقت قبل أسبوع', $trend['label']);
    }

    public function test_each_user_keeps_their_own_tile_order_and_hidden_tiles(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $manager = $this->staff($club, 'manager');

        $this->actingAs($owner)->put(route('club.dashboard.tiles'), ['order' => ['checkins_today', 'active_members'], 'hidden' => ['frozen_members']])->assertSessionHasNoErrors();
        $tiles = $this->props($owner, 'club.dashboard')['tiles'];
        $this->assertSame(['checkins_today', 'active_members'], array_slice($tiles['order'], 0, 2));
        $this->assertCount(8, $tiles['order'], 'tiles not in the saved order follow at the end');
        $this->assertSame(['frozen_members'], $tiles['hidden']);

        $this->assertSame([], $this->props($manager, 'club.dashboard')['tiles']['hidden'], 'another user keeps the defaults');
        $this->actingAs($owner)->put(route('club.dashboard.tiles'), ['order' => ['nope'], 'hidden' => []])->assertSessionHasErrors('order.0');
    }

    public function test_the_bell_is_only_in_the_club_panel(): void
    {
        $club = $this->club();
        $this->actingAs($this->owner($club))->get(route('site.home', ['club' => $club->slug]))
            ->assertInertia(fn ($page) => $page->where('notifications', null));
    }
}
