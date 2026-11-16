import { Link } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useShared } from '@/hooks/useShared';

/**
 * The club's backups cannot be relied on (an incomplete one, or none sound in 26 hours): said on every panel page to
 * whoever manages the backups, until a sound one is made again. The text comes whole from the server (BackupHealth).
 */
export default function BackupAlertBar() {
    const { backupAlert } = useShared();
    if (!backupAlert) return null;

    return (
        <div role="alert" className="mb-4 flex items-start gap-2.5 rounded-[12px] border border-[#fecaca] bg-[#fef2f2] px-4 py-3 text-[13.5px] text-[#991b1b]">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
            <p className="min-w-0 flex-1">
                <span className="font-semibold">تنبيه النسخ الاحتياطي: </span>
                {backupAlert.message}{' '}
                <Link href={route('club.backups.index')} className="font-semibold underline underline-offset-2">
                    النسخ الاحتياطي
                </Link>
            </p>
        </div>
    );
}
