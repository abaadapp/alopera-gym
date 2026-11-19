<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\GiftService;
use App\Modules\Gym\Services\GiftWhatsApp;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\SendsGiftWhatsApp;
use Tests\TestCase;

/**
 * The gift's WhatsApp language and the giver's hidden name (#192 follow-up). The giver picks the language the recipient
 * reads (ar|en), whatever the page's: the gift goes with that language's approved template of alopera_gift_received,
 * and with nothing else — never the other language instead. A giver who hid their name is never named to the
 * recipient (the message, the card, the share text, the claim pages); staff, the invoice and the audit keep the real
 * name. No free text from the giver reaches the message any more: three values, the giver, the plan, the link.
 */
class GiftLanguageAnonymityTest extends TestCase
{
    use BuildsClubs, RefreshDatabase, SendsGiftWhatsApp;

    private const REAL_NAME = 'Hidden Giver';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** The real Meta path: each send answered with its own id (the requests are what the test reads). */
    private function metaAnswers(): void
    {
        config(['services.whatsapp.driver' => 'meta']);
        Http::fake(['https://graph.facebook.com/*' => fn () => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.'.Str::random(12)]]], 200)]);
    }

    /** @return list<array<string, mixed>> the template requests sent to Meta, oldest first */
    private function templatesSent(): array
    {
        return Http::recorded(fn (HttpRequest $r) => ($r->data()['type'] ?? null) === 'template')->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    private function onlinePayments(Tenant $club): void
    {
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'h', 'integration_ids' => '111'],
        ]));
    }

    private function goldPlan(Tenant $club): MembershipPlan
    {
        return $this->plan($club, ['price' => 30, 'name' => 'ذهبية', 'name_en' => 'Gold']);
    }

    private function buyer(array $overrides = []): array
    {
        return $overrides + ['giver_name' => self::REAL_NAME, 'giver_phone' => '91234567', 'recipient_name' => 'Noor', 'recipient_phone' => '95551234', 'accept_terms' => '1'];
    }

    /** The website's gift as the form sends it, from the Arabic page or the English one. */
    private function buyOnSite(Tenant $club, MembershipPlan $plan, array $form, bool $englishPage = false): GiftMembership
    {
        $before = $this->asTenant($club, fn () => GiftMembership::max('id') ?? 0);
        $url = $englishPage ? '/c/'.$club->slug.'/en/gift/plan/'.$plan->id : route('site.gift.store', ['club' => $club->slug, 'plan' => $plan->id]);
        $this->post($url, $this->buyer($form))->assertSessionHasNoErrors()->assertRedirect();

        return $this->asTenant($club, fn () => GiftMembership::where('id', '>', $before)->sole());
    }

    private function deskGift(Tenant $club, array $data = []): GiftMembership
    {
        $plan = $this->goldPlan($club);

        return $this->asTenant($club, fn () => app(GiftService::class)->issue($plan, $data + ['giver_name' => self::REAL_NAME, 'recipient_name' => 'Noor', 'recipient_phone' => '95551234'], 'staff'));
    }

    /** Her number confirmed by her own message, then paid in full at the desk: the moment the gift may go. */
    private function confirmAndPay(Tenant $club, GiftMembership $gift): GiftMembership
    {
        $this->recipientConfirms($club, $gift)->assertOk();
        $this->asTenant($club, fn () => app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash'));

        return $this->asTenant($club, fn () => $gift->fresh());
    }

    public function test_arabic_and_english_each_go_with_their_own_template_whatever_the_page_language(): void
    {
        $this->metaAnswers();
        $club = $this->club();
        $this->onlinePayments($club);
        $this->connectWhatsApp($club);
        $plan = $this->goldPlan($club);

        // English chosen on the Arabic page; Arabic chosen on the English page: the giver's choice is what is kept.
        $english = $this->buyOnSite($club, $plan, ['message_language' => 'en']);
        $arabic = $this->buyOnSite($club, $plan, ['message_language' => 'ar'], englishPage: true);
        $this->assertSame(['en', 'ar'], [$english->message_language, $arabic->message_language]);

        $english = $this->confirmAndPay($club, $english);
        $arabic = $this->confirmAndPay($club, $arabic);

        $sent = $this->templatesSent();
        $this->assertCount(2, $sent, 'one message per gift');
        $byLink = collect($sent)->keyBy(fn ($d) => $d['template']['components'][0]['parameters'][2]['text']);
        $links = $this->asTenant($club, fn () => [$english->linkToken(), $arabic->linkToken()]);
        $en = $byLink->first(fn ($d, $link) => str_contains($link, $links[0]));
        $ar = $byLink->first(fn ($d, $link) => str_contains($link, $links[1]));

        // One Meta name, the language the giver picked, the three values in that language.
        $this->assertSame(['alopera_gift_received', 'en'], [$en['template']['name'], $en['template']['language']['code']]);
        $this->assertSame(['alopera_gift_received', 'ar'], [$ar['template']['name'], $ar['template']['language']['code']]);
        $this->assertSame([self::REAL_NAME, 'Gold'], array_slice(array_column($en['template']['components'][0]['parameters'], 'text'), 0, 2));
        $this->assertSame([self::REAL_NAME, 'ذهبية'], array_slice(array_column($ar['template']['components'][0]['parameters'], 'text'), 0, 2));
        $this->assertCount(3, $en['template']['components'][0]['parameters']);

        // Her confirmation is answered in the gift's language too.
        $texts = Http::recorded(fn (HttpRequest $r) => ($r->data()['type'] ?? null) === 'text')->map(fn ($p) => $p[0]->data()['text']['body'])->all();
        $this->assertContains('Thank you, your number is confirmed.', $texts);
        $this->assertContains('شكرًا لك، تم تأكيد رقمك.', $texts);
        $this->asTenant($club, fn () => $this->assertSame(['gift_received', 'gift_received_en'], Message::where('type', 'template')->orderBy('template_key')->pluck('template_key')->all()));
    }

    public function test_the_language_starts_as_the_pages_and_the_form_no_longer_takes_a_message(): void
    {
        $club = $this->club();
        $this->onlinePayments($club);
        $this->connectWhatsApp($club);
        $plan = $this->goldPlan($club);

        $this->get(route('site.gift.create', ['club' => $club->slug, 'plan' => $plan->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Site/GiftBuy')->where('message_language', 'ar'));
        $this->get('/c/'.$club->slug.'/en/gift/plan/'.$plan->id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Site/GiftBuy')->where('message_language', 'en'));

        // A form that says no language (a page from before) takes the page's; a message sent anyway is not kept.
        $gift = $this->buyOnSite($club, $plan, ['message' => 'Happy birthday from me'], englishPage: true);
        $this->assertSame('en', $gift->message_language);
        $this->assertNull($gift->message);
        $this->assertFalse($gift->is_anonymous, 'the name is shown unless the giver hides it');
        $this->post(route('site.gift.store', ['club' => $club->slug, 'plan' => $plan->id]), $this->buyer(['message_language' => 'fr']))->assertSessionHasErrors('message_language');
    }

    public function test_a_hidden_giver_is_never_named_to_the_recipient_but_staff_and_the_invoice_keep_the_name(): void
    {
        $this->metaAnswers();
        $club = $this->club();
        $this->onlinePayments($club);
        $this->connectWhatsApp($club);
        $plan = $this->goldPlan($club);

        $named = $this->confirmAndPay($club, $this->deskGift($club, ['giver_name' => 'Sara Named']));
        $hiddenAr = $this->confirmAndPay($club, $this->buyOnSite($club, $plan, ['message_language' => 'ar', 'is_anonymous' => '1']));
        $hiddenEn = $this->confirmAndPay($club, $this->buyOnSite($club, $plan, ['message_language' => 'en', 'is_anonymous' => '1']));
        $this->assertTrue($hiddenAr->is_anonymous);

        // The message: the real name when shown, the wording of the gift's language when hidden.
        $givers = collect($this->templatesSent())->map(fn ($d) => $d['template']['components'][0]['parameters'][0]['text'])->all();
        $this->assertSame(['Sara Named', 'شخص يفضّل عدم ذكر اسمه', 'Someone who prefers to remain anonymous'], $givers);
        $this->asTenant($club, fn () => $this->assertStringNotContainsString(self::REAL_NAME, Message::where('type', 'template')->pluck('body')->implode(' ')));

        // Everything the link opens, in both languages, for anyone holding it: no real name anywhere in the response.
        $this->flushSession();
        $token = $this->asTenant($club, fn () => $hiddenAr->linkToken());
        $pages = [
            route('site.gift.show', ['club' => $club->slug, 'token' => $token]),
            '/c/'.$club->slug.'/en/gift/'.$token,
            route('site.gift.claim', ['club' => $club->slug, 'token' => $token]),
        ];
        foreach ($pages as $url) {
            $response = $this->get($url)->assertOk();
            $this->assertStringNotContainsString(self::REAL_NAME, $response->getContent(), $url);
            $this->assertStringNotContainsString(rawurlencode(self::REAL_NAME), $response->getContent(), $url);
        }
        $this->get($pages[0])->assertInertia(fn (AssertableInertia $page) => $page->where('gift.giver_name', 'شخص يفضّل عدم ذكر اسمه')
            ->where('whatsapp', fn ($share) => str_contains(rawurldecode((string) $share), 'شخص يفضّل عدم ذكر اسمه')));
        $this->get($pages[1])->assertInertia(fn (AssertableInertia $page) => $page->where('gift.giver_name', 'Someone who prefers to remain anonymous'));
        // A giver who did not hide it is named on the card.
        $namedToken = $this->asTenant($club, fn () => $named->linkToken());
        $this->get(route('site.gift.show', ['club' => $club->slug, 'token' => $namedToken]))->assertInertia(fn (AssertableInertia $page) => $page->where('gift.giver_name', 'Sara Named'));

        // Staff, the invoice and the audit trail keep the real name, and the panel says it is hidden from her.
        $this->asTenant($club, function () use ($hiddenAr) {
            $this->assertSame(self::REAL_NAME, $hiddenAr->fresh()->giver_name);
            $this->assertSame(self::REAL_NAME, $hiddenAr->invoice->customer_name);
            $this->assertTrue(AuditLog::where('event', 'gift.created')->where('subject_id', $hiddenAr->id)->sole()->properties['anonymous']);
        });
        $this->actingAs($this->owner($club))->get(route('club.gifts.show', $hiddenAr))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('gift.giver_name', self::REAL_NAME)->where('gift.is_anonymous', true)
            ->where('gift.public_giver_name', 'شخص يفضّل عدم ذكر اسمه')->where('gift.message_language_label', 'العربية'));
        $this->actingAs($this->owner($club))->get(route('club.gifts.show', $hiddenEn))->assertInertia(fn (AssertableInertia $page) => $page->where('gift.message_language', 'en')->where('gift.message_language_label', 'English'));
    }

    public function test_nothing_goes_without_the_approved_template_of_its_language_and_never_in_the_other_one(): void
    {
        $this->metaAnswers();
        $club = $this->club();
        $this->connectWhatsApp($club);
        $this->asTenant($club, fn () => MessageTemplate::where('key', 'gift_received_en')->update(['status' => 'draft']));

        $english = $this->confirmAndPay($club, $this->deskGift($club, ['message_language' => 'en']));
        $arabic = $this->confirmAndPay($club, $this->deskGift($club, ['message_language' => 'ar']));
        $this->asTenant($club, fn () => app(GiftWhatsApp::class)->sendPending());
        Artisan::call('gym:send-reminders', ['--due' => true]);

        // Only the Arabic gift went, with the Arabic template; the English one waits, said why, and not in Arabic.
        $sent = $this->templatesSent();
        $this->assertCount(1, $sent);
        $this->assertSame('ar', $sent[0]['template']['language']['code']);
        $this->asTenant($club, function () use ($english, $arabic) {
            $this->assertNull($english->fresh()->whatsapp_message_id);
            $this->assertNotNull($arabic->fresh()->whatsapp_message_id);
            $delivery = app(GiftWhatsApp::class)->delivery($english->fresh());
            $this->assertSame('waiting', $delivery['state']);
            $this->assertFalse($delivery['can_send']);
            $this->assertStringContainsString('(الإنجليزية) لم يُعتمد', (string) $delivery['reason']);
            try {
                app(GiftWhatsApp::class)->resend($english->fresh());
                $this->fail('sent without its approved template');
            } catch (BusinessRuleException $e) {
                $this->assertSame('gift.whatsapp_template_not_approved', $e->rule);
            }
        });

        // Approved later: staff's «إرسال الآن» sends it in the language the giver picked, once.
        $this->asTenant($club, fn () => MessageTemplate::where('key', 'gift_received_en')->update(['status' => 'approved']));
        $this->actingAs($this->owner($club))->post(route('club.gifts.resend', $english))->assertSessionHasNoErrors();
        $this->post(route('club.gifts.resend', $english));
        $sent = $this->templatesSent();
        $this->assertCount(2, $sent, 'sent once by staff, the second press refused while it is on its way');
        $this->assertSame('en', $sent[1]['template']['language']['code']);
    }

    public function test_an_older_four_value_template_is_never_sent_with_and_a_draft_one_takes_the_new_text(): void
    {
        $club = $this->club();
        $this->connectWhatsApp($club);
        // As the panel may hold it from before: the Arabic gift template with the giver's message, approved.
        $old = ['giver_name', 'plan_name', 'gift_message', 'gift_link'];
        $this->asTenant($club, fn () => MessageTemplate::where('key', 'gift_received')->update(['variables' => json_encode($old), 'body' => 'old text {{1}} {{2}} {{3}} {{4}}']));

        $gift = $this->confirmAndPay($club, $this->deskGift($club));
        $this->asTenant($club, function () use ($gift, $old) {
            $this->assertSame(0, Message::where('type', 'template')->count(), 'not sent with a template whose {{n}} mean other things');
            $this->assertStringContainsString('من الإصدار السابق', (string) app(GiftWhatsApp::class)->delivery($gift)['reason']);
            // Approved or pending, it is the owner's: not rewritten.
            TemplateCatalog::seedDefaults();
            $this->assertSame($old, MessageTemplate::where('key', 'gift_received')->value('variables'));
        });
        $this->actingAs($this->owner($club))->get(route('club.automation.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events', fn ($events) => collect($events)->firstWhere('key', 'gift_received')['templates'][0]['approved'] === false
                && collect(collect($events)->firstWhere('key', 'gift_received')['templates'])->pluck('key')->all() === ['gift_received', 'gift_received_en']));

        // Back to a draft: it takes the new text and its three values.
        $this->asTenant($club, function () {
            MessageTemplate::where('key', 'gift_received')->update(['status' => 'draft']);
            TemplateCatalog::seedDefaults();
            $template = MessageTemplate::where('key', 'gift_received')->sole();
            $this->assertSame(['giver_name', 'plan_name', 'gift_link'], $template->variables);
            $this->assertSame(TemplateCatalog::EVENTS['gift_received']['body'], $template->body);
            // The English one: the same Meta name, in English.
            $this->assertSame(['name' => 'alopera_gift_received', 'language' => 'en'], MessageTemplate::where('key', 'gift_received_en')->sole()->only('name', 'language'));
        });
    }

    public function test_an_english_gift_goes_once_however_often_its_payment_or_confirmation_is_reported(): void
    {
        $club = $this->club();
        $this->connectWhatsApp($club);
        $gift = $this->confirmAndPay($club, $this->deskGift($club, ['message_language' => 'en', 'is_anonymous' => true]));
        $this->asTenant($club, function () use ($gift) {
            app(GiftService::class)->reconcilePaid();
            app(GiftWhatsApp::class)->sendPending();
            app(GiftWhatsApp::class)->sendPending();
            app(GiftWhatsApp::class)->queue($gift->fresh());
        });
        Artisan::call('gym:send-reminders', ['--due' => true]);

        $this->asTenant($club, function () use ($gift) {
            $message = Message::where('type', 'template')->sole();
            $this->assertSame(['gift_received_en', 'gift:'.$gift->id.':1'], [$message->template_key, $message->dedupe_key]);
            $this->assertSame('Someone who prefers to remain anonymous', $message->template_params[0]);
            $this->assertSame(1, $gift->fresh()->whatsapp_attempts);
        });
    }

    public function test_the_desk_issues_a_gift_with_its_language_and_the_giver_hidden(): void
    {
        $club = $this->club();
        $plan = $this->goldPlan($club);
        $this->actingAs($this->owner($club))->post(route('club.gifts.store'), [
            'membership_plan_id' => $plan->id, 'giver_name' => self::REAL_NAME, 'recipient_name' => 'Noor', 'recipient_phone' => '95551234',
            'message_language' => 'en', 'is_anonymous' => true, 'payment_method' => 'later',
        ])->assertSessionHasNoErrors();
        $this->asTenant($club, function () {
            $gift = GiftMembership::sole();
            $this->assertSame(['en', true, self::REAL_NAME], [$gift->message_language, $gift->is_anonymous, $gift->giver_name]);
        });
        // Not said at the desk: Arabic, the name shown, as every gift so far.
        $this->post(route('club.gifts.store'), [
            'membership_plan_id' => $plan->id, 'giver_name' => 'Sara', 'recipient_name' => 'Noor', 'recipient_phone' => '95551234', 'payment_method' => 'later',
        ])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(['ar', false], [GiftMembership::latest('id')->first()->message_language, GiftMembership::latest('id')->first()->is_anonymous]));
    }
}
