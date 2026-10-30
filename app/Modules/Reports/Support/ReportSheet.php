<?php

namespace App\Modules\Reports\Support;

/**
 * One table of an exported report: its title (the sheet's name in Excel), its columns with the kind of value each
 * holds, and its rows. A cell made with cell() carries its own kind, for a column that mixes amounts and rates (the
 * summary of figures).
 */
final class ReportSheet
{
    public const TEXT = 'text';

    public const INT = 'int';

    public const NUMBER = 'number';

    public const MONEY = 'money';

    public const PERCENT = 'percent';

    /**
     * @param  list<array{0: string, 1: string}>  $columns  [heading, kind]
     * @param  iterable<array<int, mixed>>  $rows
     */
    public function __construct(public readonly string $title, public readonly array $columns, public readonly iterable $rows) {}

    /** A cell that carries its own kind. */
    public static function cell(mixed $value, string $kind): array
    {
        return ['__kind' => $kind, 'value' => $value];
    }

    /**
     * The summary of a report's figures against the earlier period: one row per figure.
     *
     * @param  array<string, array{current: mixed, previous: mixed, change: float|null}>  $comparison
     * @param  array<string, array{0: string, 1: string}>  $figures  key => [label, kind]
     */
    public static function summary(array $comparison, array $figures, bool $compare = true): self
    {
        $rows = [];
        foreach ($figures as $key => [$label, $kind]) {
            if (! isset($comparison[$key])) {
                continue;
            }
            $m = $comparison[$key];
            $rows[] = $compare
                ? [$label, self::cell($m['current'], $kind), self::cell($m['previous'], $kind), $m['change'] === null ? '—' : self::cell($m['change'], self::PERCENT)]
                : [$label, self::cell($m['current'], $kind)];
        }

        return new self('الملخص', $compare
            ? [['المؤشر', self::TEXT], ['الفترة الحالية', self::NUMBER], ['فترة المقارنة', self::NUMBER], ['التغير', self::PERCENT]]
            : [['المؤشر', self::TEXT], ['القيمة', self::NUMBER]], $rows);
    }
}
