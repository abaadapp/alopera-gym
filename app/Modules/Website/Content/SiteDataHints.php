<?php

namespace App\Modules\Website\Content;

use App\Core\Auth\PermissionRegistry;
use App\Core\Tenancy\TenantContext;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\Program;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\ShopSettings;
use App\Modules\Website\Models\SitePost;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Why a section fed by the gym system is not on the site, in the owner's terms: nothing has been added yet, or rows
 * exist but a switch keeps them off the site (and which switch, on which screen). «إدارة الموقع» used to say
 * «add data» in both cases, which sent an owner with hidden plans looking for something that was already there.
 * Reads only; the rules of what shows stay in SiteRenderer.
 */
final class SiteDataHints
{
    public function __construct(private TenantContext $tenants) {}

    /**
     * @param  array<string, string>  $waiting  section id => the renderer's reason
     * @param  array<string, mixed>  $doc
     * @return array<string, string> the same sections, each with the most precise reason known
     */
    public function explain(array $waiting, array $doc): array
    {
        $sections = collect($doc['sections'] ?? [])->keyBy('id');

        foreach ($waiting as $id => $reason) {
            $section = (array) ($sections[$id] ?? []);
            $waiting[$id] = $this->reason((string) ($section['type'] ?? ''), (array) ($section['data'] ?? [])) ?? $reason;
        }

        return $waiting;
    }

    /** @param array<string, mixed> $data */
    private function reason(string $type, array $data): ?string
    {
        return match ($type) {
            'programs' => $this->rows(Program::query(), 'برامج', 'البرامج', [['is_public', 'يظهر في الموقع'], ['is_active', 'نشط']]),
            'trainers' => $this->rows(Trainer::query(), 'مدربات', 'فريق التدريب', [['is_public', 'يظهر في الموقع'], ['is_active', 'نشط']]),
            'plans' => $this->plans(),
            'join' => $this->join(),
            'products' => $this->products(),
            'posts' => $this->posts(),
            'schedule' => $this->schedule(max(1, min(14, (int) ($data['days'] ?? 7)))),
            default => null,
        };
    }

    private function plans(): ?string
    {
        return $this->rows(MembershipPlan::query(), 'باقات عضوية', 'الباقات والأسعار', [['is_public', 'تظهر في الموقع'], ['is_active', 'نشطة للبيع']]);
    }

    private function join(): ?string
    {
        if (! $this->tenants->require()->setting('member_signup_enabled', true)) {
            return 'التسجيل الإلكتروني متوقف: فعّل «السماح بالتسجيل من الموقع» من «الإعدادات» ← «إعدادات النادي».';
        }

        return $this->plans();
    }

    private function products(): ?string
    {
        if (! PermissionRegistry::moduleEnabled('sales')) {
            return null;
        }
        if (Product::onWebsite()->exists() && ! ShopSettings::of($this->tenants->require())['shop_enabled']) {
            return 'المنتجات المختارة للموقع جاهزة، لكن المتجر لا يظهر في الموقع: فعّل «إظهار المتجر في موقع النادي» من «الإعدادات» ← «إعدادات المبيعات والمتجر».';
        }

        return $this->rows(Product::query(), 'منتجات', 'المنتجات', [['show_on_website', 'عرضه في متجر الموقع'], ['is_active', 'متاح للبيع']]);
    }

    private function posts(): ?string
    {
        $total = SitePost::count();
        if ($total === 0) {
            return 'لا توجد أخبار أو عروض في النظام بعد: أضفها من «الأخبار والعروض».';
        }

        $why = array_filter([
            $this->count(SitePost::where('is_published', false), 'غير مفعّل فيها «منشور في الموقع»'),
            $this->count(SitePost::where('is_published', true)->where('published_at', '>', now()), 'لم يحن موعد نشرها'),
            $this->count(SitePost::where('is_published', true)->where('ends_on', '<', today()), 'انتهت مدة عرضها'),
        ]);

        return 'الأخبار والعروض في النظام: '.$total.'، ولا يظهر أيٌّ منها الآن'.($why ? ' — '.implode('، و', $why) : '').'. راجعها من «الأخبار والعروض».';
    }

    private function schedule(int $days): string
    {
        $start = CarbonImmutable::today();
        $upcoming = fn () => ClassSession::where('status', ClassSession::SCHEDULED)->where('starts_at', '>=', now())->between($start, $start->addDays($days));
        $hidden = $upcoming()->whereHas('program', fn ($q) => $q->where('is_public', false))->count();

        return $hidden > 0
            ? 'في الأيام القادمة '.$hidden.' حصة مجدولة لبرامج لا تظهر في الموقع: فعّل «يظهر في الموقع» لبرامجها من «البرامج».'
            : 'لا توجد حصص مجدولة في الأيام القادمة: أضفها من «الجدول الأسبوعي» أو «الحصص والحجوزات».';
    }

    /**
     * Rows of one kind: none yet, or how many exist and which switch keeps them off the site.
     *
     * @param  list<array{0: string, 1: string}>  $switches  column => its label on the screen
     */
    private function rows(Builder $query, string $noun, string $screen, array $switches): string
    {
        $total = (clone $query)->count();
        if ($total === 0) {
            return 'لا توجد '.$noun.' في النظام بعد: أضفها من «'.$screen.'».';
        }

        $why = array_filter(array_map(fn (array $s) => $this->count((clone $query)->where($s[0], false), 'غير مفعّل فيها «'.$s[1].'»'), $switches));

        return $noun.' في النظام: '.$total.'، ولا يظهر أيٌّ منها في الموقع'.($why ? ' — '.implode('، و', $why) : '').'. فعّلها من «'.$screen.'».';
    }

    private function count(Builder $query, string $label): ?string
    {
        $n = $query->count();

        return $n > 0 ? $n.' '.$label : null;
    }
}
