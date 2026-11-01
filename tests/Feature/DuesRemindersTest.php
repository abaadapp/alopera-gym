<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Jobs\SendWhatsAppMessage;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Payment reminders: off until the owner turns them on; through the club's WhatsApp only once connected, with approved
 * templates and the member's consent (otherwise recorded with the reason); each stage once per invoice, never more
 * than the club's maximum; by hand at most once a day, for those who may send messages.
 */
class DuesRemindersTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function connect(Tenant $club, bool $approved = true): void
    {
        $this->asTenant($club, function () use ($approved) {
            TenantIntegration::create(['provider' => 'whatsapp', 'is_enabled' => true, 'credentials' => ['phone_number_id' => 'PNID-9', 'access_token' => 'EAAG-secret'], 'public_identifier' => 'PNID-9']);
            TemplateCatalog::seedDefaults();
            if ($approved) {
                MessageTemplate::query()->update(['status' => 'approved']);
            }
        });
    }

    private function rules(Tenant $club, array $rules): void
    {
        $this->asTenant($club, fn () => $club->forceFill(['settings' => array_merge($club->fresh()->settings ?? [], $rules)])->save());
    }

    private function due(Tenant $club, Member $member, int $inDays): Invoice
    {
        return $this->asTenant($club, function () use ($member, $inDays) {
            $invoice = app(InvoiceService::class)->issue($member, [['description' => 'اشتراك', 'unit_price' => 30]], ['type' => 'other']);
            $invoice->forceFill(['due_date' => today()->addDays($inDays)])->save();

            return $invoice;
        });
    }

    private function sent(Tenant $club, string $status = Message::QUEUED): int
    {
        return $this->asTenant($club, fn () => Message::whereIn('template_key', ['invoice_due_soon', 'invoice_overdue'])->where('status', $status)->count());
    }

    public function test_reminders_go_once_per_stage_within_the_maximum_and_only_when_turned_on(): void
    {
        Queue::fake();
        $club = $this->club();
        $member = $this->member($club, ['whatsapp_opt_in' => true]);
        $soon = $this->due($club, $member, 3);
        $late = $this->due($club, $member, -1);
        $this->connect($club);

        // Off by default: nothing.
        Artisan::call('finance:remind-dues');
        $this->assertSame(0, $this->sent($club));

        $this->rules($club, ['dues_reminders_enabled' => true, 'dues_reminder_before_days' => [3], 'dues_reminder_overdue_days' => [1, 7], 'dues_reminder_max' => 3]);
        Artisan::call('finance:remind-dues');
        Artisan::call('finance:remind-dues');
        $this->asTenant($club, function () use ($soon, $late) {
            $this->assertSame(1, Message::where(['related_id' => $soon->id, 'template_key' => 'invoice_due_soon'])->count());
            $this->assertSame(1, Message::where(['related_id' => $late->id, 'template_key' => 'invoice_overdue'])->count());
            $this->assertStringContainsString($late->number, Message::where('related_id', $late->id)->value('body'));
        });
        Queue::assertPushed(SendWhatsAppMessage::class, 2);

        // Six days on, the late invoice reaches its 7-day stage — but the maximum (now 1) is already used.
        $this->rules($club, ['dues_reminder_max' => 1]);
        $this->travel(6)->days();
        Artisan::call('finance:remind-dues');
        $this->asTenant($club, fn () => $this->assertSame(1, Message::where('related_id', $late->id)->count()));
    }

    public function test_without_consent_or_an_approved_template_nothing_is_sent_and_the_reason_is_kept(): void
    {
        Queue::fake();
        $club = $this->club();
        $this->connect($club, approved: false);
        $this->rules($club, ['dues_reminders_enabled' => true, 'dues_reminder_before_days' => [3]]);
        $this->due($club, $this->member($club, ['whatsapp_opt_in' => true]), 3);
        $this->due($club, $this->member($club, ['whatsapp_opt_in' => false]), 3);

        Artisan::call('finance:remind-dues');
        $this->assertSame([0, 2], [$this->sent($club), $this->sent($club, Message::SKIPPED)]);
        $this->asTenant($club, fn () => $this->assertSame(['template_not_approved'], Message::pluck('error_code')->unique()->values()->all()));
        Queue::assertNothingPushed();
        $this->actingAs($this->owner($club))->get(route('club.dues'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('reminders.log.0.reason', 'القالب لم يُعتمد من Meta بعد'));
    }

    public function test_a_reminder_by_hand_goes_once_a_day_for_those_who_may_send(): void
    {
        Queue::fake();
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->due($club, $this->member($club, ['whatsapp_opt_in' => true]), -5);

        // Not connected: said so, nothing queued.
        $this->actingAs($owner)->post(route('club.dues.remind', $invoice))->assertSessionHas('error');
        $this->connect($club);
        $this->actingAs($owner)->post(route('club.dues.remind', $invoice))->assertSessionHas('success');
        $this->actingAs($owner)->post(route('club.dues.remind', $invoice))->assertSessionHas('error');
        $this->assertSame(1, $this->sent($club));

        $this->asTenant($club, fn () => Role::create(['name' => 'متابعة المستحقات', 'key' => 'dues_only', 'permissions' => ['dues.view']]));
        $this->actingAs($this->staff($club, 'dues_only'))->post(route('club.dues.remind', $invoice))->assertForbidden();
    }
}
