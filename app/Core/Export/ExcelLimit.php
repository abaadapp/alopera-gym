<?php

namespace App\Core\Export;

use Illuminate\Http\JsonResponse;

/**
 * How many rows one Excel file may hold. An Excel file is built whole in memory before it is sent (about 67 MB for
 * 5,000 rows of twelve columns, 193 MB for 20,000, measured on the report writer), on a server of 1 GB; CSV is written
 * row by row and holds any number. So a longer list is refused in Excel, with the reason and the way to have it all
 * (CSV, or a shorter period), never sent cut short. The limit is a setting (GYM_EXPORT_XLSX_MAX_ROWS), not a guess.
 */
final class ExcelLimit
{
    public static function rows(): int
    {
        return max(1, (int) config('gym.exports.xlsx_max_rows', 5000));
    }

    /** The refusal for a list of $rows rows, or null when it fits. */
    public static function check(int $rows): ?JsonResponse
    {
        return $rows > self::rows() ? self::refuse($rows) : null;
    }

    /** @param  bool  $atLeast  the count is where reading stopped (a report), not the whole of it */
    public static function refuse(int $rows, bool $atLeast = false): JsonResponse
    {
        $count = $atLeast ? 'أكثر من '.number_format(self::rows()) : number_format($rows);

        return response()->json([
            'message' => 'عدد الصفوف في هذا التصدير ('.$count.') أكبر مما يتسع له ملف Excel ('.number_format(self::rows()).' صف). صدّره بصيغة CSV، فهو يحملها كلها، أو اختر فترة أقصر أو فلاتر أضيق.',
        ], 422);
    }
}
