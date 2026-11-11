<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The home page's structured data (JSON-LD, schema.org HealthClub) names only what the club has entered: a phone or an
 * address not entered yet is left out, never sent as null, which search engines report as an invalid value.
 */
class SiteStructuredDataTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_a_phone_and_address_not_entered_are_left_out_not_sent_as_null(): void
    {
        $club = $this->club();
        $club->forceFill(['city' => null, 'phone' => null, 'address' => null])->save();

        $this->get(route('site.home', ['club' => $club->slug]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('seo.jsonld', function ($ld) {
                $ld = collect($ld)->toArray();
                $this->assertArrayNotHasKey('telephone', $ld);
                $this->assertSame(['@type' => 'PostalAddress', 'addressCountry' => 'OM'], $ld['address']);
                $this->assertStringNotContainsString('null', json_encode($ld));

                return $ld['@type'] === 'HealthClub';
            }));
    }

    public function test_what_was_entered_is_named(): void
    {
        $club = $this->club();
        $club->forceFill(['city' => 'مسقط', 'phone' => '96824000000', 'address' => 'الخوير'])->save();

        $this->get(route('site.home', ['club' => $club->slug]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('seo.jsonld.telephone', '96824000000')
                ->where('seo.jsonld.address.streetAddress', 'الخوير')
                ->where('seo.jsonld.address.addressLocality', 'مسقط'));
    }
}
