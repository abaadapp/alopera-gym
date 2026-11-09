import { useLayoutEffect, useRef, useState } from 'react';
import { ExternalLink, Laptop, RefreshCw, Smartphone, Tablet } from 'lucide-react';
import { Button, Modal } from '@/Components/ui';
import { cn } from '@/lib/cn';

const DEVICES = [
    { key: 'phone', label: 'هاتف', width: 390, icon: Smartphone },
    { key: 'tablet', label: 'تابلت', width: 768, icon: Tablet },
    { key: 'desktop', label: 'كمبيوتر', width: 1280, icon: Laptop },
] as const;

type Device = (typeof DEVICES)[number]['key'];

/**
 * «معاينة»: the saved draft as a visitor would see it, on a phone, a tablet or a computer. The page is drawn at the
 * device's real width and scaled down to fit when the window is narrower. It is the club staff's page only: visitors
 * cannot open it.
 */
export default function PreviewPanel({ open, onClose, url, stamp, dirty }: { open: boolean; onClose: () => void; url: string; stamp: number; dirty: boolean }) {
    const [device, setDevice] = useState<Device>('phone');
    const [reload, setReload] = useState(0);
    const box = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ width: 0, height: 0 });
    const width = DEVICES.find((d) => d.key === device)!.width;
    const src = `${url}${url.includes('?') ? '&' : '?'}v=${stamp}-${reload}`;

    useLayoutEffect(() => {
        if (!open) return;
        const measure = () => {
            const el = box.current;
            if (el) setSize({ width: el.clientWidth, height: el.clientHeight });
        };
        measure();
        const id = window.setTimeout(measure, 50);
        const observer = new ResizeObserver(measure);
        if (box.current) observer.observe(box.current);
        return () => {
            window.clearTimeout(id);
            observer.disconnect();
        };
    }, [open]);

    const scale = size.width > 0 ? Math.min(1, (size.width - 16) / width) : 1;
    const height = size.height > 0 ? size.height / scale : 800;

    return (
        <Modal open={open} onClose={onClose} title="معاينة الموقع" description="المسودة المحفوظة كما ستظهر للزائر. لا يراها أحد غير فريق النادي حتى تنشرها." size="full">
            <div className="flex h-full min-h-0 flex-col gap-3 pb-3">
                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex rounded-[10px] border border-line p-0.5" role="group" aria-label="حجم الشاشة">
                        {DEVICES.map((d) => (
                            <button
                                key={d.key}
                                type="button"
                                aria-pressed={device === d.key}
                                onClick={() => setDevice(d.key)}
                                className={cn('flex items-center gap-1.5 rounded-[8px] px-3 py-1.5 text-[13px] font-medium transition', device === d.key ? 'bg-ink text-white' : 'text-[#4b4b4b] hover:bg-[#f2f2f0]')}
                            >
                                <d.icon className="size-4" />
                                {d.label}
                                <span className="num text-[11px] opacity-70">{d.width}</span>
                            </button>
                        ))}
                    </div>
                    <Button type="button" variant="ghost" size="sm" icon={<RefreshCw />} onClick={() => setReload((r) => r + 1)}>تحديث</Button>
                    <Button variant="outline" size="sm" href={src} external icon={<ExternalLink />}>فتح في نافذة جديدة</Button>
                    {scale < 1 && <span className="num text-[12px] text-muted">مصغرة إلى {Math.round(scale * 100)}٪</span>}
                </div>
                {dirty && <p className="rounded-lg bg-amber-50 px-3 py-2 text-[13px] text-amber-800">لديك تعديلات لم تُحفظ بعد، فلا تظهر في المعاينة. احفظ المسودة ثم حدّث المعاينة.</p>}
                <div ref={box} dir="ltr" className="relative min-h-[60vh] flex-1 overflow-hidden rounded-xl bg-[#f2f2f0]">
                    <div className="absolute top-0 left-1/2 -translate-x-1/2" style={{ width: width * scale, height: size.height || '100%' }}>
                        <iframe
                            key={`${device}-${src}`}
                            src={src}
                            title="معاينة الموقع"
                            className="absolute top-0 left-0 border-0 bg-white shadow-[0_4px_24px_rgba(0,0,0,0.12)]"
                            style={{ width, height, transform: `scale(${scale})`, transformOrigin: '0 0' }}
                        />
                    </div>
                </div>
            </div>
        </Modal>
    );
}
