<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductAddon;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The invoice editor's catalogue (owner's decision, 2026-11-21): products with their add-ons, membership plans, personal
 * training and programs on ONE customer invoice. Prices and names are the catalogue's, kept as they were on issue; a
 * plan and a package are sold to a member chosen only when paid (owner's decision, 2026-11-25): the invoice is paid in
 * full as it is issued and they are activated at once, once — on credit or part paid nothing is issued; a program needs
 * a price and books nothing; only products move stock.
 */
class InvoiceCatalogServicesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function holding(Tenant $club, array $permissions): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.Str::lower(Str::random(8)), 'name' => 'مخصص', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
    }

    /** @return array{shake: Product, scoop: ProductAddon, plan: MembershipPlan, pt: PtPackage} */
    private function catalogue(Tenant $club): array
    {
        return $this->asTenant($club, function () {
            $shake = Product::create(['name' => 'Protein shake', 'price' => 2]);
            app(StockService::class)->move($shake, 10, 'adjustment', null, 'opening', 0.8);
            $scoop = ProductAddon::create(['name' => 'Extra scoop', 'price' => 0.5, 'max_quantity' => 2, 'applies_to' => 'all']);

            return [
                'shake' => $shake->fresh(),
                'scoop' => $scoop->fresh(),
                'plan' => MembershipPlan::create(['name' => 'Monthly', 'type' => 'combo', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 25, 'signup_fee' => 5]),
                'pt' => PtPackage::create(['name' => 'PT 4', 'sessions_count' => 4, 'session_minutes' => 60, 'price' => 40, 'validity_days' => 60]),
            ];
        });
    }

    private function issue(User $by, array $lines, array $over = [])
    {
        return $this->actingAs($by)->post(route('club.invoices.store'), array_replace([
            'customer_mode' => 'member', 'issue' => 1, 'payment_method' => 'cash',
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(7)->toDateString(),
            'lines' => $lines, 'client_key' => (string) Str::uuid(),
        ], $over));
    }

    private function planLine(MembershipPlan $plan, array $over = []): array
    {
        return array_replace(['item_type' => 'plan', 'item_id' => $plan->id, 'description' => $plan->name, 'quantity' => 1, 'unit_price' => 1, 'discount' => 0], $over);
    }

    private function ptLine(PtPackage $pt, array $over = []): array
    {
        return array_replace(['item_type' => 'pt', 'item_id' => $pt->id, 'description' => $pt->name, 'quantity' => 1, 'unit_price' => 1, 'discount' => 0], $over);
    }

    public function test_one_invoice_carries_a_product_and_its_addon_a_membership_and_training_sold_paid_in_full_and_activated_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);
        $member = $this->member($club);
        $zumba = $this->program($club, ['price' => 3]);
        $lines = [
            ['product_id' => $c['shake']->id, 'description' => 'Protein shake', 'quantity' => 2, 'unit_price' => 2, 'discount' => 0, 'addons' => [['addon_id' => $c['scoop']->id, 'quantity' => 1]]],
            $this->planLine($c['plan']),
            $this->ptLine($c['pt']),
            ['item_type' => 'program', 'item_id' => $zumba->id, 'description' => 'Zumba', 'quantity' => 2, 'unit_price' => 99, 'discount' => 0],
        ];

        // Part paid, or on credit: refused on the payment method, nothing made, nothing off the shelf.
        $this->issue($owner, $lines, ['member_id' => $member->id, 'paid_amount' => '50'])->assertSessionHasErrors('payment_method');
        $this->issue($owner, $lines, ['member_id' => $member->id, 'payment_method' => 'credit'])->assertSessionHasErrors('payment_method');
        $this->assertSame([0, 0, 0, 10.0], $this->asTenant($club, fn () => [Invoice::count(), Membership::count(), PtSubscription::count(), (float) app(StockService::class)->quantities(null)[$c['shake']->id]]));

        $this->issue($owner, $lines, ['member_id' => $member->id])->assertSessionHasNoErrors();

        [$invoices, $invoice, $membership, $training] = $this->asTenant($club, fn () => [
            Invoice::count(), Invoice::with('items')->firstOrFail(), Membership::where('member_id', $member->id)->firstOrFail(), PtSubscription::where('member_id', $member->id)->firstOrFail(),
        ]);
        // One invoice for all of it: no invoice of the membership's or the package's own.
        $this->assertSame(1, $invoices);
        $this->assertSame('other', $invoice->type);
        // Paid in full on issue: both active at once.
        $this->assertSame([Invoice::PAID, '81.000'], [$invoice->status, $invoice->paid_total]);
        $this->assertSame([Membership::ACTIVE, $invoice->id], [$membership->status, $membership->invoice_id]);
        $this->assertSame([PtSubscription::ACTIVE, $invoice->id], [$training->status, $training->invoice_id]);
        // Every line, priced from the catalogue: 2×2 + 2×0.5 + 25 + 5 (first membership's signup) + 40 + 2×3 = 81.
        $this->assertSame([
            ['Protein shake', '2.000', '2.000'],
            ['+ Extra scoop — Protein shake', '2.000', '0.500'],
            ['اشتراك — Monthly (من '.today()->toDateString().' إلى '.$membership->ends_on->toDateString().')', '1.000', '25.000'],
            ['رسوم التسجيل', '1.000', '5.000'],
            ['تدريب شخصي — PT 4 (4 جلسات)', '1.000', '40.000'],
            ['حصة جماعية — Zumba', '2.000', '3.000'],
        ], $invoice->items->sortBy('id')->map(fn ($i) => [$i->description, $i->quantity, $i->unit_price])->values()->all());
        $this->assertSame('81.000', $invoice->total);
        // Only the product leaves the shelf; selling a class books nothing.
        $this->assertSame(8.0, $this->asTenant($club, fn () => (float) app(StockService::class)->quantities(null)[$c['shake']->id]));
        $this->assertSame(0, $this->asTenant($club, fn () => Booking::count()));

        // Once — the same «paid» again (a repeated Paymob callback) activates nothing twice.
        $this->asTenant($club, fn () => InvoicePaid::dispatch($invoice->fresh()));
        [$m, $t, $count, $activated] = $this->asTenant($club, fn () => [$membership->fresh(), $training->fresh(), Membership::where('member_id', $member->id)->count(), $membership->events()->where('type', 'activated')->count()]);
        $this->assertSame(Membership::ACTIVE, $m->status);
        $this->assertSame(PtSubscription::ACTIVE, $t->status);
        $this->assertSame([1, 1], [$count, $activated]);
    }

    public function test_names_and_prices_are_the_catalogues_and_stay_as_issued_when_the_catalogue_changes(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);
        $member = $this->member($club);
        $this->activeMembership($club, $member, $c['plan']); // not a first membership: no signup fee, a renewal after it

        $this->issue($owner, [$this->planLine($c['plan'], ['description' => 'مجانًا', 'unit_price' => 0.001, 'discount' => 2])], ['member_id' => $member->id])->assertSessionHasNoErrors();
        $invoice = $this->asTenant($club, fn () => Invoice::with('items')->latest('id')->firstOrFail());
        $this->assertSame('membership', $invoice->type); // a membership alone: posted as its own screen posts it
        $item = $invoice->items->sole();
        $this->assertStringStartsWith('تجديد — Monthly (من ', $item->description);
        $this->assertSame(['25.000', '2.000'], [$item->unit_price, $item->discount]);
        // A discount larger than the catalogue's price is refused on its line, whatever price the form sent.
        $this->asTenant($club, fn () => Membership::latest('id')->firstOrFail()->forceFill(['status' => Membership::CANCELLED])->save());
        $this->issue($owner, [$this->planLine($c['plan'], ['unit_price' => 500, 'discount' => 26])], ['member_id' => $member->id])->assertSessionHasErrors('lines.0.discount');

        $this->asTenant($club, fn () => $c['plan']->update(['name' => 'Renamed', 'price' => 99]));
        $this->assertSame([$item->description, '25.000'], $this->asTenant($club, fn () => [$item->fresh()->description, $item->fresh()->unit_price]));
        $this->actingAs($owner)->get(route('club.invoices.show', $invoice->id))->assertOk()->assertInertia(fn ($p) => $p->where('invoice.items.0.description', $item->description));
    }

    public function test_a_plan_or_package_is_sold_to_a_member_once_by_whoever_may_sell_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);
        $member = $this->member($club);

        // No member: refused on the line, nothing made.
        $this->issue($owner, [$this->planLine($c['plan'])], ['customer_mode' => 'walk_in'])->assertSessionHasErrors('lines.0.item_id');
        // A new customer is registered as a member by the invoice (InvoiceNewMemberTest): not without a phone to file them by.
        $this->issue($owner, [$this->ptLine($c['pt'])], ['customer_mode' => 'new', 'customer_name' => 'سارة'])->assertSessionHasErrors('customer_phone');
        // Twice, or more than one.
        $this->issue($owner, [$this->planLine($c['plan']), $this->planLine($c['plan'])], ['member_id' => $member->id])->assertSessionHasErrors('lines.0.item_id');
        $this->issue($owner, [$this->ptLine($c['pt']), $this->ptLine($c['pt'])], ['member_id' => $member->id])->assertSessionHasErrors('lines.0.item_id');
        $this->issue($owner, [$this->planLine($c['plan'], ['quantity' => 2])], ['member_id' => $member->id])->assertSessionHasErrors('lines.0.quantity');
        // Without the selling permission.
        $clerk = $this->holding($club, ['invoices.view', 'invoices.manage', 'payments.record']);
        $this->issue($clerk, [$this->planLine($c['plan'])], ['member_id' => $member->id])->assertSessionHasErrors('lines.0.item_id');
        $this->issue($clerk, [$this->ptLine($c['pt'])], ['member_id' => $member->id])->assertSessionHasErrors('lines.0.item_id');
        $this->actingAs($clerk)->get(route('club.invoices.create'))->assertInertia(fn ($p) => $p->where('services.plans', [])->where('services.pt', []));
        // Not on sale, or another club's.
        $this->asTenant($club, fn () => $c['pt']->update(['is_active' => false]));
        $this->issue($owner, [$this->ptLine($c['pt'])], ['member_id' => $member->id])->assertSessionHasErrors('lines.0.item_id');
        $other = $this->catalogue($this->club('Other'));
        $this->issue($owner, [$this->planLine($other['plan'])], ['member_id' => $member->id])->assertSessionHasErrors('lines.0.item_id');

        $this->assertSame([0, 0, 0], $this->asTenant($club, fn () => [Invoice::count(), Membership::count(), PtSubscription::count()]));

        // A membership already awaiting payment elsewhere: its sale's own refusal, on the line.
        $this->asTenant($club, fn () => app(MembershipService::class)->sell($member, $c['plan']));
        $this->issue($owner, [$this->planLine($c['plan'])], ['member_id' => $member->id])->assertSessionHasErrors('lines.0.item_id');
        $this->assertSame(1, $this->asTenant($club, fn () => Membership::count()));
    }

    public function test_a_program_is_billed_at_its_price_or_priced_by_whoever_manages_programs_and_books_nothing(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $clerk = $this->holding($club, ['invoices.view', 'invoices.manage']);
        $priced = $this->program($club, ['name' => 'Pilates', 'price' => '4.500']);
        $unpriced = $this->program($club, ['name' => 'Yoga', 'category' => 'program']);
        $line = fn ($p, $price) => ['item_type' => 'program', 'item_id' => $p->id, 'description' => $p->name, 'quantity' => 1, 'unit_price' => $price, 'discount' => 0];
        // On credit: a program is no subscription, so it may be owed (and the clerk takes no money).
        $walkIn = ['customer_mode' => 'walk_in', 'payment_method' => 'credit'];

        // Its price, whatever the form says; a walk-in may buy it.
        $this->issue($clerk, [$line($priced, 1)], $walkIn)->assertSessionHasNoErrors();
        $this->assertSame(['حصة جماعية — Pilates', '4.500'], $this->asTenant($club, fn () => Invoice::latest('id')->firstOrFail()->items()->get(['description', 'unit_price'])->map(fn ($i) => [$i->description, $i->unit_price])->sole()));

        // No price: refused for the clerk, priced by whoever manages programs (never at nothing).
        $this->issue($clerk, [$line($unpriced, 7)], $walkIn)->assertSessionHasErrors('lines.0.unit_price');
        $this->issue($owner, [$line($unpriced, 0)], $walkIn)->assertSessionHasErrors('lines.0.unit_price');
        $this->issue($owner, [$line($unpriced, 7)], $walkIn)->assertSessionHasNoErrors();
        $this->assertSame(['برنامج تدريبي — Yoga', '7.000'], $this->asTenant($club, fn () => Invoice::latest('id')->firstOrFail()->items()->get(['description', 'unit_price'])->map(fn ($i) => [$i->description, $i->unit_price])->sole()));
        $this->assertSame([2, 0], $this->asTenant($club, fn () => [Invoice::count(), Booking::count()]));
        $this->actingAs($clerk)->get(route('club.invoices.create'))->assertInertia(fn ($p) => $p->where('services.price_programs', false)->has('services.programs', 2));
    }

    public function test_a_renewal_sold_on_the_invoice_is_paid_and_scheduled_after_the_running_one_and_the_paid_invoice_is_not_voided(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);
        $member = $this->member($club);
        $running = $this->activeMembership($club, $member, $c['plan']);

        $this->issue($owner, [
            ['product_id' => $c['shake']->id, 'description' => 'Protein shake', 'quantity' => 1, 'unit_price' => 2, 'discount' => 0],
            $this->planLine($c['plan']), $this->ptLine($c['pt']),
        ], ['member_id' => $member->id])->assertSessionHasNoErrors();
        [$invoice, $renewal, $training] = $this->asTenant($club, fn () => [Invoice::latest('id')->firstOrFail(), Membership::latest('id')->firstOrFail(), PtSubscription::firstOrFail()]);
        // Sold paid: the renewal waits for the running membership to end — scheduled, not pending; the package active.
        $this->assertSame([Invoice::PAID, Membership::SCHEDULED, $running->ends_on->addDay()->toDateString(), PtSubscription::ACTIVE], [$invoice->status, $renewal->status, $renewal->starts_on->toDateString(), $training->status]);

        // Paid, the invoice is not voided (the money is refunded first): nothing withdrawn, the shake still sold.
        $this->asTenant($club, fn () => $this->assertThrows(fn () => app(InvoiceService::class)->void($invoice->fresh(), 'خطأ في الإصدار'), BusinessRuleException::class));
        $this->assertSame(
            [Invoice::PAID, Membership::SCHEDULED, PtSubscription::ACTIVE, Membership::ACTIVE, 9.0],
            $this->asTenant($club, fn () => [$invoice->fresh()->status, $renewal->fresh()->status, $training->fresh()->status, $running->fresh()->status, (float) app(StockService::class)->quantities(null)[$c['shake']->id]]),
        );
    }

    public function test_a_draft_keeps_the_services_as_chosen_makes_nothing_and_issues_them_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);
        $member = $this->member($club);

        $this->issue($owner, [$this->planLine($c['plan']), $this->ptLine($c['pt'])], ['member_id' => $member->id, 'issue' => 0])->assertSessionHasNoErrors();
        $draft = $this->asTenant($club, fn () => Invoice::drafts()->firstOrFail());
        $this->assertSame([0, 0, 0], $this->asTenant($club, fn () => [Membership::count(), PtSubscription::count(), JournalEntry::count()]));

        // Reopened: the plan once (its signup fee is its sale's, not a line), the package.
        $this->actingAs($owner)->get(route('club.invoices.drafts.edit', $draft->id))->assertOk()->assertInertia(fn ($p) => $p
            ->has('draft.lines', 2)
            ->where('draft.lines.0.item_type', 'plan')->where('draft.lines.0.item_id', (string) $c['plan']->id)
            ->where('draft.lines.1.item_type', 'pt'));

        $this->actingAs($owner)->post(route('club.invoices.drafts.update', $draft->id), [
            'customer_mode' => 'member', 'member_id' => $member->id, 'issue' => 1, 'payment_method' => 'cash',
            'issue_date' => today()->toDateString(), 'due_date' => today()->toDateString(),
            'lines' => [$this->planLine($c['plan']), $this->ptLine($c['pt'])], 'client_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        // Paid in full on issue: one invoice, both activated.
        $this->assertSame([1, Membership::ACTIVE, PtSubscription::ACTIVE, '70.000'], $this->asTenant($club, fn () => [
            Invoice::whereNotNull('number')->count(), Membership::sole()->status, PtSubscription::sole()->status, Invoice::whereNotNull('number')->sole()->total,
        ]));
        $this->assertSame(0, $this->asTenant($club, fn () => Member::whereKey($member->id)->firstOrFail()->memberships()->where('status', Membership::PENDING)->count()));
    }
}
