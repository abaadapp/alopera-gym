<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\MemberNotifier;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Messaging\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The invoice message in the member's language (owner's decision, 2026-11-21): one Meta template,
 * alopera_invoice_issued_membership, in Arabic (invoice_issued_membership) and English (…_en), the same four values in
 * the same order. Sent automatically in the member's preferred language, and by hand with the member, the invoice and
 * the language chosen and the message read first — always with that invoice's own values, only for an open invoice of
 * that member, only by a template approved by Meta and active here (else the approved Arabic one, said so), with the
 * member's consent, once. A path that fills a member's values only never sends an invoice template empty.
 */
class InvoiceMessageLanguageTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @param  list<string>  $notApproved */
    private function whatsapp(Tenant $club, array $notApproved = []): void
    {
        Http::fake();
        $this->asTenant($club, function () use ($notApproved) {
            TenantIntegration::create(['provider' => 'whatsapp', 'is_enabled' => true, 'credentials' => ['phone_number_id' => 'PNID-3', 'access_token' => 'EAAG-secret'], 'public_identifier' => 'PNID-3']);
            TemplateCatalog::seedDefaults();
            MessageTemplate::whereNotIn('key', $notApproved)->update(['status' => 'approved']);
        });
    }

    private function approve(Tenant $club, string $key, string $status = 'approved', bool $active = true): void
    {
        $this->asTenant($club, fn () => MessageTemplate::where('key', $key)->update(['status' => $status, 'is_active' => $active]));
    }

    private function holding(Tenant $club, array $permissions): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.Str::lower(Str::random(8)), 'name' => 'مخصص', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
    }

    /** A membership sold on its screen: pending on its own invoice (the invoice message goes out with it). */
    private function membershipInvoice(Tenant $club, Member $member): Invoice
    {
        return $this->asTenant($club, fn () => app(MembershipService::class)->sell($member, $this->plan($club))->invoice()->firstOrFail());
    }

    private function plainInvoice(Tenant $club, Member $member, string $price = '12.500'): Invoice
    {
        return $this->asTenant($club, fn () => app(InvoiceService::class)->issue($member, [['description' => 'تأجير القاعة', 'quantity' => 1, 'unit_price' => $price]]));
    }

    /** @return list<string> */
    private function sent(Tenant $club, Invoice $invoice): array
    {
        return $this->asTenant($club, fn () => Message::where('related_type', 'invoice')->where('related_id', $invoice->id)->where('status', '!=', Message::SKIPPED)->orderBy('id')->pluck('template_key')->all());
    }

    private function preview(User $by, Member $member, Invoice $invoice, string $language)
    {
        return $this->actingAs($by)->postJson(route('club.whatsapp.invoice-messages.preview'), ['member_id' => $member->id, 'invoice_id' => $invoice->id, 'language' => $language]);
    }

    private function send(User $by, Member $member, Invoice $invoice, string $language)
    {
        return $this->actingAs($by)->postJson(route('club.whatsapp.invoice-messages.send'), ['member_id' => $member->id, 'invoice_id' => $invoice->id, 'language' => $language]);
    }

    public function test_one_meta_name_in_two_languages_with_the_same_values_in_the_same_order(): void
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TemplateCatalog::seedDefaults());
        [$ar, $en] = $this->asTenant($club, fn () => [MessageTemplate::where('key', 'invoice_issued_membership')->sole(), MessageTemplate::where('key', 'invoice_issued_membership_en')->sole()]);

        $this->assertSame(['alopera_invoice_issued_membership', 'ar'], [$ar->name, $ar->language]);
        $this->assertSame(['alopera_invoice_issued_membership', 'en'], [$en->name, $en->language]);
        $this->assertSame(['member_name', 'invoice_number', 'amount', 'payment_link'], $ar->variables);
        $this->assertSame($ar->variables, $en->variables);
        // Neither is sent before Meta approves it: both start as drafts.
        $this->assertSame(['draft', 'draft'], [$ar->status, $en->status]);
    }

    public function test_the_automatic_message_is_in_the_members_language_and_falls_back_to_the_approved_arabic_one(): void
    {
        $club = $this->club();
        $this->whatsapp($club);

        // English preferred and approved: English, with the invoice's own values.
        $en = $this->member($club, ['phone' => '96891234561', 'message_language' => 'en']);
        $invoice = $this->membershipInvoice($club, $en);
        $this->assertSame(['invoice_issued_membership_en'], $this->sent($club, $invoice));
        $body = $this->asTenant($club, fn () => Message::where('related_type', 'invoice')->where('related_id', $invoice->id)->sole()->body);
        $this->assertStringContainsString('starts once the invoice is paid in full', $body);
        $this->assertStringContainsString($invoice->number, $body);
        $this->assertStringContainsString(number_format((float) $invoice->total, 3), $body);

        // Arabic preferred: Arabic.
        $ar = $this->member($club, ['phone' => '96891234562']);
        $this->assertSame(['invoice_issued_membership'], $this->sent($club, $this->membershipInvoice($club, $ar)));

        // English not approved — or approved by Meta but switched off here: the approved Arabic one.
        $this->approve($club, 'invoice_issued_membership_en', 'pending');
        $this->assertSame(['invoice_issued_membership'], $this->sent($club, $this->membershipInvoice($club, $this->member($club, ['phone' => '96891234563', 'message_language' => 'en']))));
        $this->approve($club, 'invoice_issued_membership_en', 'approved', active: false);
        $this->assertSame(['invoice_issued_membership'], $this->sent($club, $this->membershipInvoice($club, $this->member($club, ['phone' => '96891234564', 'message_language' => 'en']))));

        // Neither membership wording approved: the plain invoice message, approved.
        $this->approve($club, 'invoice_issued_membership', 'draft');
        $this->assertSame(['invoice_issued'], $this->sent($club, $this->membershipInvoice($club, $this->member($club, ['phone' => '96891234565', 'message_language' => 'en']))));
    }

    public function test_by_hand_the_preview_carries_the_chosen_invoices_own_values_and_what_is_sent_is_that(): void
    {
        $club = $this->club();
        $this->whatsapp($club);
        $owner = $this->owner($club);
        $member = $this->member($club, ['phone' => '96891234566']);
        $first = $this->membershipInvoice($club, $member); // its automatic message goes out now
        $second = $this->plainInvoice($club, $member, '12.500');

        // Her open invoices to choose from, and her language.
        $this->actingAs($owner)->getJson(route('club.whatsapp.invoice-messages.invoices', ['member_id' => $member->id]))->assertOk()
            ->assertJsonPath('member.language', 'ar')->assertJsonCount(2, 'invoices')
            ->assertJsonPath('invoices.0.id', $second->id)->assertJsonPath('invoices.1.membership', true);

        // The second, in English: no English template for an invoice without a membership — the Arabic one, said so.
        $preview = $this->preview($owner, $member, $second, 'en')->assertOk()->json();
        $this->assertTrue($preview['ok']);
        $this->assertSame(['invoice_issued', 'ar'], [$preview['template_key'], $preview['language']]);
        $this->assertStringContainsString('بالقالب العربي المعتمد', (string) $preview['fallback']);
        $this->assertStringContainsString($second->number, $preview['body']);
        $this->assertStringContainsString('12.500', $preview['body']);
        $this->assertStringNotContainsString($first->number, $preview['body']);

        // The first, in English: the English membership wording, with its own number and amount.
        $english = $this->preview($owner, $member, $first, 'en')->json();
        $this->assertSame(['invoice_issued_membership_en', 'en', null], [$english['template_key'], $english['language'], $english['fallback']]);
        $this->assertStringContainsString($first->number, $english['body']);

        $this->send($owner, $member, $first, 'en')->assertOk()->assertJsonPath('template_key', 'invoice_issued_membership_en');
        $sent = $this->asTenant($club, fn () => Message::where('related_id', $first->id)->where('template_key', 'invoice_issued_membership_en')->latest('id')->firstOrFail());
        $this->assertSame($english['body'], $sent->body);
    }

    public function test_by_hand_another_members_or_a_settled_invoice_an_unapproved_template_or_no_consent_send_nothing(): void
    {
        $club = $this->club();
        $this->whatsapp($club, notApproved: ['invoice_issued', 'invoice_issued_membership', 'invoice_issued_membership_en']);
        $owner = $this->owner($club);
        $member = $this->member($club, ['phone' => '96891234567']);
        $other = $this->member($club, ['phone' => '96891234568']);
        $theirs = $this->plainInvoice($club, $other);
        $ours = $this->plainInvoice($club, $member);
        $invoiceMessages = fn () => $this->asTenant($club, fn () => Message::where('template_key', 'like', 'invoice_issued%')->where('status', '!=', Message::SKIPPED)->count());
        $before = $invoiceMessages();

        // Another member's invoice: refused, whatever the template.
        $this->preview($owner, $member, $theirs, 'ar')->assertJsonPath('ok', false)->assertJsonPath('error', 'هذه الفاتورة لا تخص هذا العضو.');
        $this->send($owner, $member, $theirs, 'ar')->assertStatus(422);
        // No template approved: not sent, said why.
        $this->preview($owner, $member, $ours, 'ar')->assertJsonPath('ok', false)->assertJsonPath('error', fn ($e) => str_contains($e, 'لا قالب معتمد'));
        $this->send($owner, $member, $ours, 'ar')->assertStatus(422);

        $this->approve($club, 'invoice_issued');
        // Paid in full, or voided: nothing owed.
        $paid = $this->plainInvoice($club, $member);
        $this->asTenant($club, fn () => app(PaymentService::class)->record($paid, $paid->total, 'cash'));
        $this->send($owner, $member, $paid->fresh(), 'ar')->assertStatus(422)->assertJsonPath('error', 'لا مبلغ مستحق على هذه الفاتورة.');
        $void = $this->plainInvoice($club, $member);
        $this->asTenant($club, fn () => app(InvoiceService::class)->void($void, 'خطأ'));
        $this->send($owner, $member, $void->fresh(), 'ar')->assertStatus(422);
        // No consent to service messages.
        $this->asTenant($club, fn () => $member->forceFill(['whatsapp_opt_in' => false])->save());
        $this->preview($owner, $member->fresh(), $ours, 'ar')->assertJsonPath('ok', false);
        $this->send($owner, $member->fresh(), $ours, 'ar')->assertStatus(422);
        // Without the permissions.
        $this->actingAs($this->holding($club, ['invoices.view']))->postJson(route('club.whatsapp.invoice-messages.send'), ['member_id' => $member->id, 'invoice_id' => $ours->id, 'language' => 'ar'])->assertForbidden();
        $this->actingAs($this->holding($club, ['whatsapp.send']))->postJson(route('club.whatsapp.invoice-messages.preview'), ['member_id' => $member->id, 'invoice_id' => $ours->id, 'language' => 'ar'])->assertForbidden();
        $this->actingAs($owner)->postJson(route('club.whatsapp.invoice-messages.send'), ['member_id' => $member->id, 'invoice_id' => $ours->id, 'language' => 'fr'])->assertStatus(422);

        $this->assertSame($before, $invoiceMessages());
    }

    /**
     * What leaves for Meta (owner, 2026-11-21: both languages approved there): the approved name, the language code
     * and the four values in order, positional {{1}}..{{4}} — automatically once, by hand in either language — and the
     * link opens that very invoice.
     */
    public function test_what_leaves_for_meta_is_the_approved_name_its_language_code_and_the_four_values_and_the_link_opens_the_invoice(): void
    {
        // The real Meta gateway, Meta answered by a fake: what it is sent is what Meta would receive.
        config(['services.whatsapp.driver' => 'meta']);
        Http::fake(['https://graph.facebook.com/*' => fn () => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.'.Str::random(8)]]], 200)]);
        $club = $this->club();
        $this->whatsapp($club);
        $owner = $this->owner($club);
        $sara = $this->member($club, ['full_name' => 'سارة الهنائي', 'phone' => '96891234571']);
        $lina = $this->member($club, ['full_name' => 'Lina Smith', 'phone' => '96891234572', 'message_language' => 'en']);
        $arInvoice = $this->membershipInvoice($club, $sara);
        $enInvoice = $this->membershipInvoice($club, $lina);

        $out = fn (string $to) => collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_ends_with($r->url(), '/PNID-3/messages') && ($r->data()['to'] ?? null) === $to && str_starts_with($r->data()['template']['name'] ?? '', 'alopera_invoice_issued'))
            ->map(fn ($r) => $r->data()['template'])->values();
        $expect = function (array $template, string $language, Member $member, Invoice $invoice) use ($club) {
            $this->assertSame('alopera_invoice_issued_membership', $template['name']);
            $this->assertSame(['code' => $language], $template['language']);
            $params = array_column($template['components'][0]['parameters'], 'text');
            $link = $this->asTenant($club, fn () => app(MemberNotifier::class)->paymentLink($invoice->fresh()));
            $this->assertSame([explode(' ', $member->full_name)[0], $invoice->number, number_format((float) $invoice->total, 3).' '.$invoice->currency, $link], $params);
            $this->assertSame(['body'], array_column($template['components'], 'type'));

            return $link;
        };

        // Automatically, in each member's language, once.
        $this->assertCount(1, $out('96891234571'));
        $this->assertCount(1, $out('96891234572'));
        $arLink = $expect($out('96891234571')[0], 'ar', $sara, $arInvoice);
        $enLink = $expect($out('96891234572')[0], 'en', $lina, $enInvoice);

        // Announced again (a second save, a retry): nothing leaves a second time.
        $this->asTenant($club, fn () => app(MemberNotifier::class)->invoiceIssued($sara->fresh(), $arInvoice->fresh()));
        $this->assertCount(1, $out('96891234571'));

        // By hand, in the other language each: the same name, that language's code, the same values.
        $this->send($owner, $sara, $arInvoice, 'en')->assertOk()->assertJsonPath('template_key', 'invoice_issued_membership_en');
        $this->send($owner, $lina, $enInvoice, 'ar')->assertOk()->assertJsonPath('template_key', 'invoice_issued_membership');
        $this->assertSame($arLink, $expect($out('96891234571')[1], 'en', $sara, $arInvoice));
        $this->assertSame($enLink, $expect($out('96891234572')[1], 'ar', $lina, $enInvoice));

        // Each link opens its own invoice, not the other's.
        $this->get($arLink)->assertOk()->assertSee($arInvoice->number)->assertDontSee($enInvoice->number);
        $this->get($enLink)->assertOk()->assertSee($enInvoice->number)->assertDontSee($arInvoice->number);
    }

    public function test_a_second_send_of_the_same_invoice_within_a_minute_is_not_sent_again(): void
    {
        $club = $this->club();
        $this->whatsapp($club);
        $owner = $this->owner($club);
        $member = $this->member($club, ['phone' => '96891234569']);
        $invoice = $this->plainInvoice($club, $member);

        $this->send($owner, $member, $invoice, 'ar')->assertOk();
        $this->send($owner, $member, $invoice, 'ar')->assertStatus(422)->assertJsonPath('error', fn ($e) => str_contains($e, 'قبل أقل من دقيقة'));
        $this->assertSame(['invoice_issued'], $this->sent($club, $invoice));
    }

    public function test_a_path_with_the_members_values_only_never_sends_an_invoice_template_empty(): void
    {
        $club = $this->club();
        $this->whatsapp($club);
        $owner = $this->owner($club);
        $member = $this->member($club, ['phone' => '96891234570']);

        // The member's page: not offered there, refused if asked.
        $this->actingAs($owner)->get(route('club.members.show', $member))->assertInertia(fn ($p) => $p->where('templates', fn ($t) => collect($t)->pluck('key')->intersect(['invoice_issued', 'invoice_issued_membership', 'invoice_issued_membership_en', 'payment_received'])->isEmpty()));
        $this->actingAs($owner)->post(route('club.members.whatsapp', $member), ['template_key' => 'invoice_issued_membership'])->assertSessionHasErrors('template_key');
        // And whatever path: an invoice template with its values empty is not sent (logged as not sent, and why).
        $message = $this->asTenant($club, fn () => app(WhatsAppService::class)->queueTemplate($member, 'invoice_issued', ['member_name' => 'سارة'], null, 'test:empty'));
        $this->assertSame([Message::SKIPPED, 'missing_values'], [$message->status, $message->error_code]);
        $this->assertSame(0, $this->asTenant($club, fn () => Message::where('template_key', 'like', 'invoice_issued%')->where('status', '!=', Message::SKIPPED)->count()));
    }

    public function test_the_member_form_keeps_her_message_language(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club, ['phone' => '96891234571']);
        $this->assertSame('ar', $member->fresh()->message_language);

        $form = ['full_name' => $member->full_name, 'phone' => '96891234571', 'whatsapp_opt_in' => true, 'marketing_opt_in' => false];
        $this->actingAs($owner)->put(route('club.members.update', $member), $form + ['message_language' => 'en'])->assertSessionHasNoErrors();
        $this->assertSame('en', $this->asTenant($club, fn () => $member->fresh()->message_language));
        $this->actingAs($owner)->put(route('club.members.update', $member), $form + ['message_language' => 'fr'])->assertSessionHasErrors('message_language');
        $this->actingAs($owner)->get(route('club.members.edit', $member))->assertInertia(fn ($p) => $p->where('member.message_language', 'en'));
    }
}
