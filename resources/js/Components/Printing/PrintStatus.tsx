import { useState } from 'react';
import { AlertTriangle, CheckCircle2, FileText, LoaderCircle, RotateCcw, Share2 } from 'lucide-react';
import { Button } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { failureMessage, mayHavePrinted, stageMessage } from '@/lib/printing/printer-service';
import type { PrintState } from '@/hooks/usePrint';

/**
 * A print's state under its buttons: what is happening, «printed» (only for the app's direct print the printer took),
 * or why it failed with «print again» and «open PDF». A failure that may have reached the paper asks the cashier to
 * look at the paper first, and «print again» then takes a second, explicit confirmation: it is never repeated by itself.
 */
export default function PrintStatus({ state, onRetry, onOpen, onShare, className }: {
    state: PrintState;
    onRetry: () => void;
    onOpen: () => void;
    /** The share sheet's own button, when the receipt is ready for it (iPhone and iPad). */
    onShare?: () => void;
    className?: string;
}) {
    const [confirming, setConfirming] = useState(false);
    const { stage, outcome } = state;
    if (stage === 'idle') return null;
    const doubt = mayHavePrinted(outcome);

    if (stage === 'ready') {
        return (
            <div className={cn('flex flex-wrap items-center gap-2 rounded-[12px] border border-line bg-[#f9fafb] px-3 py-2 text-[13px]', className)} role="status">
                <span className="min-w-0 flex-1">{stageMessage('ready')}</span>
                {onShare && <Button size="sm" icon={<Share2 />} onClick={onShare}>طباعة أو مشاركة PDF</Button>}
            </div>
        );
    }

    if (stage !== 'failed') {
        const done = stage === 'success';
        return (
            <p className={cn('flex items-center gap-2 text-[13px]', done ? 'text-[#047857]' : 'text-muted', className)} role="status" aria-live="polite">
                {done ? <CheckCircle2 className="size-4" /> : <LoaderCircle className="size-4 animate-spin" />}
                {stageMessage(stage)}
            </p>
        );
    }

    const code = outcome && !outcome.ok ? outcome.code : 'INTERNAL';
    return (
        <div className={cn('space-y-2 rounded-[12px] border border-[#fecaca] bg-[#fef2f2] px-3 py-2.5 text-[13px] text-[#991b1b]', className)} role="alert">
            <p className="flex items-start gap-2"><AlertTriangle className="mt-0.5 size-4 shrink-0" /><span>{outcome?.ok === false && outcome.connected && code !== 'NATIVE_REQUIRED' ? 'تم الاتصال بالطابعة، ' : ''}{failureMessage(code)}</span></p>
            {doubt && <p className="text-[12px] leading-5">ربما خرج جزء من الإيصال. تحقق من الورق قبل أن تطبعه مرة أخرى.</p>}
            <div className="flex flex-wrap gap-2">
                {doubt && !confirming ? (
                    <Button size="sm" variant="outline" icon={<RotateCcw />} onClick={() => setConfirming(true)}>إعادة الطباعة</Button>
                ) : (
                    <Button size="sm" variant={doubt ? 'danger' : 'outline'} icon={<RotateCcw />} onClick={() => { setConfirming(false); onRetry(); }}>{doubt ? 'لم يخرج — اطبعه مرة أخرى' : 'إعادة الطباعة'}</Button>
                )}
                <Button size="sm" variant="outline" icon={<FileText />} onClick={onOpen}>فتح PDF</Button>
            </div>
        </div>
    );
}
