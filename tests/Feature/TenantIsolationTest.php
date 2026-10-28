<?php

namespace Tests\Feature;

use App\Core\Backup\TenantTables;
use App\Core\Tenancy\CrossTenantWriteException;
use App\Core\Tenancy\MissingTenantException;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MemberDocument;
use App\Modules\Gym\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Each club must only ever see and change its own data — through models,
 * validation, route binding, files, portal sessions and reports.
 */
class TenantIsolationTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_queries_only_return_the_current_clubs_rows_and_fail_closed_without_a_club(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $this->member($a);
        $this->member($a);
        $this->member($b);

        $this->assertSame(2, $this->asTenant($a, fn () => Member::count()));
        $this->assertSame(1, $this->asTenant($b, fn () => Member::count()));

        app(TenantContext::class)->set(null);
        $this->assertSame(0, Member::count(), 'No tenant in context must match nothing.');
        $this->assertSame(3, app(TenantContext::class)->bypass(fn () => Member::count()));
    }

    public function test_writes_require_a_club_and_cannot_target_another_club(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');

        app(TenantContext::class)->set(null);
        $this->expectException(MissingTenantException::class);
        Member::create(['full_name' => 'X', 'phone' => '96890000000']);
    }

    public function test_cross_tenant_create_and_tenant_reassignment_are_refused(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $member = $this->member($a);

        $this->asTenant($a, function () use ($b) {
            $m = new Member(['full_name' => 'X', 'phone' => '96890000001']);
            $m->tenant_id = $b->id;
            try {
                $m->save();
                $this->fail('Saving a row for another club must throw.');
            } catch (CrossTenantWriteException) {
                $this->assertTrue(true);
            }
        });

        $this->asTenant($a, function () use ($member, $b) {
            $member->tenant_id = $b->id;
            $this->expectException(CrossTenantWriteException::class);
            $member->save();
        });
    }

    public function test_staff_cannot_open_or_change_another_clubs_records_by_id(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $foreign = $this->member($b);
        $foreignMembership = $this->activeMembership($b, $foreign);
        $foreignInvoice = $foreignMembership->invoice_id;
        $owner = $this->owner($a);

        $this->actingAs($owner)->get(route('club.members.show', $foreign->id))->assertNotFound();
        $this->actingAs($owner)->put(route('club.members.update', $foreign->id), ['full_name' => 'Hacked', 'phone' => '96890000009'])->assertNotFound();
        $this->actingAs($owner)->get(route('club.invoices.show', $foreignInvoice))->assertNotFound();
        $this->actingAs($owner)->post(route('club.payments.store', $foreignInvoice), ['amount' => 1, 'method' => 'cash'])->assertNotFound();
        $this->actingAs($owner)->post(route('club.memberships.cancel', $foreignMembership->id), ['reason' => 'x'])->assertNotFound();
        $this->actingAs($owner)->delete(route('club.members.destroy', $foreign->id))->assertNotFound();

        $this->assertSame('active', $this->asTenant($b, fn () => Membership::find($foreignMembership->id)->status));
        $this->assertNotSame('Hacked', $this->asTenant($b, fn () => Member::find($foreign->id)->full_name));
    }

    public function test_validation_rejects_ids_from_another_club(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $member = $this->member($a);
        $foreignPlan = $this->plan($b);

        $this->actingAs($this->owner($a))
            ->post(route('club.memberships.store', $member->id), ['membership_plan_id' => $foreignPlan->id, 'mode' => 'new'])
            ->assertSessionHasErrors('membership_plan_id');

        $this->assertSame(0, $this->asTenant($a, fn () => Membership::count()));
    }

    public function test_staff_user_management_is_limited_to_own_club(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $foreignUser = $this->staff($b, 'reception');

        $this->actingAs($this->owner($a))
            ->put(route('club.users.update', $foreignUser->id), ['name' => 'X', 'email' => $foreignUser->email, 'role_id' => 1])
            ->assertNotFound();
        $this->actingAs($this->owner($a))->delete(route('club.users.destroy', $foreignUser->id))->assertNotFound();
        $this->assertTrue($foreignUser->fresh()->is_active);
    }

    public function test_member_documents_of_another_club_cannot_be_downloaded(): void
    {
        Storage::fake('local');
        $a = $this->club('A');
        $b = $this->club('B');
        $member = $this->member($b);

        $this->actingAs($this->owner($b))
            ->post(route('club.members.documents.store', $member->id), ['type' => 'contract', 'title' => 'Contract', 'file' => UploadedFile::fake()->create('c.pdf', 20, 'application/pdf')])
            ->assertSessionHasNoErrors();
        $doc = $this->asTenant($b, fn () => MemberDocument::first());

        $this->actingAs($this->owner($a))->get(route('club.documents.download', $doc->id))->assertNotFound();
        $this->actingAs($this->owner($b))->get(route('club.documents.download', $doc->id))->assertOk();
        $this->assertStringStartsWith('tenants/'.$b->id.'/', $doc->path);
    }

    public function test_member_portal_session_does_not_carry_over_to_another_club(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $member = $this->member($a, ['password' => 'secret123']);
        $member->forceFill(['password' => 'secret123'])->save();

        $this->post(route('portal.login.store', ['club' => $a->slug]), ['login' => $member->phone, 'password' => 'secret123'])
            ->assertRedirect(route('portal.dashboard', ['club' => $a->slug]));
        $this->get(route('portal.dashboard', ['club' => $a->slug]))->assertOk();

        // Same browser session on club B's portal is a guest there.
        $this->get(route('portal.dashboard', ['club' => $b->slug]))->assertRedirect();

        // And the same credentials do not open club B's portal.
        $this->post(route('portal.logout', ['club' => $a->slug]));
        $this->post(route('portal.login.store', ['club' => $b->slug]), ['login' => $member->phone, 'password' => 'secret123'])
            ->assertSessionHasErrors('login');
    }

    public function test_dashboard_figures_only_count_the_current_club(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $this->activeMembership($a, $this->member($a));
        foreach (range(1, 3) as $i) {
            $this->activeMembership($b, $this->member($b));
        }

        $this->actingAs($this->owner($a))->get(route('club.dashboard'))
            ->assertInertia(fn ($page) => $page->component('Club/Dashboard')->where('kpis.active_members', 1));
    }

    public function test_every_table_with_a_tenant_column_is_classified_for_backup(): void
    {
        $tables = collect(DB::select("select table_name from information_schema.columns where column_name = 'tenant_id' and table_schema = 'public'"))
            ->pluck('table_name');

        $known = array_merge(TenantTables::ORDER, array_keys(TenantTables::EXCLUDED));
        $unclassified = $tables->reject(fn ($t) => in_array($t, $known, true))->values()->all();

        $this->assertSame([], $unclassified, 'Add new tenant tables to TenantTables::ORDER or EXCLUDED.');
        foreach (TenantTables::ORDER as $table) {
            $this->assertTrue(Schema::hasTable($table), "Unknown table {$table} in TenantTables::ORDER");
        }
    }

    public function test_invoice_numbers_are_sequenced_per_club(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $this->activeMembership($a, $this->member($a));
        $this->activeMembership($b, $this->member($b));

        $numberA = $this->asTenant($a, fn () => Invoice::first()->number);
        $numberB = $this->asTenant($b, fn () => Invoice::first()->number);
        $this->assertSame($numberA, $numberB, 'Both clubs start their own sequence.');
        $this->assertStringEndsWith('000001', $numberA);
    }
}
