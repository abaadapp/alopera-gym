<?php

namespace App\Modules\Website\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SiteSetting extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['is_published' => true];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sections' => 'array',
            'working_hours' => 'array',
            'social_links' => 'array',
            'stats' => 'array',
            'features' => 'array',
        ];
    }

    /** The home page sections after the hero, in their default order, with what each shows. */
    public const SECTIONS = [
        'features' => 'لماذا نحن',
        'about' => 'التعريف بالنادي',
        'offers' => 'العروض',
        'plans' => 'العضويات والباقات',
        'programs' => 'البرامج',
        'trainers' => 'المدربات',
        'gallery' => 'معرض الصور',
        'news' => 'الأخبار',
        'cta' => 'دعوة للاشتراك والحجز',
    ];

    /**
     * The owner's order and visibility: [{key, visible}] for every section. Sections she has not placed yet (or
     * a new one) follow in the default order, shown.
     *
     * @return list<array{key: string, label: string, visible: bool}>
     */
    public function sectionLayout(): array
    {
        $stored = collect($this->sections ?? [])->filter(fn ($s) => isset(self::SECTIONS[$s['key'] ?? '']))->unique('key');
        $missing = collect(array_keys(self::SECTIONS))->diff($stored->pluck('key'))->map(fn ($key) => ['key' => $key, 'visible' => true]);

        return $stored->concat($missing)->map(fn ($s) => ['key' => $s['key'], 'label' => self::SECTIONS[$s['key']], 'visible' => (bool) ($s['visible'] ?? true)])->values()->all();
    }

    /** @return list<string> the visible sections, in order */
    public function visibleSections(): array
    {
        return collect($this->sectionLayout())->where('visible', true)->pluck('key')->all();
    }

    public static function url(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
