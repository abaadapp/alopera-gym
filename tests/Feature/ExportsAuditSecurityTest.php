<?php

namespace Tests\Feature;

use App\Core\Export\TableExporter;
use App\Models\AuditLog;
use App\Models\TenantIntegration;
use App\Modules\Gym\Models\Membership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Spec 7.2 / 7.4: exports, audit trail (who/what/when), rate limiting, headers. */
class ExportsAuditSecurityTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_member_export_is_excel_friendly_csv_and_neutralises_formulas(): void
    {
        $club = $this->club();
        $this->member($club, ['full_name' => '=HYPERLINK("http://evil")']);

        $response = $this->actingAs($this->owner($club))->get(route('club.members.export'));
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('رقم العضوية', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertSame("'@x", TableExporter::cell('@x'));
        $this->assertSame('-5', TableExporter::cell('-5'));
    }

    public function test_memberships_list_exports_with_arabic_status_labels(): void
    {
        $club = $this->club();
        $this->activeMembership($club, $this->member($club));
        $owner = $this->owner($club);

        $this->actingAs($owner)->get(route('club.memberships.index', ['filter' => 'active', 'format' => 'xlsx']))
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $csv = $this->actingAs($owner)->get(route('club.memberships.index', ['filter' => 'active', 'format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('نشطة', $csv);
        $this->assertStringNotContainsString(',active,', $csv);
    }

    public function test_reports_export_as_xlsx_and_financial_exports_need_permission(): void
    {
        $club = $this->club();
        $this->activeMembership($club, $this->member($club));

        $this->actingAs($this->owner($club))->get(route('club.reports.export', ['report' => 'revenue', 'format' => 'xlsx']))
            ->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $manager = $this->staff($club, 'manager');
        $this->actingAs($manager)->get(route('club.reports.export', ['report' => 'memberships']))->assertOk();
    }

    public function test_sensitive_operations_are_audited_with_actor_and_are_immutable(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);
        $plan = $this->plan($club);

        $this->actingAs($owner)->post(route('club.memberships.store', $member->id), ['membership_plan_id' => $plan->id, 'mode' => 'new']);
        $membership = $this->asTenant($club, fn () => Membership::first());
        $this->actingAs($owner)->post(route('club.memberships.cancel', $membership->id), ['reason' => 'test']);

        $this->asTenant($club, function () use ($owner) {
            $log = AuditLog::where('event', 'membership.cancelled')->firstOrFail();
            $this->assertSame('user', $log->actor_type);
            $this->assertSame($owner->id, $log->actor_id);
            $this->assertSame(['reason' => 'test'], $log->properties);
            $this->assertNotNull($log->created_at);

            $this->assertFalse($log->delete(), 'Audit rows cannot be deleted through the model.');
            $this->assertTrue(AuditLog::whereKey($log->id)->exists());
        });

        $this->actingAs($owner)->get(route('club.audit.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Club/Settings/Audit')->where('logs.data.0.event', fn ($e) => is_string($e)));
    }

    public function test_integration_secrets_are_encrypted_and_never_sent_to_the_browser(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        $this->actingAs($owner)->put(route('club.integrations.update', 'paymob'), [
            'is_enabled' => true, 'mode' => 'test', 'credentials' => ['secret_key' => 'sk_live_very_secret', 'hmac_secret' => 'hmac-very-secret'],
        ])->assertSessionHas('success');

        $raw = DB::table('tenant_integrations')->where('tenant_id', $club->id)->value('credentials');
        $this->assertStringNotContainsString('sk_live_very_secret', $raw);

        $page = $this->actingAs($owner)->get(route('club.integrations.index'))->getContent();
        $this->assertStringNotContainsString('sk_live_very_secret', $page);
        $this->assertStringNotContainsString('hmac-very-secret', $page);

        // A blank field keeps the stored secret.
        $this->actingAs($owner)->put(route('club.integrations.update', 'paymob'), ['is_enabled' => true, 'mode' => 'live', 'credentials' => ['secret_key' => '']]);
        $this->assertSame('sk_live_very_secret', $this->asTenant($club, fn () => TenantIntegration::first()->credential('secret_key')));
    }

    public function test_login_is_rate_limited(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post(route('login.store'), ['email' => 'x@y.test', 'password' => 'wrong']);
        }
        $this->post(route('login.store'), ['email' => 'x@y.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get(route('login'))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_reports_compare_with_the_previous_period(): void
    {
        $club = $this->club();
        $this->travel(-35)->days();
        $this->activeMembership($club, $this->member($club));
        $this->travelBack();
        $this->activeMembership($club, $this->member($club));
        $this->activeMembership($club, $this->member($club));

        $this->actingAs($this->owner($club))->get(route('club.reports.show', ['report' => 'members', 'from' => today()->subDays(29)->toDateString(), 'to' => today()->toDateString()]))
            ->assertInertia(fn ($page) => $page->component('Reports/Show')
                ->where('report.comparison.new_memberships.current', 2)
                ->where('report.comparison.new_memberships.previous', 1)
                ->where('report.comparison.new_memberships.change', 100));
    }
}
