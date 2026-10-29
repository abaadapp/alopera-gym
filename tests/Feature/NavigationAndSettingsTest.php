<?php

namespace Tests\Feature;

use App\Core\Auth\PermissionRegistry;
use App\Models\Role;
use App\Modules\Inventory\Models\Product;
use App\Modules\Messaging\Models\AutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Sales & inventory switched off without touching data; the settings area; templates vs automation. */
class NavigationAndSettingsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_sales_are_on_while_suppliers_and_purchases_stay_off(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->asTenant($club, fn () => Product::create(['name' => 'Water', 'price' => 0.2]));

        // Sales (products, point of sale, website orders) have their own switch, on by default.
        $this->actingAs($owner)->get(route('club.pos.index'))->assertOk();
        $this->actingAs($owner)->get(route('club.products.index'))->assertOk();
        $this->assertContains('pos.sell', $owner->fresh()->permissions());
        // Suppliers and purchases stay off, and their permissions are not granted.
        $this->actingAs($owner)->get(route('club.purchases.index'))->assertNotFound();
        $this->actingAs($owner)->get(route('club.suppliers.index'))->assertNotFound();
        $this->assertNotContains('purchases.manage', $owner->fresh()->permissions());
        $this->actingAs($owner)->get(route('club.roles.index'))
            ->assertInertia(fn ($page) => $page->where('groups', fn ($groups) => collect($groups)->pluck('key')->contains('sales') && collect($groups)->pluck('key')->doesntContain('inventory')));
        $this->actingAs($owner)->get(route('club.dashboard'))->assertInertia(fn ($page) => $page->where('modules.sales', true)->where('modules.inventory', false));

        // Switching sales off hides their screens and the sales report; the data stays.
        config(['gym.modules.sales' => false]);
        $this->actingAs($owner->fresh())->get(route('club.pos.index'))->assertNotFound();
        $this->actingAs($owner->fresh())->get(route('club.products.index'))->assertNotFound();
        $this->actingAs($owner->fresh())->get(route('club.reports.export', ['report' => 'sales']))->assertNotFound();
        $this->assertNotContains('pos.sell', $owner->fresh()->permissions());
        $this->assertSame(1, $this->asTenant($club, fn () => Product::count()));

        config(['gym.modules.inventory' => true]);
        $this->actingAs($owner->fresh())->get(route('club.purchases.index'))->assertOk();
    }

    public function test_editing_a_role_keeps_the_permissions_of_a_switched_off_module(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_shop', 'name' => 'Shop', 'permissions' => ['members.view', 'purchases.manage'], 'is_system' => false]));

        $this->actingAs($owner)->put(route('club.roles.update', $role->id), ['name' => 'Shop', 'permissions' => ['members.view', 'classes.view']])->assertSessionHasNoErrors();

        $role->refresh();
        $this->assertEqualsCanonicalizing(['members.view', 'classes.view', 'purchases.manage'], $role->permissions);
        $this->assertNotContains('purchases.manage', $role->expandedPermissions());
        config(['gym.modules.inventory' => true]);
        $this->assertContains('purchases.manage', $role->expandedPermissions());
        $this->assertContains('purchases.manage', PermissionRegistry::all());
    }

    public function test_notification_rules_have_their_own_screen_and_club_settings_leave_them_alone(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        $this->actingAs($owner)->put(route('club.settings.notifications.update'), [
            'expiry_reminder_days' => [10, 2], 'expired_followup_days' => [5], 'notify_class_changes' => false,
        ])->assertSessionHasNoErrors();

        $this->actingAs($owner)->put(route('club.settings.update'), [
            'name' => 'Renamed', 'primary_color' => '#9d174d', 'secondary_color' => '#f9a8d4',
            'rules' => ['booking_window_days' => 10, 'expiry_reminder_days' => [1]],
        ])->assertSessionHasNoErrors();

        $club->refresh();
        $this->assertSame('Renamed', $club->name);
        $this->assertSame(10, $club->setting('booking_window_days'));
        $this->assertSame([10, 2], $club->setting('expiry_reminder_days'));
        $this->assertSame([5], $club->setting('expired_followup_days'));
        $this->assertFalse($club->setting('notify_class_changes'));
    }

    public function test_templates_and_automation_are_separate_screens(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->asTenant($club, fn () => AutoReply::create(['keyword' => 'الأسعار', 'match_type' => 'contains', 'reply' => 'رابط الباقات', 'is_active' => true]));

        $this->actingAs($owner)->get(route('club.templates.index'))
            ->assertInertia(fn ($page) => $page->component('Messaging/Templates')->has('templates')->missing('autoReplies'));
        $this->actingAs($owner)->get(route('club.automation.index'))
            ->assertInertia(fn ($page) => $page->component('Messaging/Automation')->has('autoReplies', 1)->has('rules.expiry_reminder_days'));
    }

    public function test_settings_hub_renders_for_the_owner(): void
    {
        $club = $this->club();

        $this->actingAs($this->owner($club))->get(route('club.settings.index'))
            ->assertOk()->assertInertia(fn ($page) => $page->component('Club/Settings/Index'));
    }

    public function test_old_settings_addresses_redirect_to_the_settings_area(): void
    {
        $owner = $this->owner($this->club());

        $this->actingAs($owner)->get('/app/users')->assertRedirect('/app/settings/users');
        $this->actingAs($owner)->get('/app/billing')->assertRedirect('/app/settings/billing');
    }
}
