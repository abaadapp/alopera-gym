import { useState } from 'react';
import { ChevronDown, Download, FileSpreadsheet, FileText, LoaderCircle, Lock } from 'lucide-react';
import { toast } from 'sonner';
import { Dropdown, DropdownItem, DropdownLabel } from '@/Components/ui/Dropdown';
import { cn } from '@/lib/cn';

const FORMATS = [
    { key: 'xlsx', label: 'تصدير كملف إكسل', icon: FileSpreadsheet },
    { key: 'csv', label: 'تصدير كملف CSV', icon: FileText },
] as const;

/**
 * A report's «تصدير» button: a menu of Excel and CSV. The file is fetched in the background so the page can say
 * what happened — a file of headings only when there is no data, no permission, too many exports in a minute — and
 * is then saved under the name the server gave it (the report's key and dates, nothing personal).
 *
 * `href` is the report's export address; `params` its filters (the page's address, or nothing for the card's
 * opening period, which `scope` names). Without `href` the button is shown locked: the user may not export.
 */
export default function ExportMenu({ href, params, title, scope, size = 'sm' }: { href: string | null; params?: Record<string, string>; title: string; scope?: string; size?: 'sm' | 'md' }) {
    const [busy, setBusy] = useState(false);
    // Beside the page's other header buttons it is one of them: 40px, outlined, «تصدير» with the download mark.
    const box = cn('inline-flex items-center rounded-[10px] border border-[#dcdcdc] bg-white font-medium text-[#111] transition-colors', size === 'sm' ? 'h-8 gap-1.5 px-2.5 text-[12px] [&_svg]:size-3.5' : 'h-10 gap-2 px-4 text-sm [&_svg]:size-[18px]');

    if (!href) {
        return (
            <span className={cn(box, 'cursor-not-allowed text-[#9ca3af]')} title="تحتاج صلاحية «تصدير التقارير»" aria-disabled="true">
                <Lock className="size-3.5" />
                تصدير
                <span className="sr-only">: تحتاج صلاحية «تصدير التقارير»</span>
            </span>
        );
    }

    const download = async (format: string) => {
        setBusy(true);
        try {
            const url = `${href}?${new URLSearchParams({ ...(params ?? {}), format }).toString()}`;
            const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } });
            const type = res.headers.get('content-type') ?? '';
            if (!res.ok || !(type.includes('spreadsheetml') || type.includes('text/csv'))) {
                toast.error(
                    res.status === 403 ? 'لا تملك صلاحية تصدير هذا التقرير.'
                        : res.status === 429 ? 'صدّرت تقارير كثيرة خلال دقيقة؛ حاول بعد قليل.'
                            : res.redirected || res.status === 401 || res.status === 419 ? 'انتهت الجلسة؛ أعد تحميل الصفحة ثم صدّر مرة أخرى.'
                                : 'تعذّر تصدير التقرير؛ حاول مرة أخرى.',
                );
                return;
            }
            const name = /filename="?([^";]+)"?/.exec(res.headers.get('content-disposition') ?? '')?.[1] ?? `report.${format}`;
            const link = document.createElement('a');
            link.href = URL.createObjectURL(await res.blob());
            link.download = name;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(link.href), 1000);
            if (res.headers.get('x-report-empty') === '1') toast.info(`لا توجد بيانات في «${title}» لهذه الفترة والفلاتر؛ نزل الملف برؤوس الأعمدة فقط.`);
            else toast.success(`نزل ملف «${title}».`);
        } catch {
            toast.error('تعذّر الاتصال؛ تحقق من الشبكة ثم صدّر مرة أخرى.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dropdown
            label={`تصدير ${title}`}
            panelClassName="w-56"
            trigger={({ open }) => (
                <span className={cn(box, open ? 'bg-[#fafafa]' : 'hover:bg-[#fafafa]')}>
                    {busy ? <LoaderCircle className="animate-spin" /> : <Download />}
                    تصدير
                    {size === 'sm' && <ChevronDown className={cn('text-[#9ca3af] transition-transform', open && 'rotate-180')} />}
                </span>
            )}
        >
            {(close) => (
                <>
                    {scope && <DropdownLabel>الفترة: {scope}</DropdownLabel>}
                    {FORMATS.map((f) => (
                        <DropdownItem key={f.key} onClick={() => { close(); void download(f.key); }}>
                            <f.icon />
                            {f.label}
                        </DropdownItem>
                    ))}
                </>
            )}
        </Dropdown>
    );
}
