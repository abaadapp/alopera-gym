import { router } from '@inertiajs/react';
import { Check, UserX } from 'lucide-react';
import { Button, ConfirmAction } from '@/Components/ui';
import { useCan } from '@/hooks/useShared';

/**
 * «تمت» / «غاب» for a PT session (both consume it from the package). Before its start time only
 * users with pt.close_early can close it, with a reason that goes to the audit log.
 */
export default function PtCloseActions({ id, started }: { id: number; started: boolean }) {
    const can = useCan();
    const close = (status: 'completed' | 'no_show') => router.post(route('club.pt.close', id), { status }, { preserveScroll: true });

    if (started) {
        return (
            <>
                <Button size="sm" variant="success" icon={<Check className="size-3.5" />} onClick={() => close('completed')}>تمت</Button>
                <Button size="sm" variant="outline" icon={<UserX className="size-3.5" />} onClick={() => close('no_show')}>غاب</Button>
            </>
        );
    }

    if (!can('pt.close_early')) {
        return <span className="self-center text-xs text-muted">تُغلق بعد بدء موعدها</span>;
    }

    const message = 'لم يبدأ موعد الجلسة بعد. يُسجَّل الإغلاق المبكر ووقته وسببه في سجل العمليات، وتُحتسب الجلسة من الباقة (ليست إلغاءً).';
    return (
        <>
            <ConfirmAction url={route('club.pt.close', id)} data={{ status: 'completed' }} title="إغلاق مبكر: تمت" message={message} reason="سبب الإغلاق المبكر" confirmLabel="تأكيد" tone="primary" variant="success" icon={<Check className="size-3.5" />}>
                تمت مبكرًا
            </ConfirmAction>
            <ConfirmAction url={route('club.pt.close', id)} data={{ status: 'no_show' }} title="إغلاق مبكر: غاب" message={message} reason="سبب الإغلاق المبكر" confirmLabel="تأكيد" tone="primary" variant="outline" icon={<UserX className="size-3.5" />}>
                غاب مبكرًا
            </ConfirmAction>
        </>
    );
}
