<?php

namespace App\Core\Audit;

use App\Models\User;
use App\Modules\Payroll\Models\Employee;

/**
 * What an audit entry may show to its reader. Salary and bank values recorded on an employee's entries (older entries
 * logged them) are shown only to someone who may read salaries; anyone else sees that the field changed, not to what.
 */
final class AuditRedaction
{
    public const HIDDEN = '•••';

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public static function values(?array $values, ?string $subjectType, User $viewer): ?array
    {
        if ($values === null || $subjectType !== 'Employee' || $viewer->hasPermission('payroll.salaries')) {
            return $values;
        }
        foreach (Employee::CONFIDENTIAL as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = self::HIDDEN;
            }
        }

        return $values;
    }
}
