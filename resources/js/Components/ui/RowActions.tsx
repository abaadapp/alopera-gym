import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { Eye, MoreVertical, Pencil, Trash2 } from 'lucide-react';
import Button from './Button';
import Modal from './Modal';
import { Dropdown, DropdownItem, DropdownSeparator } from './Dropdown';

export interface RowAction {
    label: string;
    icon?: ReactNode;
    href?: string;
    external?: boolean;
    onSelect?: () => void;
    danger?: boolean;
}

/**
 * A row's actions behind ⋮: view, edit, any others, then delete under a line — asked once more before it happens. Each
 * is given only when the user may do it (the server checks again).
 */
export default function RowActions({ label, show, edit, destroy, extra = [] }: { label: string; show?: string; edit?: () => void; destroy?: { url: string; message?: string }; extra?: RowAction[] }) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    if (!show && !edit && !destroy && extra.length === 0) return null;
    const remove = () => destroy && router.delete(destroy.url, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setConfirming(false); } });

    return (
        <>
            <Dropdown label={`إجراءات ${label}`} panelClassName="w-44" trigger={() => <span className="grid size-8 place-items-center rounded-[10px] text-[#4b4b4b] transition-colors hover:bg-ink/[0.045] hover:text-ink"><MoreVertical className="size-4" /></span>}>
                {(close) => (
                    <>
                        {show && <DropdownItem href={show}><Eye /> عرض</DropdownItem>}
                        {edit && <DropdownItem onClick={() => { close(); edit(); }}><Pencil /> تعديل</DropdownItem>}
                        {extra.map((a) => (
                            <DropdownItem key={a.label} href={a.href} external={a.external} destructive={a.danger} onClick={a.onSelect && (() => { close(); a.onSelect?.(); })}>{a.icon} {a.label}</DropdownItem>
                        ))}
                        {destroy && (
                            <>
                                <DropdownSeparator />
                                <DropdownItem destructive onClick={() => { close(); setConfirming(true); }}><Trash2 /> حذف</DropdownItem>
                            </>
                        )}
                    </>
                )}
            </Dropdown>
            <Modal open={confirming} onClose={() => setConfirming(false)} size="xs" title="تأكيد الحذف" footer={<><Button variant="outline" onClick={() => setConfirming(false)} disabled={busy}>إلغاء</Button><Button variant="danger" loading={busy} onClick={remove}>حذف</Button></>}>
                <p className="text-sm text-[#4b4b4b]">{destroy?.message ?? 'سيُحذف هذا السجل نهائيًا. هل تريد المتابعة؟'}</p>
            </Modal>
        </>
    );
}
