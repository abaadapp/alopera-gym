<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Gym\Access\AccessDecision;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * دخول الأعضاء: entries allowed, refused (and why) and repeated, the members who came, by day, hour and way in. The
 * reception desk and the gate keep recording them; the live log stays in its section.
 */
final class CheckinsReport extends Report
{
    public const METHODS = ['manual' => 'يدوي (الاستقبال)', 'qr' => 'رمز QR', 'card' => 'بطاقة', 'fingerprint' => 'بصمة', 'barcode' => 'باركود', 'pin' => 'رمز سري'];

    public const RESULTS = ['allowed' => 'مسموح', 'duplicate' => 'مكرر', 'denied' => 'مرفوض'];

    private const FIGURES = [
        'allowed' => ['مرات الدخول المسموحة', S::INT], 'members' => ['أعضاء مختلفون دخلوا', S::INT], 'denied' => ['محاولات مرفوضة', S::INT], 'duplicate' => ['مكررة لم تُحتسب', S::INT],
    ];

    public function page(ReportFilters $f, User $user): array
    {
        $comparison = $this->compare($this->headline($f, $f->from, $f->to), $this->headline($f, $f->prevFrom, $f->prevTo));
        $result = $f->choice('result', array_keys(self::RESULTS));

        return [
            'comparison' => $comparison,
            'days' => $this->days($f),
            'hours' => $this->hours($f),
            'methods' => $this->methods($f),
            'reasons' => $this->reasons($f),
            'records' => $this->records($f, $result)->with(['member:id,full_name,member_no', 'device:id,name', 'performer:id,name'])->paginate(50)->withQueryString()
                ->through(fn (CheckIn $c) => $c->only('id', 'method', 'direction', 'result', 'credential_hint') + [
                    'reason' => AccessDecision::REASONS[$c->reason] ?? $c->reason,
                    'member' => $c->member?->only('id', 'full_name', 'member_no'),
                    'by' => $c->device?->name ?? $c->performer?->name, 'at' => $c->checked_in_at->format('Y-m-d H:i'),
                ]),
            'options' => ['result' => $result ?: null],
            'empty' => collect($comparison)->every(fn ($m) => (int) $m['current'] === 0),
        ];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $comparison = $this->compare($this->headline($f, $f->from, $f->to), $this->headline($f, $f->prevFrom, $f->prevTo));
        $result = $f->choice('result', array_keys(self::RESULTS));

        return [
            'empty' => collect($comparison)->every(fn ($m) => (int) $m['current'] === 0),
            'filters' => [['النتيجة في السجل', self::RESULTS[$result] ?? 'الكل']],
            'sheets' => [
                S::summary($comparison, self::FIGURES),
                new S('حسب اليوم', [['التاريخ', S::TEXT], ['مسموح', S::INT], ['مرفوض', S::INT], ['مكرر', S::INT]], array_map(fn ($d) => [$d['date'], $d['allowed'], $d['denied'], $d['duplicate']], $this->days($f))),
                new S('حسب الساعة', [['الساعة', S::TEXT], ['مرات الدخول', S::INT]], array_map(fn ($h) => [$h['hour'], $h['count']], $this->hours($f))),
                new S('حسب الطريقة', [['الطريقة', S::TEXT], ['مرات الدخول', S::INT]], array_map(fn ($m) => [$m['label'], $m['count']], $this->methods($f))),
                new S('أسباب الرفض', [['السبب', S::TEXT], ['العدد', S::INT]], array_map(fn ($r) => [$r['label'], $r['count']], $this->reasons($f))),
                new S('السجل', [['الوقت', S::TEXT], ['رقم العضوية', S::TEXT], ['العضو', S::TEXT], ['الطريقة', S::TEXT], ['الاتجاه', S::TEXT], ['الجهاز / الموظف', S::TEXT], ['النتيجة', S::TEXT], ['السبب', S::TEXT]],
                    (function () use ($f, $result) {
                        foreach ($this->records($f, $result)->with(['member:id,full_name,member_no', 'device:id,name', 'performer:id,name'])->lazy(500) as $c) {
                            yield [$c->checked_in_at->format('Y-m-d H:i:s'), $c->member?->member_no, $c->member?->full_name ?? 'غير معروف', self::METHODS[$c->method] ?? $c->method,
                                $c->direction === 'out' ? 'خروج' : 'دخول', $c->device?->name ?? $c->performer?->name, self::RESULTS[$c->result] ?? $c->result,
                                $c->result === 'denied' ? (AccessDecision::REASONS[$c->reason] ?? $c->reason) : ''];
                        }
                    })()),
            ],
        ];
    }

    private function base(ReportFilters $f, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Builder
    {
        return $this->in(CheckIn::query(), $f)->whereBetween('checked_in_at', [$from ?? $f->from, ($to ?? $f->to)->endOfDay()]);
    }

    private function records(ReportFilters $f, string $result): Builder
    {
        return $this->base($f)->when($result, fn ($q) => $q->where('result', $result))->orderByDesc('checked_in_at')->orderByDesc('id');
    }

    /** @return array<string, int> */
    private function headline(ReportFilters $f, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = $this->base($f, $from, $to)->selectRaw('result, count(*) as n')->groupBy('result')->pluck('n', 'result');

        return [
            'allowed' => (int) ($counts['allowed'] ?? 0),
            'members' => (int) $this->base($f, $from, $to)->where('result', 'allowed')->whereNotNull('member_id')->distinct()->count('member_id'),
            'denied' => (int) ($counts['denied'] ?? 0),
            'duplicate' => (int) ($counts['duplicate'] ?? 0),
        ];
    }

    private function days(ReportFilters $f): array
    {
        $rows = $this->base($f)->toBase()->selectRaw('date(checked_in_at) as d, result, count(*) as n')->groupBy('d', 'result')->get()->groupBy('d');
        $out = [];
        for ($d = $f->from; $d->lte($f->to); $d = $d->addDay()) {
            $day = $rows->get($d->toDateString(), collect())->pluck('n', 'result');
            $out[] = ['date' => $d->toDateString(), 'allowed' => (int) ($day['allowed'] ?? 0), 'denied' => (int) ($day['denied'] ?? 0), 'duplicate' => (int) ($day['duplicate'] ?? 0)];
        }

        return $out;
    }

    private function hours(ReportFilters $f): array
    {
        return $this->base($f)->where('result', 'allowed')->toBase()->selectRaw('extract(hour from checked_in_at)::int as h, count(*) as n')->groupBy('h')->orderBy('h')->get()
            ->map(fn ($r) => ['hour' => str_pad((string) $r->h, 2, '0', STR_PAD_LEFT).':00', 'count' => (int) $r->n])->all();
    }

    private function methods(ReportFilters $f): array
    {
        return $this->base($f)->where('result', 'allowed')->toBase()->select('method', DB::raw('count(*) as n'))->groupBy('method')->orderByDesc('n')->get()
            ->map(fn ($r) => ['label' => self::METHODS[$r->method] ?? $r->method, 'count' => (int) $r->n])->all();
    }

    private function reasons(ReportFilters $f): array
    {
        return $this->base($f)->where('result', 'denied')->toBase()->select('reason', DB::raw('count(*) as n'))->groupBy('reason')->orderByDesc('n')->get()
            ->map(fn ($r) => ['label' => AccessDecision::REASONS[$r->reason] ?? ($r->reason ?: 'غير محدد'), 'count' => (int) $r->n])->all();
    }
}
