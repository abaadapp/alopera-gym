<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\ChartOfAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Sensitive settings leave a trail: who changed what and when, the values before and after for the keys that changed
 * only, account numbers masked to their last four characters; saving without a change writes nothing.
 */
class SettingsAuditTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function last(string $event): ?object
    {
        return DB::table('audit_logs')->where('event', $event)->latest('id')->first();
    }

    public function test_club_finance_and_bank_account_changes_are_audited_safely(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $settings = fn (array $rules) => ['name' => $club->name, 'primary_color' => '#111111', 'secondary_color' => '#222222', 'rules' => $rules];

        // The club: only the tax rate changed, so only it is in the trail; the same form again writes nothing.
        $this->actingAs($owner)->put(route('club.settings.update'), $settings(['vat_rate' => 0]))->assertSessionHasNoErrors();
        $count = DB::table('audit_logs')->where('event', 'settings.club_updated')->count();
        $this->actingAs($owner)->put(route('club.settings.update'), $settings(['vat_rate' => 5]))->assertSessionHasNoErrors();
        $log = $this->last('settings.club_updated');
        $this->assertSame(['vat_rate' => 5], json_decode($log->new_values, true));
        $this->assertSame($owner->id, (int) $log->actor_id);
        $this->actingAs($owner)->put(route('club.settings.update'), $settings(['vat_rate' => 5]))->assertSessionHasNoErrors();
        $this->assertSame($count + 1, DB::table('audit_logs')->where('event', 'settings.club_updated')->count());

        // Finance settings: which account each method uses, by name.
        [$cash, $bank] = $this->asTenant($club, fn () => [app(ChartOfAccounts::class)->account('cash'), app(ChartOfAccounts::class)->account('bank')]);
        $this->actingAs($owner)->put(route('club.settings.finance.update'), ['receipt' => ['cash' => $bank->id], 'spending' => ['cash' => $cash->id]])->assertSessionHasNoErrors();
        $this->assertSame($bank->name, json_decode($this->last('settings.finance_updated')->new_values, true)['receipt']['cash']);

        // A bank account's number: changed, and never written in full.
        $this->actingAs($owner)->put(route('club.finance.banks.update', $bank), ['name' => 'بنك مسقط', 'account_number' => '0123456789012', 'iban' => 'OM810180000001299123456'])->assertSessionHasNoErrors();
        $log = $this->last('accounting.account_updated');
        $this->assertSame('••••9012', json_decode($log->new_values, true)['account_number']);
        $this->assertStringNotContainsString('0123456789012', $log->new_values.$log->old_values.$log->properties);
        $this->assertStringNotContainsString('OM810180000001299123456', $log->new_values.$log->old_values.$log->properties);
        $this->asTenant($club, fn () => $this->assertSame('0123456789012', Account::find($bank->id)->account_number));

        // The audit page reads them in words.
        $this->actingAs($owner)->get(route('club.audit.index', ['event' => 'settings.']))->assertOk();
    }
}
