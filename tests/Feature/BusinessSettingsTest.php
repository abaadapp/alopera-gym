<?php

namespace Tests\Feature;

use App\Core\Papers\Paper;
use App\Core\Papers\PaperIssuer;
use App\Core\Papers\Papers;
use App\Core\Papers\PaperSize;
use App\Core\Papers\PaperTypes;
use App\Models\Tenant;
use App\Modules\Finance\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The club's business data as the owner keeps it in the settings, and the papers printing it from there: the VAT number
 * only for a club registered for VAT, the governorate with the city, the bank account under «how to pay», and a
 * currency that changes only before the first invoice.
 */
class BusinessSettingsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @param  array<string, mixed>  $rules */
    private function save(Tenant $club, array $rules, array $extra = []): TestResponse
    {
        return $this->actingAs($this->owner($club))->put(route('club.settings.update'), [
            'name' => $club->name, 'primary_color' => '#9d174d', 'secondary_color' => '#f9a8d4', 'rules' => $rules,
        ] + $extra);
    }

    private function html(Tenant $club, string $size = PaperSize::A4): string
    {
        return $this->asTenant($club, function () use ($club, $size) {
            $tpl = PaperTypes::settings($club, 'invoice');

            return (new Paper($club, 'invoice', Papers::builder('invoice')::sample($club, $tpl), $tpl, PaperIssuer::resolve($club), $size, 'sample'))->html();
        });
    }

    public function test_a_club_not_registered_for_vat_prints_no_vat_number_and_charges_no_rate(): void
    {
        $club = $this->club();
        $this->save($club, ['vat_registered' => true, 'vat_rate' => 5, 'vat_number' => 'OM1100223344'])->assertSessionHasNoErrors();
        $club->refresh();
        $this->assertTrue($club->vatRegistered());
        $this->assertStringContainsString('OM1100223344', $this->html($club));
        $this->assertStringContainsString('OM1100223344', $this->html($club, PaperSize::T80));

        $this->save($club, ['vat_registered' => false, 'vat_rate' => 5, 'vat_number' => 'OM1100223344'])->assertSessionHasNoErrors();
        $club->refresh();
        $this->assertFalse($club->vatRegistered());
        $this->assertSame(0.0, (float) $club->setting('vat_rate'));
        $this->assertSame('OM1100223344', $club->setting('vat_number'));
        $this->assertStringNotContainsString('OM1100223344', $this->html($club));
        $this->assertStringNotContainsString('OM1100223344', $this->html($club, PaperSize::T80));

        // a rate sent without the switch does not slip past it
        $this->save($club, ['vat_rate' => 5])->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) $club->refresh()->setting('vat_rate'));
    }

    /** A club that set a rate or a number before the switch existed is read as registered: nothing it prints changes. */
    public function test_a_club_from_before_the_switch_keeps_what_it_printed(): void
    {
        $club = $this->club();
        $club->forceFill(['settings' => ['vat_number' => 'OM99', 'vat_rate' => 0]])->save();

        $this->assertTrue($club->refresh()->vatRegistered());
        $this->assertStringContainsString('OM99', $this->html($club));
        $club->forceFill(['settings' => []])->save();
        $this->assertFalse($club->refresh()->vatRegistered());
    }

    public function test_the_governorate_and_the_bank_account_reach_the_invoice(): void
    {
        $club = $this->club();
        $club->forceFill(['city' => 'السيب'])->save();
        $this->save($club, ['governorate' => 'مسقط', 'bank_name' => 'بنك مسقط', 'bank_account_name' => 'Alopera Ladies Fitness LLC', 'bank_iban' => 'OM810180000001234567890'])->assertSessionHasNoErrors();
        $html = $this->html($club->refresh());

        $this->assertStringContainsString('السيب، مسقط', $html);
        $this->assertStringContainsString('بنك مسقط', $html);
        $this->assertStringContainsString('OM810180000001234567890', $html);

        // on a 58mm receipt each value sits on its own line under its label, so a long account wraps within the strip
        $strip = $this->html($club, PaperSize::T58);
        $this->assertMatchesRegularExpression('#<tr><td class="s-sub">[^<]+</td></tr>\s*<tr><td class="words" dir="ltr">[^<]*OM810180000001234567890#u', $strip);

        // an account number is one long word: it may break rather than run past the paper
        $this->assertStringContainsString('<td class="words" dir="ltr">', $strip);
        $this->assertStringContainsString('.words { overflow-wrap: anywhere;', $strip);

        $this->save($club, ['governorate' => 'مكان آخر'])->assertSessionHasErrors('rules.governorate');
        $this->save($club, ['bank_iban' => 'OM81<script>'])->assertSessionHasErrors('rules.bank_iban');
    }

    public function test_the_currency_changes_only_before_the_first_invoice(): void
    {
        $club = $this->club();
        $this->save($club, [], ['currency' => 'SAR'])->assertSessionHasNoErrors();
        $this->assertSame('SAR', $club->refresh()->currency);

        $member = $this->member($club);
        $this->asTenant($club, fn () => app(InvoiceService::class)->issue($member, [['description' => 'رسوم', 'quantity' => 1, 'unit_price' => 5]]));

        $this->save($club, [], ['currency' => 'OMR'])->assertSessionHasErrors('currency');
        $this->assertSame('SAR', $club->refresh()->currency);
        $this->save($club, [], ['currency' => 'SAR'])->assertSessionHasNoErrors();
    }

    public function test_the_optional_fields_stay_optional(): void
    {
        $club = $this->club();

        $this->save($club, ['vat_registered' => false, 'governorate' => '', 'bank_name' => '', 'bank_account_name' => '', 'bank_iban' => '', 'cr_number' => ''])->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('اسم الحساب', $this->html($club->refresh()));
    }
}
