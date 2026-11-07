<?php

namespace Tests\Feature;

use App\Core\Auth\SystemRoles;
use App\Models\Role;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Models\Attachment;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationEvent;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Messaging\Support\WhatsAppPermissionUpgrade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The WhatsApp inbox and who may do what in it, checked on the server: the section, each conversation (list, thread,
 * live feed, files, every action), the member's data on the card, and the club's walls. The development driver is on
 * and HTTP is faked: no message leaves.
 */
class WhatsAppInboxTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    private function connect(Tenant $club): void
    {
        $this->asTenant($club, function () {
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved']);
            TenantIntegration::create(['provider' => 'whatsapp', 'is_enabled' => true, 'public_identifier' => 'PNID-'.Str::random(5),
                'credentials' => ['phone_number_id' => 'x', 'access_token' => 'y'], 'settings' => ['vendor' => 'meta']]);
        });
    }

    /** A conversation where the customer has just written (the 24-hour window is open). */
    private function chat(Tenant $club, string $phone, array $attributes = []): Conversation
    {
        return $this->asTenant($club, function () use ($phone, $attributes) {
            $conversation = Conversation::create($attributes + ['phone' => $phone, 'contact_name' => 'Customer '.$phone, 'last_inbound_at' => now(), 'first_inbound_at' => now(),
                'last_message_at' => now(), 'status' => Conversation::NEW]);
            Message::create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'source' => Message::SOURCE_CUSTOMER, 'type' => 'text',
                'to_phone' => $phone, 'body' => 'سؤال', 'status' => Message::RECEIVED]);

            return $conversation;
        });
    }

    /** @param list<string> $permissions */
    private function role(Tenant $club, array $permissions): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.Str::random(6), 'name' => 'Custom', 'permissions' => $permissions, 'is_system' => false]));
        $user = new User(['name' => 'Custom '.Str::random(3), 'email' => Str::lower(Str::random(10)).'@staff.test', 'password' => 'password']);
        $user->tenant_id = $club->id;
        $user->role_id = $role->id;
        $user->save();

        return $user;
    }

    public function test_the_section_is_closed_to_staff_without_a_whatsapp_permission(): void
    {
        $club = $this->club();
        $chat = $this->chat($club, '96891000001');

        foreach ([$this->staff($club, SystemRoles::TRAINER), $this->staff($club, SystemRoles::CASHIER), $this->staff($club, SystemRoles::FINANCE)] as $user) {
            $this->actingAs($user)->get(route('club.whatsapp.index'))->assertForbidden();
            $this->actingAs($user)->getJson(route('club.whatsapp.feed'))->assertForbidden();
            $this->actingAs($user)->getJson(route('club.whatsapp.show', $chat))->assertForbidden();
            $this->actingAs($user)->postJson(route('club.whatsapp.reply', $chat), ['body' => 'x'])->assertForbidden();
        }
    }

    public function test_reception_sees_and_answers_only_the_chats_assigned_to_it(): void
    {
        $club = $this->club();
        $this->connect($club);
        $reception = $this->staff($club, SystemRoles::RECEPTION);
        $mine = $this->chat($club, '96891000001', ['assigned_to' => $reception->id]);
        $other = $this->chat($club, '96891000002');
        $file = $this->asTenant($club, fn () => Message::where('conversation_id', $other->id)->first()->attachments()->create(['kind' => 'image', 'mime' => 'image/jpeg', 'path' => 'whatsapp/x.jpg', 'status' => Attachment::STORED]));
        Storage::disk('local')->put('whatsapp/x.jpg', 'JPEG');

        $page = $this->actingAs($reception)->get(route('club.whatsapp.index'))->assertOk()->viewData('page')['props'];
        $this->assertSame([$mine->id], collect($page['conversations'])->pluck('id')->all());
        $this->assertSame('assigned', $page['scope']);
        $this->assertSame([$mine->id], collect($this->actingAs($reception)->getJson(route('club.whatsapp.feed', ['c' => $other->id]))->json('conversations'))->pluck('id')->all());
        $this->assertTrue($this->actingAs($reception)->getJson(route('club.whatsapp.feed', ['c' => $other->id]))->json('thread.gone'));

        // Someone else's chat does not exist for reception, whichever way it is reached.
        $this->actingAs($reception)->getJson(route('club.whatsapp.show', $other))->assertNotFound();
        $this->actingAs($reception)->postJson(route('club.whatsapp.reply', $other), ['body' => 'x'])->assertNotFound();
        $this->actingAs($reception)->postJson(route('club.whatsapp.notes', $other), ['body' => 'x'])->assertNotFound();
        $this->actingAs($reception)->postJson(route('club.whatsapp.status', $other), ['status' => 'closed'])->assertNotFound();
        $this->actingAs($reception)->get(route('club.whatsapp.attachments.show', $file))->assertNotFound();

        // Its own: answered (named as the sender), but not given away, linked or sent files without those permissions.
        $this->actingAs($reception)->postJson(route('club.whatsapp.reply', $mine), ['body' => 'أهلًا بك'])->assertOk()->assertJsonPath('message.sender', $reception->name);
        $this->actingAs($reception)->postJson(route('club.whatsapp.assign', $mine), ['user_id' => null])->assertForbidden();
        $this->actingAs($reception)->postJson(route('club.whatsapp.link', $mine), ['member_id' => null])->assertForbidden();
        $this->actingAs($reception)->postJson(route('club.whatsapp.media', $mine), ['file' => UploadedFile::fake()->image('a.jpg')])->assertForbidden();
        $this->actingAs($reception)->get(route('club.whatsapp.reports'))->assertForbidden();
        $this->asTenant($club, fn () => $this->assertSame(0, Message::where('conversation_id', $other->id)->where('direction', 'outbound')->count()));
    }

    public function test_a_manager_assigns_and_transfers_and_the_new_holder_is_told(): void
    {
        $club = $this->club();
        $this->connect($club);
        $manager = $this->staff($club, SystemRoles::MANAGER);
        $first = $this->staff($club, SystemRoles::RECEPTION, ['name' => 'Salma']);
        $second = $this->staff($club, SystemRoles::RECEPTION, ['name' => 'Maryam']);
        $chat = $this->chat($club, '96891000003');

        $this->actingAs($manager)->postJson(route('club.whatsapp.assign', $chat), ['user_id' => $first->id])->assertOk();
        $this->actingAs($first)->getJson(route('club.whatsapp.show', $chat))->assertOk();
        $this->actingAs($manager)->postJson(route('club.whatsapp.assign', $chat), ['user_id' => $second->id])->assertOk();
        $this->actingAs($first)->getJson(route('club.whatsapp.show', $chat))->assertNotFound();
        $this->actingAs($second)->getJson(route('club.whatsapp.show', $chat))->assertOk();

        $this->asTenant($club, function () use ($chat, $second) {
            $this->assertSame(Conversation::OPEN, $chat->fresh()->status);
            $this->assertSame([ConversationEvent::ASSIGNED, ConversationEvent::ASSIGNED], ConversationEvent::where('conversation_id', $chat->id)->pluck('type')->all());
            $this->assertSame(1, StaffNotification::where('user_id', $second->id)->where('key', 'like', 'whatsapp-assigned:%')->count());
        });
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.conversation_transferred', 'subject_id' => $chat->id]);

        // Only to someone who may hold chats.
        $trainer = $this->staff($club, SystemRoles::TRAINER);
        $this->actingAs($manager)->postJson(route('club.whatsapp.assign', $chat), ['user_id' => $trainer->id])->assertStatus(422);
    }

    public function test_an_internal_note_stays_inside_and_a_chat_is_closed_and_opened_again(): void
    {
        $club = $this->club();
        $this->connect($club);
        $owner = $this->owner($club);
        $chat = $this->chat($club, '96891000004');

        $this->actingAs($owner)->postJson(route('club.whatsapp.notes', $chat), ['body' => 'العميلة تفضل الاتصال مساءً'])->assertOk();
        $this->actingAs($owner)->postJson(route('club.whatsapp.status', $chat), ['status' => Conversation::CLOSED])->assertOk();

        $this->asTenant($club, function () use ($chat) {
            $this->assertSame(0, Message::where('conversation_id', $chat->id)->where('direction', 'outbound')->count(), 'a note is never a message');
            $this->assertSame('العميلة تفضل الاتصال مساءً', ConversationEvent::where('type', ConversationEvent::NOTE)->value('body'));
            $this->assertSame(Conversation::CLOSED, $chat->fresh()->status);
            $this->assertNotNull($chat->fresh()->closed_at);
        });
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.note_added', 'subject_id' => $chat->id]);
        $this->assertStringNotContainsString('مساءً', (string) \DB::table('audit_logs')->where('event', 'whatsapp.note_added')->value('properties'), 'the log says a note was added, not what it says');

        $thread = $this->actingAs($owner)->getJson(route('club.whatsapp.show', $chat))->assertOk();
        $this->assertSame(['note', 'status'], collect($thread->json('events'))->pluck('type')->all());

        $this->actingAs($owner)->postJson(route('club.whatsapp.status', $chat), ['status' => Conversation::OPEN])->assertOk();
        $this->asTenant($club, fn () => $this->assertNull($chat->fresh()->closed_at));
        $this->actingAs($owner)->postJson(route('club.whatsapp.status', $chat), ['status' => 'deleted'])->assertStatus(422);
    }

    public function test_the_member_card_shows_only_what_the_viewer_may_see(): void
    {
        $club = $this->club();
        $this->connect($club);
        $member = $this->member($club, ['full_name' => 'Noor Al Hinai', 'phone' => '96891234567']);
        $this->activeMembership($club, $member);
        $chat = $this->chat($club, '96891234567', ['member_id' => $member->id]);

        $chatOnly = $this->role($club, ['whatsapp.view_all', 'whatsapp.reply']);
        $this->assertSame(['restricted' => true], $this->actingAs($chatOnly)->getJson(route('club.whatsapp.show', $chat))->json('member'));

        $names = $this->role($club, ['whatsapp.view_all', 'members.view']);
        $card = $this->actingAs($names)->getJson(route('club.whatsapp.show', $chat))->json('member');
        $this->assertSame('Noor Al Hinai', $card['name']);
        $this->assertArrayNotHasKey('membership', $card);
        $this->assertArrayNotHasKey('owed', $card);

        $all = $this->actingAs($this->owner($club))->getJson(route('club.whatsapp.show', $chat))->json('member');
        $this->assertSame('active', $all['membership']['status']);
        $this->assertArrayHasKey('starts_on', $all['membership']);
        $this->assertArrayHasKey('owed', $all);
        $this->assertSame(route('club.members.show', $member), $all['url']);
    }

    public function test_an_unknown_number_stays_a_new_customer_until_staff_link_it(): void
    {
        $club = $this->club();
        $this->connect($club);
        // Two members share one phone (a mother and her daughter): no guess is made.
        $mother = $this->member($club, ['full_name' => 'Mother', 'phone' => '96899990000']);
        $this->member($club, ['full_name' => 'Daughter', 'phone' => '96899990001']);
        $this->asTenant($club, fn () => Member::where('full_name', 'Daughter')->update(['phone' => '99990000']));

        $payload = ['entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => $this->asTenant($club, fn () => TenantIntegration::first()->public_identifier)],
            'messages' => [['from' => '96899990000', 'id' => 'wamid.U1', 'type' => 'text', 'text' => ['body' => 'hi']]]]]]]]];
        config(['services.whatsapp.app_secret' => 'app-secret']);
        $body = json_encode($payload);
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();

        $chat = $this->asTenant($club, fn () => Conversation::sole());
        $this->assertNull($chat->member_id);
        $this->asTenant($club, fn () => $this->assertSame(2, Member::count(), 'no member is created'));
        $owner = $this->owner($club);
        $this->assertSame(2, $this->actingAs($owner)->getJson(route('club.whatsapp.show', $chat))->json('members_sharing'));

        $this->actingAs($owner)->postJson(route('club.whatsapp.link', $chat), ['member_id' => $mother->id])->assertOk();
        $this->asTenant($club, function () use ($chat, $mother) {
            $this->assertSame($mother->id, $chat->fresh()->member_id);
            $this->assertSame([$mother->id], Message::where('conversation_id', $chat->id)->pluck('member_id')->unique()->values()->all());
        });

        // Linking needs whatsapp.assign and members.view together.
        $assignOnly = $this->role($club, ['whatsapp.view_all', 'whatsapp.assign']);
        $this->actingAs($assignOnly)->postJson(route('club.whatsapp.link', $chat), ['member_id' => null])->assertForbidden();
    }

    public function test_one_club_never_reaches_anothers_conversations_or_files(): void
    {
        $a = $this->club();
        $b = $this->club();
        $chat = $this->chat($a, '96891000005');
        $file = $this->asTenant($a, fn () => Message::where('conversation_id', $chat->id)->first()->attachments()->create(['kind' => 'document', 'mime' => 'application/pdf', 'path' => 'whatsapp/a.pdf', 'status' => Attachment::STORED]));
        Storage::disk('local')->put('whatsapp/a.pdf', 'PDF');
        $ownerB = $this->owner($b);

        $this->actingAs($ownerB)->getJson(route('club.whatsapp.show', $chat->id))->assertNotFound();
        $this->actingAs($ownerB)->postJson(route('club.whatsapp.reply', $chat->id), ['body' => 'x'])->assertNotFound();
        $this->actingAs($ownerB)->get(route('club.whatsapp.attachments.show', $file->id))->assertNotFound();
        $this->assertSame([], $this->actingAs($ownerB)->getJson(route('club.whatsapp.feed'))->json('conversations'));

        // The owner of the club reaches the file, from the private disk, never cached.
        $this->actingAs($this->owner($a))->get(route('club.whatsapp.attachments.show', $file->id))->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Disposition', "attachment; filename*=UTF-8''document-".$file->id.'.pdf');
    }

    public function test_files_are_sent_within_the_window_and_only_of_the_kinds_whatsapp_takes(): void
    {
        $club = $this->club();
        $this->connect($club);
        $owner = $this->owner($club);
        $chat = $this->chat($club, '96891000006');

        $this->actingAs($owner)->postJson(route('club.whatsapp.media', $chat), ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])->assertStatus(422);
        $this->actingAs($owner)->postJson(route('club.whatsapp.media', $chat), ['file' => UploadedFile::fake()->create('big.jpg', 6 * 1024, 'image/jpeg')])->assertStatus(422);

        $sent = $this->actingAs($owner)->postJson(route('club.whatsapp.media', $chat), ['file' => UploadedFile::fake()->create('جدول.pdf', 50, 'application/pdf'), 'caption' => 'جدول الحصص'])->assertOk();
        $this->assertSame('document', $sent->json('message.attachments.0.kind'));
        $this->asTenant($club, function () use ($chat) {
            $attachment = Attachment::sole();
            Storage::disk('local')->assertExists($attachment->path);
            $this->assertStringStartsNotWith('public', $attachment->path);
            $this->assertSame(Message::SENT, $attachment->message->status, 'sent through the development driver');
            $this->assertStringStartsWith('log.media.', (string) $attachment->provider_media_id);
            $this->assertSame('جدول الحصص', $chat->fresh()->last_preview);
        });

        // Outside the 24-hour window: refused, said so, nothing sent.
        $this->asTenant($club, fn () => $chat->forceFill(['last_inbound_at' => now()->subDays(2)])->save());
        $this->actingAs($owner)->postJson(route('club.whatsapp.media', $chat), ['file' => UploadedFile::fake()->image('a.jpg')])->assertStatus(422)->assertJsonPath('ok', false);
    }

    public function test_search_filters_and_unread_counts(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $reception = $this->staff($club, SystemRoles::RECEPTION);
        $sara = $this->chat($club, '96891111111', ['contact_name' => 'سارة', 'unread_count' => 2]);
        $huda = $this->chat($club, '96892222222', ['contact_name' => 'هدى', 'status' => Conversation::PENDING, 'assigned_to' => $reception->id, 'unread_count' => 1]);
        $this->chat($club, '96893333333', ['contact_name' => 'ريم', 'status' => Conversation::CLOSED]);

        $ids = fn (array $query) => collect($this->actingAs($owner)->getJson(route('club.whatsapp.feed', $query))->json('conversations'))->pluck('id')->all();
        $this->assertSame([$sara->id], $ids(['q' => 'سارة']));
        $this->assertSame([$huda->id], $ids(['q' => '9222 2222']));
        $this->assertSame([$huda->id], $ids(['q' => '٩٢٢٢٢٢٢٢']));
        $this->assertSame([$huda->id], $ids(['status' => 'pending']));
        $this->assertSame([$huda->id], $ids(['assignee' => (string) $reception->id]));
        $this->assertCount(2, $ids(['assignee' => 'none']));
        $this->assertSame(3, $this->actingAs($owner)->getJson(route('club.whatsapp.feed'))->json('unread'));
        $this->assertSame(1, $this->actingAs($reception)->getJson(route('club.whatsapp.feed'))->json('unread'), 'only the chats it sees');

        // Opening a chat reads it.
        $this->actingAs($owner)->getJson(route('club.whatsapp.show', $sara))->assertOk();
        $this->assertSame(1, $this->actingAs($owner)->getJson(route('club.whatsapp.feed'))->json('unread'));
    }

    public function test_the_inbox_page_opens_with_its_staff_list_for_whoever_sees_every_chat(): void
    {
        $club = $this->club();
        $this->connect($club);
        $reception = $this->staff($club, SystemRoles::RECEPTION, ['name' => 'Salma']);
        $chat = $this->chat($club, '96891000011', ['assigned_to' => $reception->id]);

        // Each staff member's role is loaded with the list (lazy loading is refused outside production).
        $page = $this->actingAs($this->owner($club))->get(route('club.whatsapp.index', ['c' => $chat->id]))->assertOk()->viewData('page')['props'];
        $this->assertContains('Salma', collect($page['assignees'])->pluck('name')->all());
        $this->assertSame($chat->id, $page['active']['id']);
        $this->assertTrue($page['connection']['demo']);
    }

    public function test_the_old_inbox_addresses_lead_to_the_new_one(): void
    {
        $club = $this->club();
        $chat = $this->chat($club, '96891000007');

        $this->actingAs($this->owner($club))->get(route('club.inbox.show', $chat))->assertRedirect(route('club.whatsapp.index', ['c' => $chat->id]));
    }

    public function test_the_reports_count_conversations_messages_first_answers_and_automatic_notices(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->asTenant($club, function () use ($owner) {
            $c = Conversation::create(['phone' => '96891000008', 'status' => Conversation::OPEN, 'assigned_to' => $owner->id,
                'first_inbound_at' => now()->subMinutes(30), 'first_response_at' => now()->subMinutes(20)]);
            Conversation::create(['phone' => '96891000009', 'status' => Conversation::CLOSED, 'closed_at' => now()]);
            Conversation::create(['phone' => '96891000010', 'status' => Conversation::NEW, 'is_demo' => true]);
            Message::create(['conversation_id' => $c->id, 'direction' => 'inbound', 'source' => 'customer', 'type' => 'text', 'body' => 'a', 'status' => 'received']);
            Message::create(['conversation_id' => $c->id, 'direction' => 'outbound', 'source' => 'platform', 'type' => 'text', 'body' => 'b', 'status' => 'read', 'sent_by' => $owner->id]);
            Message::create(['conversation_id' => $c->id, 'direction' => 'outbound', 'source' => 'business_app', 'type' => 'text', 'body' => 'c', 'status' => 'sent']);
            Message::create(['direction' => 'outbound', 'source' => 'automation', 'type' => 'template', 'template_key' => 'member_welcome', 'status' => 'delivered']);
            Message::create(['direction' => 'outbound', 'source' => 'automation', 'type' => 'template', 'template_key' => 'membership_expiring', 'status' => 'failed']);
        });

        $figures = $this->actingAs($owner)->get(route('club.whatsapp.reports'))->assertOk()->viewData('page')['props']['figures'];
        $this->assertSame(2, $figures['conversations']['new'], 'the sample conversation is left out');
        $this->assertSame(1, $figures['conversations']['closed']);
        $this->assertSame([1, 2], [$figures['messages']['inbound'], $figures['messages']['outbound']]);
        $this->assertSame(600, $figures['first_response']['seconds']);
        $this->assertSame([1, 1], [$figures['automatic']['succeeded'], $figures['automatic']['failed']]);
        $this->assertSame([['id' => $owner->id, 'name' => $owner->name, 'open' => 1, 'replies' => 1]], json_decode(json_encode($figures['staff']), true));
    }

    public function test_the_old_permissions_become_the_new_ones_without_anyone_gaining_or_losing_by_surprise(): void
    {
        $this->assertSame(['whatsapp.send', 'whatsapp.view_assigned', 'whatsapp.reply'],
            WhatsAppPermissionUpgrade::upgrade(['whatsapp.inbox', 'whatsapp.send'], SystemRoles::RECEPTION));
        $this->assertSame(['members.view', 'whatsapp.view_all', 'whatsapp.reply', 'whatsapp.view_reports', 'whatsapp.manage_templates', 'whatsapp.manage_automation'],
            WhatsAppPermissionUpgrade::upgrade(['members.view', 'whatsapp.inbox', 'templates.manage'], 'custom_x'));
        $this->assertContains('whatsapp.manage_settings', WhatsAppPermissionUpgrade::upgrade(['integrations.manage'], 'custom_x'));
        $this->assertNotContains('whatsapp.manage_settings', WhatsAppPermissionUpgrade::upgrade(['whatsapp.inbox'], SystemRoles::MANAGER));
        $this->assertContains('whatsapp.assign', WhatsAppPermissionUpgrade::upgrade(['whatsapp.inbox'], SystemRoles::MANAGER));
        $this->assertSame(['*'], WhatsAppPermissionUpgrade::upgrade(['*'], SystemRoles::OWNER));
        $this->assertSame(['pos.sell'], WhatsAppPermissionUpgrade::upgrade(['pos.sell'], SystemRoles::CASHIER));

        // A new club's starting roles say the same.
        $club = $this->club();
        $reception = $this->staff($club, SystemRoles::RECEPTION);
        $manager = $this->staff($club, SystemRoles::MANAGER);
        $this->assertTrue($reception->can('whatsapp.view_assigned') && ! $reception->can('whatsapp.view_all') && ! $reception->can('whatsapp.assign'));
        $this->assertTrue($manager->can('whatsapp.view_all') && $manager->can('whatsapp.assign') && ! $manager->can('whatsapp.manage_settings'));
        $this->assertTrue($this->owner($club)->can('whatsapp.manage_settings'));
    }
}
