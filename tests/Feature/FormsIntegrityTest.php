<?php

namespace Tests\Feature;

use App\Core\Papers\PaperTypes;
use App\Models\Role;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** What a form sends is what is kept, and a save that did not happen never says it did. */
class FormsIntegrityTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_a_renewal_starts_after_the_current_period_whatever_start_date_the_form_still_carries(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['duration_value' => 30, 'duration_unit' => 'day']);
        $current = $this->asTenant($club, fn () => $this->activeMembership($club, $member, $plan));

        // Switched to «new», a date typed, then back to «renewal»: the hidden date is still sent.
        $this->actingAs($this->owner($club))->post(route('club.memberships.store', $member), ['membership_plan_id' => $plan->id, 'mode' => 'renewal', 'starts_on' => today()->toDateString()])->assertSessionHasNoErrors();

        $renewal = $this->asTenant($club, fn () => Membership::where('member_id', $member->id)->whereKeyNot($current->id)->sole());
        $this->assertTrue($renewal->starts_on->eq($current->fresh()->ends_on->addDay()));
    }

    public function test_removing_every_variable_from_a_template_is_kept(): void
    {
        $club = $this->club();
        $template = $this->asTenant($club, function () {
            TemplateCatalog::seedDefaults();

            return MessageTemplate::where('key', 'invoice_issued')->firstOrFail();
        });
        $this->assertNotEmpty($template->variables);

        $this->actingAs($this->owner($club))->put(route('club.templates.update', $template), [
            'name' => $template->name, 'language' => $template->language, 'category' => $template->category, 'body' => 'شكرًا لك', 'status' => $template->status, 'variables' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame([], $template->fresh()->variables);
    }

    public function test_sending_an_invoice_by_whatsapp_says_so_when_nothing_was_sent(): void
    {
        $club = $this->club();
        $member = $this->member($club, ['whatsapp_opt_in' => false]);
        $invoice = $this->asTenant($club, function () use ($member) {
            TenantIntegration::create(['provider' => 'whatsapp', 'is_enabled' => true, 'credentials' => ['phone_number_id' => 'PNID-9', 'access_token' => 'EAAG-secret'], 'public_identifier' => 'PNID-9']);
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved']);

            return app(InvoiceService::class)->issue($member, [['description' => 'Line', 'quantity' => 1, 'unit_price' => 20]], ['type' => 'other']);
        });

        $this->actingAs($this->owner($club))->post(route('club.invoices.send', $invoice))->assertSessionHas('error')->assertSessionMissing('success');
    }

    public function test_the_invoice_footer_from_the_club_settings_keeps_printing_after_the_invoice_paper_is_saved(): void
    {
        $club = $this->club();
        $setFooter = fn (string $text) => $club->forceFill(['settings' => array_merge($club->fresh()->settings ?? [], ['invoice_footer' => $text])])->save();
        $setFooter('A');

        // The paper editor sends back every value it shows, the inherited footer included.
        $values = PaperTypes::settings($club->fresh(), 'invoice');
        $this->actingAs($this->owner($club))->put(route('club.settings.papers.update', 'invoice'), ['show_signature' => true] + $values)->assertSessionHasNoErrors();

        $setFooter('B');
        $this->assertSame('B', PaperTypes::settings($club->fresh(), 'invoice')['footer']);
    }

    public function test_a_member_email_written_with_capitals_still_signs_in(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $this->asTenant($club, fn () => $member->forceFill(['password' => 'secret-pass-1'])->save());

        $this->actingAs($member, 'member')->put(route('portal.profile.update', ['club' => $club->slug]), ['email' => 'Sara@Mail.com'])->assertSessionHasNoErrors();
        auth('member')->logout();

        $this->post(route('portal.login.store', ['club' => $club->slug]), ['login' => 'Sara@Mail.com', 'password' => 'secret-pass-1']);
        $this->assertAuthenticatedAs($member->fresh(), 'member');

        // One saved with capitals before this change signs in too.
        auth('member')->logout();
        DB::table('gym_members')->where('id', $member->id)->update(['email' => 'Old@Mail.com']);
        $this->post(route('portal.login.store', ['club' => $club->slug]), ['login' => 'old@mail.com', 'password' => 'secret-pass-1']);
        $this->assertAuthenticatedAs($member->fresh(), 'member');
    }

    public function test_a_staff_email_taken_in_another_case_is_a_field_error_not_a_server_error(): void
    {
        $club = $this->club();
        $this->staff($club, 'reception', ['email' => 'staff@mail.com']);
        $role = $this->asTenant($club, fn () => Role::where('key', 'reception')->firstOrFail());

        $this->actingAs($this->owner($club))->post(route('club.users.store'), ['name' => 'Second', 'email' => 'Staff@Mail.com', 'role_id' => $role->id, 'password' => 'Str0ng-Pass!word'])
            ->assertSessionHasErrors('email');
        $this->assertSame(1, User::where('email', 'staff@mail.com')->count());
    }

    public function test_a_member_edit_that_is_refused_keeps_the_photo(): void
    {
        Storage::fake('public');
        $club = $this->club();
        $this->member($club, ['phone' => '91111111']);
        $member = $this->member($club, ['phone' => '92222222']);
        Storage::disk('public')->put('members/old.jpg', 'old');
        $this->asTenant($club, fn () => $member->forceFill(['photo_path' => 'members/old.jpg'])->save());

        $this->actingAs($this->owner($club))->put(route('club.members.update', $member), [
            'full_name' => $member->full_name, 'phone' => '91111111', 'status' => Member::ACTIVE, 'photo' => UploadedFile::fake()->image('new.jpg'),
        ])->assertSessionHasErrors();

        $this->assertSame('members/old.jpg', $member->fresh()->photo_path);
        Storage::disk('public')->assertExists('members/old.jpg');
    }
}
