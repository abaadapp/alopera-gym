import { useState } from 'react';
import { router } from '@inertiajs/react';
import { History, RotateCcw } from 'lucide-react';
import { Button, Modal } from '@/Components/ui';
import { date, time } from '@/lib/format';
import type { RevisionItem } from '@/types/site';

/**
 * The versions published so far, newest first. «استرجاع» publishes that version again at once (as a new version, so
 * nothing is lost) and replaces the draft with it.
 */
export default function HistoryModal({ open, onClose, revisions, dirty }: { open: boolean; onClose: () => void; revisions: RevisionItem[]; dirty: boolean }) {
    const [restoring, setRestoring] = useState<RevisionItem | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const restore = () => {
        if (!restoring) return;
        setBusy(true);
        setError(null);
        router.post(route('club.website.revisions.restore', { revision: restoring.id }), {}, {
            preserveScroll: true,
            onSuccess: () => {
                setRestoring(null);
                onClose();
            },
            onError: (errors) => setError((Object.values(errors)[0] as string) ?? 'تعذر الاسترجاع.'),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <>
            <Modal open={open && restoring === null} onClose={onClose} title="سجل النسخ المنشورة" description="كل نشر يحفظ نسخة كاملة من الموقع. يمكنك الرجوع إلى أي نسخة سابقة." size="large">
                {revisions.length === 0 ? (
                    <p className="py-8 text-center text-sm text-muted">لم يُنشر الموقع بهذا التصميم بعد.</p>
                ) : (
                    <ol className="divide-y divide-line pb-2">
                        {revisions.map((r, i) => (
                            <li key={r.id} className="flex flex-wrap items-center gap-3 py-3">
                                <span className="grid size-9 shrink-0 place-items-center rounded-full bg-[#f2f2f0] text-[#4b4b4b]"><History className="size-4" /></span>
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm font-semibold text-ink">
                                        النسخة <span className="num">{r.number}</span>
                                        {i === 0 && <span className="ms-2 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700">المنشورة الآن</span>}
                                    </p>
                                    <p className="text-[12px] text-muted">
                                        {date(r.published_at)} · <span className="num">{time(r.published_at)}</span>
                                        {r.published_by && <> · {r.published_by}</>}
                                        {r.note && <> · {r.note}</>}
                                    </p>
                                </div>
                                {i > 0 && (
                                    <Button type="button" size="sm" variant="outline" icon={<RotateCcw />} onClick={() => setRestoring(r)}>استرجاع</Button>
                                )}
                            </li>
                        ))}
                    </ol>
                )}
            </Modal>
            <Modal
                open={restoring !== null}
                onClose={() => setRestoring(null)}
                title={`استرجاع النسخة ${restoring?.number ?? ''}؟`}
                size="confirm"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setRestoring(null)}>إلغاء</Button>
                        <Button loading={busy} onClick={restore}>استرجاع ونشر</Button>
                    </>
                }
            >
                <div className="space-y-2 text-sm leading-7 text-[#4b4b4b]">
                    <p>تُنشر هذه النسخة على الموقع فورًا، وتحل محل المسودة الحالية. النسخة المنشورة الآن تبقى في السجل ويمكنك الرجوع إليها.</p>
                    {dirty && <p className="font-medium text-amber-800">لديك تعديلات غير محفوظة ستضيع.</p>}
                    {error && <p className="font-medium text-red-700">{error}</p>}
                </div>
            </Modal>
        </>
    );
}
