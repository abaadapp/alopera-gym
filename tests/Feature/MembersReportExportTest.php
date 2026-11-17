<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * تقرير العضويات: its figures and «الباقات المباعة» count the memberships activated in the period (paid and started).
 * The export's «العضويات المسجلة» lists the same ones: a membership sold on 31 October and paid on 2 November is
 * November's, as in the figures; one sold and never paid is in neither.
 */
class MembersReportExportTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private Tenant $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = $this->club();
        $this->actingAs($this->owner($this->club));
    }

    private function at(string $muscat): void
    {
        $this->travelTo(CarbonImmutable::parse($muscat, 'Asia/Muscat'));
    }

    /** The page's new memberships figure, and the export's «العضويات المسجلة» sheet. */
    private function report(string $from, string $to): array
    {
        $new = $this->get(route('club.reports.show', ['report' => 'members', 'from' => $from, 'to' => $to]))->assertOk()
            ->viewData('page')['props']['data']['report']['comparison']['new_memberships']['current'];
        $csv = $this->get(route('club.reports.export', ['report' => 'members', 'format' => 'csv', 'from' => $from, 'to' => $to]))->assertOk()->streamedContent();
        $sheet = explode('العضويات المسجلة', $csv, 2)[1] ?? '';

        return [(int) $new, $sheet];
    }

    public function test_the_registered_memberships_sheet_lists_the_memberships_the_figures_count(): void
    {
        $paidLater = $this->member($this->club, ['full_name' => 'سارة دُفعت لاحقًا']);
        $neverPaid = $this->member($this->club, ['full_name' => 'منى لم تدفع']);
        $plan = $this->plan($this->club, ['price' => '30.000']);

        $this->at('2026-10-31 20:00:00');
        $invoice = $this->asTenant($this->club, fn () => app(MembershipService::class)->sell($paidLater, $plan)->invoice);
        $this->at('2026-11-02 10:00:00');
        $this->asTenant($this->club, fn () => app(PaymentService::class)->record($invoice->fresh(), '30.000', 'cash'));
        $this->at('2026-11-05 10:00:00');
        $this->asTenant($this->club, fn () => app(MembershipService::class)->sell($neverPaid, $plan));

        [$octoberNew, $october] = $this->report('2026-10-01', '2026-10-31');
        [$novemberNew, $november] = $this->report('2026-11-01', '2026-11-30');

        $this->assertSame([0, 1], [$octoberNew, $novemberNew], 'activated in November');
        $this->assertStringNotContainsString('سارة دُفعت لاحقًا', $october, 'not October\'s');
        $this->assertStringContainsString('سارة دُفعت لاحقًا', $november, 'November\'s, as the figures say');
        $this->assertStringNotContainsString('منى لم تدفع', $november, 'never paid: never activated, in no figure');
        $this->assertStringContainsString('2026-11-02', $november, 'with its activation date');
    }
}
