<?php

namespace Tests\Feature;

use App\Core\Support\Phone;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A new customer sold a membership or personal training on a customer invoice (owner's decision, 2026-11-21): issuing
 * registers their member file (the members' rules, the phone normalized), the invoice, its lines, the membership or
 * package and the money taken now — all in one transaction, or none of it. A membership or a package is sold only when
 * it is paid (owner's decision, 2026-11-25): the invoice is paid in full as it is issued and the sale is active at once;
 * on credit or part paid nothing is issued and no one is registered. A number already registered makes no second
 * member; a new customer's invoice without a plan or a package registers no one; permissions and branches hold.
 */
class InvoiceNewMemberTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function holding(Tenant $club, array $permissions, array $attributes = []): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.Str::lower(Str::random(8)), 'name' => 'مخصص', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception', $attributes);
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
    }

    /** @return array{shake: Product, plan: MembershipPlan, pt: PtPackage} */
    private function catalogue(Tenant $club): array
    {
        return $this->asTenant($club, function () {
            $shake = Product::create(['name' => 'Protein shake', 'price' => 2]);
            app(StockService::class)->move($shake, 10, 'adjustment', null, 'opening', 0.8);

            return [
                'shake' => $shake->fresh(),
                'plan' => MembershipPlan::create(['name' => 'Monthly', 'type' => 'combo', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 25, 'signup_fee' => 5]),
                'pt' => PtPackage::create(['name' => 'PT 4', 'sessions_count' => 4, 'session_minutes' => 60, 'price' => 40, 'validity_days' => 60]),
            ];
        });
    }

    /** The editor's form for a new customer, issued. */
    private function form(array $lines, array $over = []): array
    {
        return array_replace([
            'customer_mode' => 'new', 'customer_name' => 'سارة الحارثية', 'customer_phone' => '9123 4567', 'customer_email' => 'Sara@Example.com',
            'issue' => 1, 'payment_method' => 'cash', 'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(7)->toDateString(),
            'lines' => $lines, 'client_key' => (string) Str::uuid(),
        ], $over);
    }

    private function issue(User $by, array $lines, array $over = [])
    {
        return $this->actingAs($by)->from('/app')->post(route('club.invoices.store'), $this->form($lines, $over));
    }

    private function planLine(MembershipPlan $plan): array
    {
        return ['item_type' => 'plan', 'item_id' => $plan->id, 'description' => $plan->name, 'quantity' => 1, 'unit_price' => 1, 'discount' => 0];
    }

    private function ptLine(PtPackage $pt): array
    {
        return ['item_type' => 'pt', 'item_id' => $pt->id, 'description' => $pt->name, 'quantity' => 1, 'unit_price' => 1, 'discount' => 0];
    }

    private function productLine(Product $product, int $quantity = 1): array
    {
        return ['product_id' => $product->id, 'description' => $product->name, 'quantity' => $quantity, 'unit_price' => $product->price, 'discount' => 0];
    }

    /** @return list<int> members, invoices, memberships, packages */
    private function counts(Tenant $club): array
    {
        return $this->asTenant($club, fn () => [Member::withTrashed()->count(), Invoice::count(), Membership::count(), PtSubscription::count()]);
    }

    public function test_a_new_customer_sold_a_membership_is_registered_and_billed_on_one_invoice(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);

        // The preview shows what issuing will bill: a first membership, from today, with its signup fee.
        $preview = $this->actingAs($owner)->postJson(route('club.invoices.preview'), collect($this->form([$this->planLine($c['plan'])]))->except(['issue', 'client_key'])->all())->assertOk();
        $this->assertSame('30.000', $preview->json('totals.total'));

        $this->issue($owner, [$this->planLine($c['plan'])])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($c) {
            $member = Member::sole();
            $this->assertSame(['سارة الحارثية', Phone::normalize('91234567'), 'sara@example.com'], [$member->full_name, $member->phone, $member->email], 'the members\' rules: phone normalized, e-mail as stored');
            $invoice = Invoice::with('items')->sole();
            $membership = Membership::sole();
            // One invoice, the member's: no invoice of the membership's own.
            $this->assertSame(['member', $member->id, 'membership', '30.000'], [$invoice->billable_type, $invoice->billable_id, $invoice->type, $invoice->total]);
            // Sold paid: the invoice paid in full on issue, the membership active from today.
            $this->assertSame([Invoice::PAID, '30.000', '0.000'], [$invoice->status, $invoice->paid_total, $invoice->balance()]);
            $this->assertSame([$member->id, Membership::ACTIVE, $invoice->id, $c['plan']->id], [$membership->member_id, $membership->status, $membership->invoice_id, $membership->membership_plan_id]);
            $this->assertSame([$invoice->branch_id, $invoice->branch_id], [$member->branch_id, $membership->branch_id]);
            $this->assertSame(['membership', 'membership'], $invoice->items->pluck('itemable_type')->all(), 'the plan and its signup fee, on the membership');
            $this->assertDatabaseHas('audit_logs', ['event' => 'member.registered_on_invoice', 'subject_id' => $member->id]);
        });
    }

    public function test_a_new_customer_sold_personal_training_is_registered_with_the_package_paid_and_active(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);

        $this->issue($owner, [$this->ptLine($c['pt'])])->assertSessionHasNoErrors();

        $this->asTenant($club, function () {
            $member = Member::sole();
            $invoice = Invoice::sole();
            $training = PtSubscription::sole();
            $this->assertSame(['pt', '40.000', $member->id], [$invoice->type, $invoice->total, $invoice->billable_id]);
            $this->assertSame([Invoice::PAID, '40.000'], [$invoice->status, $invoice->paid_total]);
            $this->assertSame([$member->id, PtSubscription::ACTIVE, $invoice->id], [$training->member_id, $training->status, $training->invoice_id]);
            $this->assertSame(0, Membership::count());
        });
    }

    public function test_a_mixed_invoice_of_products_a_membership_and_training_registers_one_member_for_all_of_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);

        $this->issue($owner, [$this->productLine($c['shake'], 2), $this->planLine($c['plan']), $this->ptLine($c['pt'])])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($c) {
            $member = Member::sole();
            $invoice = Invoice::sole();
            // 2×2 + 25 + 5 (signup) + 40 = 74, on one invoice, its revenue on each line's own account (as for any member).
            $this->assertSame(['other', '74.000', $member->id], [$invoice->type, $invoice->total, $invoice->billable_id]);
            $this->assertSame([$member->id, $invoice->id], [Membership::sole()->member_id, Membership::sole()->invoice_id]);
            $this->assertSame([$member->id, $invoice->id], [PtSubscription::sole()->member_id, PtSubscription::sole()->invoice_id]);
            $this->assertSame(8.0, (float) app(StockService::class)->quantities(null)[$c['shake']->id], 'the product leaves the shelf');
            $reports = app(FinanceReports::class);
            $this->assertSame(['30.000', '40.000', '4.000'], [$reports->balanceOf('revenue_membership'), $reports->balanceOf('revenue_pt'), $reports->balanceOf('revenue_pos')]);
        });
    }

    public function test_a_phone_already_registered_makes_no_second_member_and_points_to_the_one_who_has_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);
        $existing = $this->member($club, ['phone' => Phone::normalize('91234567'), 'full_name' => 'سارة']);

        // Typed differently, the same number once normalized: refused, nothing made; the member named, to choose.
        $this->issue($owner, [$this->planLine($c['plan'])], ['customer_phone' => '+968 9123-4567'])
            ->assertSessionHasErrors(['customer_phone', 'existing_member_id' => (string) $existing->id]);
        $this->assertSame([1, 0, 0, 0], $this->counts($club));

        // Without «عرض الأعضاء»: refused all the same, the member not named.
        $clerk = $this->holding($club, ['invoices.view', 'invoices.manage', 'payments.record', 'memberships.sell', 'members.manage']);
        $this->issue($clerk, [$this->planLine($c['plan'])])->assertSessionHasErrors('customer_phone')->assertSessionDoesntHaveErrors('existing_member_id');
        $this->assertSame([1, 0, 0, 0], $this->counts($club));

        // Chosen instead: the invoice and the membership are that member's.
        $this->issue($owner, [$this->planLine($c['plan'])], ['customer_mode' => 'member', 'member_id' => $existing->id])->assertSessionHasNoErrors();
        $this->assertSame([1, 1, 1, 0], $this->counts($club));
        $this->asTenant($club, fn () => $this->assertSame([$existing->id, $existing->id], [Invoice::sole()->billable_id, Membership::sole()->member_id]));
    }

    public function test_a_failure_anywhere_leaves_no_member_invoice_membership_or_package(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);
        $lines = [$this->productLine($c['shake']), $this->planLine($c['plan']), $this->ptLine($c['pt'])];

        // The money taken now larger than the invoice: refused after the member, the sale and the invoice were made.
        $this->issue($owner, $lines, ['payment_method' => 'cash', 'paid_amount' => '999'])->assertSessionHasErrors('paid_amount');
        // Short stock.
        $this->issue($owner, [$this->productLine($c['shake'], 11), $this->planLine($c['plan'])])->assertSessionHasErrors();
        // The member's own rules: a phone that is not one.
        $this->issue($owner, $lines, ['customer_phone' => 'abc'])->assertSessionHasErrors('customer_phone');

        $this->assertSame([0, 0, 0, 0], $this->counts($club));
        $this->asTenant($club, function () use ($c) {
            $this->assertSame(10.0, (float) app(StockService::class)->quantities(null)[$c['shake']->id]);
            $this->assertSame(0, DB::table('audit_logs')->where('event', 'member.registered_on_invoice')->count());
        });
    }

    public function test_the_form_sent_twice_registers_and_issues_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);
        $form = $this->form([$this->planLine($c['plan']), $this->ptLine($c['pt'])]);

        // A double click: the same form, the same key.
        $this->actingAs($owner)->post(route('club.invoices.store'), $form)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.invoices.store'), $form)->assertSessionHasNoErrors();
        $this->assertSame([1, 1, 1, 1], $this->counts($club));

        // Sent again as a new form: the number is a member's now, so no second member, invoice or sale.
        $this->issue($owner, [$this->planLine($c['plan']), $this->ptLine($c['pt'])])->assertSessionHasErrors('customer_phone');
        $this->assertSame([1, 1, 1, 1], $this->counts($club));
    }

    public function test_a_plan_or_package_on_credit_or_part_paid_is_refused_and_leaves_nothing_and_paid_in_full_it_is_active_at_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);
        $lines = [$this->productLine($c['shake']), $this->planLine($c['plan']), $this->ptLine($c['pt'])];

        // Sold only when paid: part paid, or on credit (آجل), is refused on the payment method — no member registered,
        // no invoice, no membership, no package, the product still on the shelf.
        $this->issue($owner, $lines, ['payment_method' => 'cash', 'paid_amount' => '20'])->assertSessionHasErrors('payment_method');
        $this->issue($owner, $lines, ['payment_method' => 'credit'])->assertSessionHasErrors('payment_method');
        $this->assertSame([0, 0, 0, 0], $this->counts($club));
        $this->asTenant($club, function () use ($c) {
            $this->assertSame(10.0, (float) app(StockService::class)->quantities(null)[$c['shake']->id]);
            $this->assertSame(0, DB::table('audit_logs')->where('event', 'member.registered_on_invoice')->count());
        });

        // Paid in full (the whole balance typed): both active at once.
        $this->issue($owner, [$this->planLine($c['plan']), $this->ptLine($c['pt'])], ['payment_method' => 'card', 'paid_amount' => '70'])->assertSessionHasNoErrors();
        [$invoice, $membership, $training] = $this->asTenant($club, fn () => [Invoice::sole(), Membership::sole(), PtSubscription::sole()]);
        $this->assertSame([Invoice::PAID, '70.000', Membership::ACTIVE, PtSubscription::ACTIVE], [$invoice->status, $invoice->paid_total, $membership->status, $training->status]);
    }

    public function test_registering_takes_its_permission_and_the_users_branch(): void
    {
        $club = $this->club();
        $this->owner($club);
        $c = $this->catalogue($club);

        // May sell, may not register members: refused on the line, nothing made.
        // Each may take the money (a plan or a package is sold paid), so only the permission named is missing.
        $seller = $this->holding($club, ['invoices.view', 'invoices.manage', 'payments.record', 'memberships.sell', 'pt.sell', 'members.view']);
        $this->issue($seller, [$this->planLine($c['plan'])])->assertSessionHasErrors('lines.0.item_id');
        // May register, may not sell the plan.
        $registrar = $this->holding($club, ['invoices.view', 'invoices.manage', 'payments.record', 'members.manage', 'members.view']);
        $this->issue($registrar, [$this->ptLine($c['pt'])])->assertSessionHasErrors('lines.0.item_id');
        $this->assertSame([0, 0, 0, 0], $this->counts($club));
        $this->actingAs($seller)->get(route('club.invoices.create'))->assertInertia(fn ($p) => $p->where('may.register', false));

        // Tied to a branch: the member, the invoice and the membership are that branch's.
        $here = $this->asTenant($club, fn () => Branch::create(['name' => 'الخوض', 'is_active' => true]));
        $staff = $this->holding($club, ['invoices.view', 'invoices.manage', 'payments.record', 'memberships.sell', 'members.manage', 'members.view'], ['branch_id' => $here->id]);
        $this->issue($staff, [$this->planLine($c['plan'])])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([$here->id, $here->id, $here->id], [Member::sole()->branch_id, Invoice::sole()->branch_id, Membership::sole()->branch_id]));
    }

    public function test_a_new_customers_invoice_without_a_plan_or_package_registers_no_one(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);

        $this->issue($owner, [$this->productLine($c['shake']), ['description' => 'استشارة', 'quantity' => 1, 'unit_price' => 7, 'discount' => 0]], ['customer_phone' => null])->assertSessionHasNoErrors();

        $this->asTenant($club, function () {
            $this->assertSame(0, Member::count());
            $invoice = Invoice::sole();
            $this->assertSame([null, 'سارة الحارثية', 'other'], [$invoice->billable_id, $invoice->customer_name, $invoice->type]);
        });
    }

    public function test_a_draft_for_a_new_customer_registers_no_one_until_it_is_issued(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $c = $this->catalogue($club);

        $this->actingAs($owner)->post(route('club.invoices.store'), $this->form([$this->planLine($c['plan'])], ['issue' => 0, 'customer_phone' => null]))->assertSessionHasNoErrors();
        $draft = $this->asTenant($club, fn () => Invoice::drafts()->sole());
        $this->assertSame([0, 0, 0, 0], $this->counts($club));
        $this->actingAs($owner)->get(route('club.invoices.drafts.edit', $draft->id))->assertInertia(fn ($p) => $p->where('draft.customer_mode', 'new'));

        $this->actingAs($owner)->post(route('club.invoices.drafts.update', $draft->id), $this->form([$this->planLine($c['plan'])]))->assertSessionHasNoErrors();
        $this->assertSame([1, 1, 1, 0], $this->counts($club));
        $this->asTenant($club, fn () => $this->assertSame(0, Invoice::drafts()->count()));
    }
}
