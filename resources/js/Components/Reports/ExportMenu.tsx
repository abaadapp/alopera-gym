import { useState } from 'react';
import { Download, FileDown, FileSpreadsheet, FileText, LoaderCircle } from 'lucide-react';
import { toast } from 'sonner';
import { Dropdown, DropdownItem } from '@/Components/ui/Dropdown';
import { cn } from '@/lib/cn';

const FORMATS = [
    { key: 'xlsx', label: 'تصدير كملف إكسل', icon: FileSpreadsheet },
    { key: 'pdf', label: 'تصدير كملف PDF', icon: FileText },
    { key: 'csv', label: 'تصدير كملف CSV', icon: FileDown },
] as const;

/**
 * A screen's «تصدير» button, in its header beside the page's other buttons: a menu of Excel, PDF and CSV, as the
 * reference's. The file is fetched in the background so the page can say what happened — a file of headings only when
 * there is no data, no permission, too many exports in a minute — and is then saved under the name the server gave it
 * (the report's key and dates, nothing personal). While it is on its way the button says so and takes no second click.
 *
 * `href` is the export address and `params` the screen's filters (its period, branch, comparison…), so the file holds
 * what the screen shows. Without `href` the user may not export, and there is no button at all: one that answers 403
 * makes its user think the system broken. The server checks the permission again either way.
 */
export default function ExportMenu({ href, params, title }: { href: string | null; params?: Record<string, string>; title: string }) {
    const [busy, setBusy] = useState(false);

    if (!href) return null;

    const download = async (format: string) => {
        if (busy) return;
        setBusy(true);
        try {
            const url = `${href}?${new URLSearchParams({ ...(params ?? {}), format }).toString()}`;
            const res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'text/csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/pdf' } });
            const type = res.headers.get('content-type') ?? '';
            if (!res.ok || !(type.includes('spreadsheetml') || type.includes('text/csv') || type.includes('application/pdf'))) {
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
                // The reference's outline button: 40px (44px on a touch screen), «تصدير» with the download mark.
                <span aria-busy={busy} className={cn('inline-flex h-10 items-center gap-2 rounded-[10px] border border-[#dcdcdc] bg-white px-4 text-sm font-medium text-[#111] transition-colors touch:h-11 [&_svg]:size-[18px]', busy && 'pointer-events-none opacity-50', open ? 'bg-[#fafafa]' : 'hover:bg-[#fafafa]')}>
                    {busy ? <LoaderCircle className="animate-spin" /> : <Download />}
                    {busy ? 'جاري التصدير…' : 'تصدير'}
                </span>
            )}
        >
            {(close) => FORMATS.map((f) => (
                <DropdownItem key={f.key} onClick={() => { close(); void download(f.key); }}>
                    <f.icon className="text-[#9ca3af]" />
                    {f.label}
                </DropdownItem>
            ))}
        </Dropdown>
    );
}
