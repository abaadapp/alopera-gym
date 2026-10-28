import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import Button from './Button';
import Modal from './Modal';
import { Field, Textarea } from './Form';

/**
 * Button → confirmation dialog → POST/DELETE. With `reason`, a required text
 * field is shown and sent as `reason` (cancellations, voids, refunds).
 */
export default function ConfirmAction({
    url,
    method = 'post',
    title,
    message,
    reason,
    confirmLabel = 'تأكيد',
    children,
    variant = 'outline',
    size = 'sm',
    tone = 'danger',
    data,
    icon,
}: {
    url: string;
    method?: 'post' | 'delete' | 'put';
    title: ReactNode;
    message?: ReactNode;
    reason?: string;
    confirmLabel?: string;
    children: ReactNode;
    variant?: 'primary' | 'secondary' | 'outline' | 'ghost' | 'danger' | 'success';
    size?: 'sm' | 'md';
    tone?: 'danger' | 'primary';
    data?: Record<string, unknown>;
    icon?: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const submit = () => {
        setBusy(true);
        router.visit(url, {
            method,
            data: { ...(data ?? {}), ...(reason ? { reason: text } : {}) } as Record<string, string>,
            preserveScroll: true,
            onError: (errors) => setError(Object.values(errors)[0] as string),
            onSuccess: () => {
                setOpen(false);
                setText('');
            },
            onFinish: () => setBusy(false),
        });
    };

    return (
        <>
            <Button type="button" variant={variant} size={size} icon={icon} onClick={() => { setError(undefined); setOpen(true); }}>
                {children}
            </Button>
            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title={title}
                size="sm"
                footer={
                    <>
                        <Button variant="ghost" onClick={() => setOpen(false)}>تراجع</Button>
                        <Button variant={tone === 'danger' ? 'danger' : 'primary'} loading={busy} disabled={!!reason && text.trim() === ''} onClick={submit}>
                            {confirmLabel}
                        </Button>
                    </>
                }
            >
                {message && <p className="text-sm text-muted">{message}</p>}
                {reason && (
                    <Field label={reason} error={error} className="mt-3" required>
                        <Textarea value={text} onChange={(e) => setText(e.target.value)} rows={3} autoFocus />
                    </Field>
                )}
                {!reason && error && <p className="mt-2 text-sm text-red-600">{error}</p>}
            </Modal>
        </>
    );
}
