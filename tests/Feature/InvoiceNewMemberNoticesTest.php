<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * «إشعارات العضو الجديد» on a new customer's invoice that registers them (owner's decision, 2026-11-21): consent to
 * service messages on WhatsApp kept on the member file (off unless ticked), the language of the messages, and — only
 * with that consent — the invoice and the welcome, each off unless ticked. Sent through the club's own notices
 * (InvoiceMessage, MemberNotifier::welcome: consent, approval, dedupe), once each, only once the invoice is issued and
 * saved; nothing at all otherwise — not even a skipped row that would stop a later message.
 */
class InvoiceNewMemberNoticesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function whatsapp(Tenant $club): void
    {
        Http::fake();
        $this->asTenant($club, function () {
            TenantIntegration::create(['provider' => 'whatsapp', 'is_enabled' => true, 'credentials' => ['phone_number_id' => 'PNID-9', 'access_token' => 'EAAG-test'], 'public_identifier' => 'PNID-9']);
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved']);
        });
    }

    /** @return array{plan: MembershipPlan, pt: PtPackage} */
    private function catalogue(Tenant $club): array
    {
        return $this->asTenant($club, fn () => [
            'plan' => MembershipPlan::create(['name' => 'Monthly', 'type' => 'combo', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 25]),
            'pt' => PtPackage::create(['name' => 'PT 4', 'sessions_count' => 4, 'session_minutes' => 60, 'price' => 40, 'validity_days' => 60]),
        ]);
    }

    private function form(array $lines, array $over = []): array
    {
        return array_replace([
            'customer_mode' => 'new', 'customer_name' => 'Sara', 'customer_phone' => '9'.random_int(1000000, 9999999), 'issue' => 1, 'payment_method' => 'credit',
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(7)->toDateString(), 'lines' => $lines, 'client_key' => (string) Str::uuid(),
        ], $over);
    }

    private function issue(User $by, array $form)
    {
        return $this->actingAs($by)->from('/app')->post(route('club.invoices.store'), $form);
    }

    private function planLine(MembershipPlan $plan): array
    {
        return ['item_type' => 'plan', 'item_id' => $plan->id, 'description' => $plan->name, 'quantity' => 1, 'unit_price' => 25, 'discount' => 0];
    }

    private function ptLine(PtPackage $pt): array
    {
        return ['item_type' => 'pt', 'item_id' => $pt->id, 'description' => $pt->name, 'quantity' => 1, 'unit_price' => 40, 'discount' => 0];
    }

    /** @return list<array{0: string, 1: string, 2: string|null}> template, status, dedupe key — every message row, skipped ones too */
    private function messages(Tenant $club): array
    {
        return $this->asTenant($club, fn () => Message::orderBy('id')->get()->map(fn (Message $m) => [$m->template_key, $m->status, $m->dedupe_key])->all());
    }

    public function test_nothing_is_consented_or_sent_unless_ticked(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->whatsapp($club);
        $c = $this->catalogue($club);

        $this->issue($owner, $this->form([$this->planLine($c['plan'])]))->assertSessionHasNoErrors();

        $this->asTenant($club, fn () => $this->assertSame([false, 'ar'], [Member::sole()->whatsapp_opt_in, Member::sole()->message_language], 'consent off unless ticked'));
        $this->assertSame([], $this->messages($club), 'no welcome, no invoice message, not even a skipped one');
    }

    public function test_with_consent_the_invoice_and_the_welcome_go_once_each_in_the_members_language(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->whatsapp($club);
        $c = $this->catalogue($club);
        $form = $this->form([$this->planLine($c['plan'])], ['new_member' => ['whatsapp_opt_in' => 1, 'message_language' => 'en', 'send_invoice' => 1, 'welcome' => 1]]);

        $this->issue($owner, $form)->assertSessionHasNoErrors();
        $this->issue($owner, $form)->assertSessionHasNoErrors(); // the same form again (a double click)

        [$member, $invoice] = $this->asTenant($club, fn () => [Member::sole(), Invoice::sole()]);
        $this->assertSame([true, 'en'], [$member->whatsapp_opt_in, $member->message_language], 'the customer\'s consent and language on their file');
        $this->assertSame([
            ['member_welcome', Message::SENT, 'welcome:'.$member->id],
            ['invoice_issued_membership_en', Message::SENT, 'invoice:'.$invoice->id],
        ], $this->messages($club), 'each once, the invoice in the member\'s language, sent after the invoice was saved');
    }

    public function test_an_arabic_customer_gets_the_arabic_invoice_message_and_consent_alone_sends_nothing(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->whatsapp($club);
        $c = $this->catalogue($club);

        $this->issue($owner, $this->form([$this->planLine($c['plan'])], ['customer_phone' => '91111111', 'new_member' => ['whatsapp_opt_in' => 1, 'message_language' => 'ar', 'send_invoice' => 1]]))->assertSessionHasNoErrors();
        $this->issue($owner, $this->form([$this->planLine($c['plan'])], ['customer_phone' => '92222222', 'new_member' => ['whatsapp_opt_in' => 1]]))->assertSessionHasNoErrors();

        $this->asTenant($club, function () {
            $first = Member::where('phone', 'like', '%91111111')->sole();
            $second = Member::where('phone', 'like', '%92222222')->sole();
            $this->assertTrue($second->whatsapp_opt_in, 'consent kept for later messages');
            $this->assertSame(['invoice_issued_membership'], Message::where('member_id', $first->id)->pluck('template_key')->all());
            $this->assertSame(0, Message::where('member_id', $second->id)->count(), 'consent alone sends nothing');
        });
    }

    public function test_the_invoice_or_the_welcome_without_consent_is_refused_and_nothing_is_made(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->whatsapp($club);
        $c = $this->catalogue($club);

        $this->issue($owner, $this->form([$this->planLine($c['plan'])], ['new_member' => ['send_invoice' => 1]]))->assertSessionHasErrors('new_member.send_invoice');
        $this->issue($owner, $this->form([$this->planLine($c['plan'])], ['new_member' => ['welcome' => 1]]))->assertSessionHasErrors('new_member.welcome');

        $this->assertSame([0, 0, 0], $this->asTenant($club, fn () => [Member::count(), Invoice::count(), Membership::count()]));
        $this->assertSame([], $this->messages($club));
    }

    public function test_nothing_leaves_when_issuing_fails_after_the_member_was_registered(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->whatsapp($club);
        $c = $this->catalogue($club);

        $this->issue($owner, $this->form([$this->planLine($c['plan'])], [
            'payment_method' => 'cash', 'paid_amount' => '999',
            'new_member' => ['whatsapp_opt_in' => 1, 'message_language' => 'ar', 'send_invoice' => 1, 'welcome' => 1],
        ]))->assertSessionHasErrors('paid_amount');

        $this->assertSame([0, 0], $this->asTenant($club, fn () => [Member::count(), Invoice::count()]));
        $this->assertSame([], $this->messages($club), 'the messages were written with the transaction, and went with it');
    }

    public function test_a_package_alone_is_sent_when_asked_and_an_invoice_paid_on_issue_asks_for_nothing(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->whatsapp($club);
        $c = $this->catalogue($club);

        // Personal training alone: the general invoice message, once.
        $this->issue($owner, $this->form([$this->ptLine($c['pt'])], ['customer_phone' => '93333333', 'new_member' => ['whatsapp_opt_in' => 1, 'send_invoice' => 1]]))->assertSessionHasNoErrors();
        // Paid in full on issue: no invoice message (nothing owed); the welcome asked for still goes, and — the customer
        // having consented — the club's own notices of a payment and an activation, as for every member.
        $this->issue($owner, $this->form([$this->planLine($c['plan'])], ['customer_phone' => '94444444', 'payment_method' => 'cash', 'new_member' => ['whatsapp_opt_in' => 1, 'send_invoice' => 1, 'welcome' => 1]]))->assertSessionHasNoErrors();

        $this->asTenant($club, function () {
            $pt = Member::where('phone', 'like', '%93333333')->sole();
            $paid = Member::where('phone', 'like', '%94444444')->sole();
            $this->assertSame(['invoice_issued'], Message::where('member_id', $pt->id)->pluck('template_key')->all());
            $this->assertSame(['member_welcome', 'payment_received', 'membership_activated'], Message::where('member_id', $paid->id)->orderBy('id')->pluck('template_key')->all());
            $this->assertSame(Membership::ACTIVE, Membership::where('member_id', $paid->id)->sole()->status, 'activation as before: paid in full');
        });
    }

    public function test_a_registered_members_invoice_is_announced_as_before(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->whatsapp($club);
        $c = $this->catalogue($club);
        $member = $this->member($club);

        $this->issue($owner, $this->form([$this->planLine($c['plan'])], ['customer_mode' => 'member', 'member_id' => $member->id]))->assertSessionHasNoErrors();

        $invoice = $this->asTenant($club, fn () => Invoice::sole());
        $this->assertSame([['invoice_issued_membership', Message::SENT]], $this->asTenant($club, fn () => Message::where('related_type', 'invoice')->where('related_id', $invoice->id)->get()->map(fn ($m) => [$m->template_key, $m->status])->all()));
    }
}
