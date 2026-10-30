<?php

namespace App\Modules\Reports\Support;

use App\Core\Tenancy\BranchContext;
use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * What a report is asked for, read from the address so a report can be shared as a link: the period (a preset or
 * two dates, at most two years), the period it is compared with, the branch, and the report's own filters.
 *
 * The branch: a user tied to a branch always gets theirs, whatever the address says. A club-wide user gets the
 * branch in the address («all» for the whole club), else the one the top bar is showing.
 */
final class ReportFilters
{
    public const PRESETS = ['today', 'week', 'month', 'last_month', 'quarter', 'year'];

    /** @param array<string, mixed> $query */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly CarbonImmutable $prevFrom,
        public readonly CarbonImmutable $prevTo,
        public readonly string $preset,
        public readonly string $compare,
        public readonly ?int $branch,
        private readonly array $query = [],
    ) {}

    public static function fromRequest(Request $request, User $user, string $defaultPreset = 'month'): self
    {
        $today = CarbonImmutable::today();
        $asked = $request->string('preset')->toString();
        $dated = $request->filled('from') || $request->filled('to');
        $preset = in_array($asked, self::PRESETS, true) ? $asked : ($dated ? 'custom' : $defaultPreset);

        [$from, $to] = match ($preset) {
            'today' => [$today, $today],
            'week' => [$today->startOfWeek(CarbonImmutable::SATURDAY), $today],
            'month' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'quarter' => [$today->firstOfQuarter(), $today],
            'year' => [$today->startOfYear(), $today],
            // a malformed date in the address falls back to the default instead of failing
            default => [
                rescue(fn () => CarbonImmutable::parse($request->string('from')->toString() ?: $today->startOfMonth()->toDateString())->startOfDay(), $today->startOfMonth(), false),
                rescue(fn () => CarbonImmutable::parse($request->string('to')->toString() ?: $today->toDateString())->startOfDay(), $today, false),
            ],
        };
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        // keep a report to at most two years per query
        $from = $from->max($to->subYears(2));

        [$prevFrom, $prevTo, $compare] = self::comparison($request, $from, $to);

        return new self($from, $to, $prevFrom, $prevTo, $preset, $compare, self::branch($request, $user), $request->query());
    }

    /**
     * The period every figure is compared with. Default: the same number of days just before.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private static function comparison(Request $request, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $previous = [$from->subDays($days), $from->subDay(), 'previous'];

        return match ($request->string('compare')->toString()) {
            'month' => [$from->subMonthNoOverflow(), $to->subMonthNoOverflow(), 'month'],
            'year' => [$from->subYearNoOverflow(), $to->subYearNoOverflow(), 'year'],
            'custom' => rescue(function () use ($request) {
                $a = CarbonImmutable::parse($request->string('compare_from')->toString())->startOfDay();
                $b = CarbonImmutable::parse($request->string('compare_to')->toString())->startOfDay();
                [$a, $b] = $a->gt($b) ? [$b, $a] : [$a, $b];

                return [$a->max($b->subYears(2)), $b, 'custom'];
            }, $previous, false),
            default => $previous,
        };
    }

    private static function branch(Request $request, User $user): ?int
    {
        if ($user->branch_id) {
            return (int) $user->branch_id;
        }
        $asked = $request->query('branch');
        if ($asked === 'all') {
            return null;
        }
        if (is_numeric($asked) && Branch::whereKey((int) $asked)->exists()) {
            return (int) $asked;
        }

        return app(BranchContext::class)->id($user);
    }

    /** One of $allowed from the address, else $default. */
    public function choice(string $key, array $allowed, string $default = ''): string
    {
        $value = $this->query[$key] ?? null;

        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    public function integer(string $key): ?int
    {
        $value = $this->query[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function boolean(string $key): bool
    {
        return filter_var($this->query[$key] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    public function branchName(): string
    {
        return $this->branch ? (Branch::whereKey($this->branch)->value('name') ?? '—') : 'كل الفروع';
    }

    /** The period and comparison as the page shows them. */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'preset' => $this->preset,
            'compare' => $this->compare, 'compare_from' => $this->prevFrom->toDateString(), 'compare_to' => $this->prevTo->toDateString(),
            'previous_from' => $this->prevFrom->toDateString(), 'previous_to' => $this->prevTo->toDateString(),
            'branch' => $this->branch,
        ];
    }
}
