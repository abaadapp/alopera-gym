<?php

namespace App\Core\Export;

use RuntimeException;

/** An Excel file being built has passed ExcelLimit: building stops here, before the memory goes. */
final class ExcelTooLong extends RuntimeException
{
    public function __construct(public readonly int $rows)
    {
        parent::__construct("More than {$rows} rows for one Excel file.");
    }
}
