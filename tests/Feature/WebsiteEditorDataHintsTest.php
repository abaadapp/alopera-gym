<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Website\Models\SitePost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * «إدارة الموقع» tells the owner why a system section is not on the site: nothing added yet, or rows that exist but
 * are kept off by a switch — naming the switch and the screen. Before, both cases read «add data in the system».
 */
class WebsiteEditorDataHintsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array<string, string> section type => the editor's reason */
    private function reasons(Tenant $club): array
    {
        $props = $this->actingAs($this->owner($club))->get(route('club.website.edit'))->assertOk()->viewData('page')['props'];
        $types = collect($props['doc']['sections'])->pluck('type', 'id');

        return collect($props['waiting'])->mapWithKeys(fn ($reason, $id) => [$types[$id] => $reason])->all();
    }

    public function test_an_empty_club_is_told_to_add_data_and_where(): void
    {
        $reasons = $this->reasons($this->club());

        $this->assertSame('لا توجد باقات عضوية في النظام بعد: أضفها من «الباقات والأسعار».', $reasons['plans']);
        $this->assertSame('لا توجد برامج في النظام بعد: أضفها من «البرامج».', $reasons['programs']);
        $this->assertSame('لا توجد مدربات في النظام بعد: أضفها من «فريق التدريب».', $reasons['trainers']);
        $this->assertStringContainsString('أضفها من «الأخبار والعروض»', $reasons['posts']);
        $this->assertStringContainsString('لا توجد حصص مجدولة', $reasons['schedule']);
    }

    public function test_rows_kept_off_the_site_are_counted_with_the_switch_that_hides_them(): void
    {
        $club = $this->club();
        $this->plan($club, ['is_public' => false]);
        $this->plan($club, ['is_public' => false, 'name' => 'Yearly']);
        $this->plan($club, ['is_public' => true, 'is_active' => false, 'name' => 'Old']);
        $this->program($club, ['is_public' => false]);
        $this->asTenant($club, fn () => Trainer::create(['name' => 'Huda', 'is_public' => true, 'is_active' => false]));

        $reasons = $this->reasons($club);

        $this->assertSame('باقات عضوية في النظام: 3، ولا يظهر أيٌّ منها في الموقع — 2 غير مفعّل فيها «تظهر في الموقع»، و1 غير مفعّل فيها «نشطة للبيع». فعّلها من «الباقات والأسعار».', $reasons['plans']);
        $this->assertSame($reasons['plans'], $reasons['join'], 'the join section waits for the same plans');
        $this->assertStringContainsString('برامج في النظام: 1', $reasons['programs']);
        $this->assertStringContainsString('«يظهر في الموقع»', $reasons['programs']);
        $this->assertSame('مدربات في النظام: 1، ولا يظهر أيٌّ منها في الموقع — 1 غير مفعّل فيها «نشط». فعّلها من «فريق التدريب».', $reasons['trainers']);
    }

    public function test_sessions_of_a_hidden_program_point_at_the_program_switch(): void
    {
        $club = $this->club();
        $this->classSession($club, $this->program($club, ['is_public' => false]));

        $this->assertStringContainsString('1 حصة مجدولة لبرامج لا تظهر في الموقع', $this->reasons($club)['schedule']);
    }

    public function test_posts_say_whether_they_are_unpublished_not_yet_due_or_over(): void
    {
        $club = $this->club();
        $this->asTenant($club, function () {
            SitePost::create(['type' => 'news', 'title' => 'Draft', 'slug' => 'draft', 'is_published' => false]);
            SitePost::create(['type' => 'offer', 'title' => 'Later', 'slug' => 'later', 'is_published' => true, 'published_at' => now()->addWeek()]);
            SitePost::create(['type' => 'offer', 'title' => 'Over', 'slug' => 'over', 'is_published' => true, 'published_at' => now()->subMonth(), 'ends_on' => today()->subDay()]);
        });

        $reason = $this->reasons($club)['posts'];

        $this->assertStringContainsString('الأخبار والعروض في النظام: 3', $reason);
        $this->assertStringContainsString('1 غير مفعّل فيها «منشور في الموقع»', $reason);
        $this->assertStringContainsString('1 لم يحن موعد نشرها', $reason);
        $this->assertStringContainsString('1 انتهت مدة عرضها', $reason);
    }

    public function test_products_chosen_for_a_closed_shop_point_at_the_shop_switch(): void
    {
        $club = $this->club();
        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['sales' => ['shop_enabled' => false]])])->save();
        $this->asTenant($club, fn () => Product::create(['name' => 'Protein bar', 'price' => 1.5, 'show_on_website' => true]));

        $this->assertStringContainsString('فعّل «إظهار المتجر في موقع النادي»', $this->reasons($club)['products']);
    }

    public function test_products_not_chosen_for_the_site_point_at_the_product_switch(): void
    {
        $club = $this->club();
        $this->asTenant($club, fn () => Product::create(['name' => 'Protein bar', 'price' => 1.5, 'show_on_website' => false]));

        $this->assertSame('منتجات في النظام: 1، ولا يظهر أيٌّ منها في الموقع — 1 غير مفعّل فيها «عرضه في متجر الموقع». فعّلها من «المنتجات».', $this->reasons($club)['products']);
    }

    public function test_switched_off_signup_is_named_before_the_plans(): void
    {
        $club = $this->club();
        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['member_signup_enabled' => false])])->save();
        $this->plan($club, ['is_public' => true]);

        $reasons = $this->reasons($club);

        $this->assertArrayNotHasKey('plans', $reasons, 'the plan shows');
        $this->assertStringContainsString('«السماح بالتسجيل من الموقع»', $reasons['join']);
    }
}
