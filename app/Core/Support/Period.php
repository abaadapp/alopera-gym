<?php

namespace App\Core\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

/**
 * The period a screen shows, from its address: ?range=today|week|month|year|all, or ?range=custom&from&to for dates of
 * one's own (an older link with only ?from&to reads as custom). The same words on every screen (PeriodTabs in the
 * browser), worked out here on the club's calendar: a link opened tomorrow shows tomorrow's «today», and an export reads
 * the period of the screen it was pressed on. A week starts on Saturday; a named period runs to today.
 */
final class Period
{
    public const RANGES = ['today', 'week', 'month', 'year', 'all'];

    public const LABELS = ['today' => 'اليوم', 'week' => 'هذا الأسبوع', 'month' => 'هذا الشهر', 'year' => 'هذه السنة', 'all' => 'كل الفترات', 'custom' => 'فترة مخصصة'];

    private function __construct(
        public readonly string $range,
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
    ) {}

    /** @param  list<string>  $allowed  the named periods this screen offers (all of them by default) */
    public static function fromRequest(Request $request, string $default = 'all', array $allowed = self::RANGES): self
    {
        $asked = $request->string('range')->toString();
        if (in_array($asked, $allowed, true)) {
            return self::named($asked);
        }

        $from = self::date($request->query('from'));
        $to = self::date($request->query('to'));
        if ($from || $to) {
            if ($from && $to && $from->gt($to)) {
                [$from, $to] = [$to, $from];
            }

            return new self('custom', $from, $to);
        }

        return self::named(in_array($default, $allowed, true) ? $default : 'all');
    }

    public static function named(string $range): self
    {
        $today = CarbonImmutable::today();

        return match ($range) {
            'today' => new self('today', $today, $today),
            'week' => new self('week', $today->startOfWeek(CarbonImmutable::SATURDAY), $today),
            'month' => new self('month', $today->startOfMonth(), $today),
            'year' => new self('year', $today->startOfYear(), $today),
            default => new self('all', null, null),
        };
    }

    /** Narrow a query to the period on a date or timestamp column: from the first day's start to the last day's end. */
    public function apply(QueryBuilder $query, string $column): QueryBuilder
    {
        return $query
            ->when($this->from, fn ($q, $from) => $q->where($column, '>=', $from->startOfDay()))
            ->when($this->to, fn ($q, $to) => $q->where($column, '<', $to->addDay()->startOfDay()));
    }

    public function bounded(): bool
    {
        return $this->from !== null || $this->to !== null;
    }

    /** @return array{range: string, from: string|null, to: string|null} for the screen's PeriodTabs */
    public function toArray(): array
    {
        return ['range' => $this->range, 'from' => $this->from?->toDateString(), 'to' => $this->to?->toDateString()];
    }

    /** @return array<string, string> the address parameters that give this period again (links, exports) */
    public function query(): array
    {
        return $this->range === 'custom'
            ? array_filter(['range' => 'custom', 'from' => $this->from?->toDateString(), 'to' => $this->to?->toDateString()])
            : ['range' => $this->range];
    }

    /** In words, for an export's heading: «هذا الشهر (2026-10-01 – 2026-10-31)», «كل الفترات». */
    public function label(): string
    {
        if (! $this->bounded()) {
            return self::LABELS['all'];
        }
        $dates = ($this->from?->toDateString() ?? '…').' – '.($this->to?->toDateString() ?? '…');

        return $this->range === 'custom' ? $dates : self::LABELS[$this->range].' ('.$dates.')';
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $value), null, false) ?: null;
    }
}
