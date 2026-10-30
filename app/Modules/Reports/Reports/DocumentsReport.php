<?php

namespace App\Modules\Reports\Reports;

use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * المستندات وصلاحيتها, as they stand today: the business's active documents, which are about to expire or have
 * expired, by type, and who answers for each. Only documents this user may see (confidential ones are the owner's
 * and their responsible's). Renewing and uploading stay in the documents section.
 */
final class DocumentsReport extends Report
{
    public const STATES = ['expiring' => 'قريبة الانتهاء', 'expired' => 'منتهية', 'none' => 'بلا تاريخ انتهاء'];

    public function __construct(private readonly TenantContext $tenants) {}

    public function page(ReportFilters $f, User $user): array
    {
        $soon = DocumentService::soonDays($this->tenants->require());
        $today = CarbonImmutable::today();

        return [
            'stats' => [
                'total' => $this->active($f, $user)->count(),
                'expiring' => $this->active($f, $user)->whereBetween('expiry_date', [$today, $today->addDays($soon)])->count(),
                'expired' => $this->active($f, $user)->where('expiry_date', '<', $today)->count(),
                'none' => $this->active($f, $user)->whereNull('expiry_date')->count(),
            ],
            'byType' => $this->byType($f, $user, $soon, $today),
            'documents' => $this->listed($f, $user, $soon, $today)->paginate(50)->withQueryString()->through(fn (BusinessDocument $d) => $this->row($d, $soon, $today)),
            'types' => DocumentType::orderBy('name')->get(['id', 'name']),
            'soon' => $soon,
            'options' => ['state' => $f->choice('state', array_keys(self::STATES)) ?: null, 'type' => $f->integer('type')],
            'empty' => ! $this->listed($f, $user, $soon, $today)->exists(),
        ];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $soon = DocumentService::soonDays($this->tenants->require());
        $today = CarbonImmutable::today();
        $type = $f->integer('type') ? DocumentType::whereKey($f->integer('type'))->value('name') : null;

        return [
            'empty' => ! $this->listed($f, $user, $soon, $today)->exists(),
            'filters' => [['الحالة', self::STATES[$f->choice('state', array_keys(self::STATES))] ?? 'كل المستندات السارية'], ['النوع', $type ?? 'كل الأنواع'], ['قريبة الانتهاء', "خلال {$soon} يومًا"]],
            'sheets' => [
                new S('حسب النوع', [['النوع', S::TEXT], ['المستندات', S::INT], ['قريبة الانتهاء', S::INT], ['منتهية', S::INT]],
                    array_map(fn ($t) => [$t['type'], $t['total'], $t['expiring'], $t['expired']], $this->byType($f, $user, $soon, $today))),
                new S('المستندات', [['المستند', S::TEXT], ['النوع', S::TEXT], ['الرقم المرجعي', S::TEXT], ['جهة الإصدار', S::TEXT], ['الفرع', S::TEXT], ['المسؤول', S::TEXT], ['تاريخ الإصدار', S::TEXT], ['تاريخ الانتهاء', S::TEXT], ['الأيام المتبقية', S::INT], ['الحالة', S::TEXT]],
                    (function () use ($f, $user, $soon, $today) {
                        foreach ($this->listed($f, $user, $soon, $today)->lazy(500) as $d) {
                            $r = $this->row($d, $soon, $today);
                            yield [$r['name'], $r['type'], $r['reference_number'], $r['issuer'], $r['branch'], $r['responsible'], $r['issue_date'], $r['expiry_date'], $r['days_left'], $r['state_label']];
                        }
                    })()),
            ],
        ];
    }

    private function active(ReportFilters $f, User $user): Builder
    {
        return $this->in(BusinessDocument::visibleTo($user), $f)->where('status', 'active');
    }

    private function listed(ReportFilters $f, User $user, int $soon, CarbonImmutable $today): Builder
    {
        return $this->active($f, $user)->with(['type:id,name', 'branch:id,name', 'responsible:id,name'])
            ->when($f->choice('state', array_keys(self::STATES)), fn ($q, $state) => match ($state) {
                'expiring' => $q->whereBetween('expiry_date', [$today, $today->addDays($soon)]),
                'expired' => $q->where('expiry_date', '<', $today),
                default => $q->whereNull('expiry_date'),
            })
            ->when($f->integer('type'), fn ($q, $type) => $q->where('document_type_id', $type))
            ->orderByRaw('expiry_date is null')->orderBy('expiry_date')->orderBy('name')->orderBy('id');
    }

    private function byType(ReportFilters $f, User $user, int $soon, CarbonImmutable $today): array
    {
        $names = DocumentType::pluck('name', 'id');

        return $this->active($f, $user)->toBase()
            ->selectRaw('document_type_id, count(*) as total, sum(case when expiry_date between ? and ? then 1 else 0 end) as expiring, sum(case when expiry_date < ? then 1 else 0 end) as expired',
                [$today->toDateString(), $today->addDays($soon)->toDateString(), $today->toDateString()])
            ->groupBy('document_type_id')->orderByDesc('total')->get()
            ->map(fn ($r) => ['type' => $names[$r->document_type_id] ?? 'بلا نوع', 'total' => (int) $r->total, 'expiring' => (int) $r->expiring, 'expired' => (int) $r->expired])->all();
    }

    private function row(BusinessDocument $d, int $soon, CarbonImmutable $today): array
    {
        $state = $d->state($soon, $today);

        return [
            'id' => $d->id, 'name' => $d->name, 'type' => $d->type?->name, 'reference_number' => $d->reference_number, 'issuer' => $d->issuer,
            'branch' => $d->branch?->name, 'responsible' => $d->responsible?->name, 'is_confidential' => $d->is_confidential,
            'issue_date' => $d->issue_date?->toDateString(), 'expiry_date' => $d->expiry_date?->toDateString(), 'days_left' => $d->daysLeft($today),
            'state' => $state, 'state_label' => BusinessDocument::STATE_LABELS[$state] ?? $state,
        ];
    }
}
