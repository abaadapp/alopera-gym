<?php

namespace App\Core\Backup;

use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Between a restore's journal and its end, the club's rows and files can come from two states (TenantBackupService::
 * restoreWhole: the rows roll back by themselves when the process dies, the files moved so far do not). Every page of
 * the club — the panel, the website, the member portal — passes here once its club is known, so none is shown from
 * such a mix, and no one waits for backup:recover or the next night:
 * - a restore still running (its process holds the club's lock): "a restore is running", 503, try again in a moment;
 * - one that stopped half-way (a killed PHP, a power cut: the lock went with the process): finished here, forward or
 *   back as its journal says, then the page is shown as usual;
 * - one that cannot be finished yet (a disk error): 503 until it can, the cause reported; never the mix.
 */
class RestoreGate
{
    public function __construct(private readonly TenantBackupService $backups) {}

    public function hold(Tenant $tenant): ?Response
    {
        if (! $this->backups->restoreUnderWay($tenant)) {
            return null;
        }
        try {
            $this->backups->recover($tenant);

            return null;
        } catch (BusinessRuleException $e) {
            $running = $e->rule === 'backup.busy';
            $running || report($e);
        } catch (Throwable $e) {
            report($e);
            $running = false;
        }

        return response()->view('errors.503', ['message' => $running
            ? 'تجري الآن استعادة نسخة احتياطية لبيانات النادي. أعد تحميل الصفحة بعد لحظات.'
            : 'توقفت استعادة نسخة احتياطية لبيانات النادي قبل اكتمالها، ولم يمكن إكمالها بعد. لن تظهر البيانات حتى تكتمل؛ أعد المحاولة بعد قليل.',
        ], 503)->header('Retry-After', '10');
    }
}
