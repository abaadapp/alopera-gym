<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Documents\Models\DocumentRenewal;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * سجل تجديد المستندات: every renewal in the period — who did it and when, the expiry and reference before and after
 * — for the documents this user may see, by type, against the comparison period. The archived copies are downloaded
 * from the documents section.
 */
final class RenewalsReport extends Report
{
    private const FIGURES = ['renewals' => ['التجديدات', S::INT], 'documents' => ['مستندات جُدّدت', S::INT]];

    public function page(ReportFilters $f, User $user): array
    {
        $comparison = $this->compare($this->headline($f, $user, $f->from, $f->to), $this->headline($f, $user, $f->prevFrom, $f->prevTo));

        return [
            'comparison' => $comparison,
            'byType' => $this->byType($f, $user),
            'renewals' => $this->renewals($f, $user)->paginate(50)->withQueryString()->through(fn (DocumentRenewal $r) => $this->row($r)),
            'empty' => (int) $comparison['renewals']['current'] === 0,
        ];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $comparison = $this->compare($this->headline($f, $user, $f->from, $f->to), $this->headline($f, $user, $f->prevFrom, $f->prevTo));

        return [
            'empty' => (int) $comparison['renewals']['current'] === 0,
            'sheets' => [
                S::summary($comparison, self::FIGURES),
                new S('حسب النوع', [['النوع', S::TEXT], ['التجديدات', S::INT]], array_map(fn ($t) => [$t['type'], $t['count']], $this->byType($f, $user))),
                new S('التجديدات', [['المستند', S::TEXT], ['النوع', S::TEXT], ['الانتهاء السابق', S::TEXT], ['الانتهاء الجديد', S::TEXT], ['المرجع السابق', S::TEXT], ['المرجع الجديد', S::TEXT], ['نفّذه', S::TEXT], ['التاريخ', S::TEXT], ['ملاحظات', S::TEXT]],
                    (function () use ($f, $user) {
                        foreach ($this->renewals($f, $user)->lazy(500) as $r) {
                            $row = $this->row($r);
                            yield [$row['document'].($row['deleted'] ? ' (محذوف)' : ''), $row['type'], $row['previous_expiry'], $row['new_expiry'], $row['previous_reference'], $row['new_reference'], $row['by'], $row['at'], $row['notes']];
                        }
                    })()),
            ],
        ];
    }

    /** The documents whose renewals this user may see: visible to them, in the chosen branch, deleted ones included. */
    private function documents(ReportFilters $f, User $user): Builder
    {
        return $this->in(BusinessDocument::visibleTo($user)->withTrashed(), $f)->select('id');
    }

    private function base(ReportFilters $f, User $user, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DocumentRenewal::whereIn('gym_document_renewals.document_id', $this->documents($f, $user))
            ->whereBetween('gym_document_renewals.created_at', [$from->startOfDay(), $to->endOfDay()]);
    }

    private function renewals(ReportFilters $f, User $user): Builder
    {
        return $this->base($f, $user, $f->from, $f->to)->with(['document' => fn ($q) => $q->withTrashed()->select('id', 'name', 'document_type_id', 'deleted_at'), 'document.type:id,name', 'renewedBy:id,name'])
            ->latest('id');
    }

    private function headline(ReportFilters $f, User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['renewals' => $this->base($f, $user, $from, $to)->count(), 'documents' => $this->base($f, $user, $from, $to)->distinct()->count('document_id')];
    }

    private function byType(ReportFilters $f, User $user): array
    {
        $names = DocumentType::pluck('name', 'id');

        return $this->base($f, $user, $f->from, $f->to)->toBase()
            ->join('gym_documents as d', 'd.id', '=', 'gym_document_renewals.document_id')
            ->selectRaw('d.document_type_id, count(*) as n')->groupBy('d.document_type_id')->orderByDesc('n')->get()
            ->map(fn ($r) => ['type' => $names[$r->document_type_id] ?? 'بلا نوع', 'count' => (int) $r->n])->all();
    }

    private function row(DocumentRenewal $r): array
    {
        return [
            'id' => $r->id, 'document' => $r->document?->name ?? '—', 'deleted' => $r->document?->trashed() ?? false, 'type' => $r->document?->type?->name,
            'previous_expiry' => $r->previous_expiry_date?->toDateString(), 'new_expiry' => $r->new_expiry_date->toDateString(),
            'previous_reference' => $r->previous_reference, 'new_reference' => $r->new_reference,
            'notes' => $r->notes, 'by' => $r->renewedBy?->name, 'at' => $r->created_at->format('Y-m-d H:i'),
        ];
    }
}
