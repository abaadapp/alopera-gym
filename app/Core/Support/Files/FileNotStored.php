<?php

namespace App\Core\Support\Files;

use App\Core\Support\BusinessRuleException;

/** The disk refused a file or kept only part of it (full, read-only, unreachable): nothing was saved, nothing points at it. */
final class FileNotStored extends BusinessRuleException
{
    public function __construct(string $field = 'file')
    {
        parent::__construct('file.not_stored', 'تعذر حفظ الملف على الخادم، فلم يُحفظ شيء. حاول مرة أخرى، وإن تكرر فأبلغ المسؤول.', $field);
    }
}
