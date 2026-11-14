import { useState } from 'react';
import { ImagePlus, TriangleAlert } from 'lucide-react';
import { Badge, Button, Modal, Select } from '@/Components/ui';
import { autoLayoutHint, ORIENTATION_LABELS } from '@/lib/site-builder';
import type { FieldSchema, ImageFit, ImageRef } from '@/types/site';
import { useBuilder } from './context';
import MediaLibrary from './MediaLibrary';

const FITS: { value: ImageFit; label: string }[] = [
    { value: 'auto', label: 'تلقائي — الصورة كاملة دون قص، والتخطيط يتكيف مع أبعادها' },
    { value: 'contain', label: 'كاملة داخل إطار' },
    { value: 'cover', label: 'ملء المساحة — قد تُقص الأطراف' },
];

/**
 * An image of a section: an empty dashed box «أضف صورة» until one is picked from the club's library (or uploaded
 * there), then the whole image with its size and shape, «تغيير» / «إزالة», and how it is shown. Only images the club
 * uploaded can be picked: nothing is fetched from elsewhere.
 */
export default function ImageField({ field, value, onChange, error }: { field: FieldSchema; value: ImageRef | null | undefined; onChange: (v: ImageRef | null) => void; error?: string }) {
    const { media } = useBuilder();
    const [open, setOpen] = useState(false);
    const ref = value && typeof value === 'object' ? value : null;
    const item = ref?.id ? media.find((m) => m.id === ref.id) : undefined;
    const fit: ImageFit = ref?.fit ?? 'auto';

    return (
        <div className="space-y-2">
            {!ref?.id ? (
                <button
                    type="button"
                    onClick={() => setOpen(true)}
                    className="flex w-full flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-line bg-[#fafafa] px-4 py-8 text-center text-[#4b4b4b] transition hover:border-[#9ca3af] hover:bg-[#f4f4f5]"
                >
                    <ImagePlus className="size-7" />
                    <span className="text-sm font-semibold">أضف صورة</span>
                    <span className="text-[12px] text-muted">حتى تُضاف صورة يظهر القسم للزائر بالنص والألوان فقط، دون إطار فارغ.</span>
                </button>
            ) : (
                <div className="overflow-hidden rounded-2xl border border-line bg-white">
                    {item ? (
                        <div className="grid max-h-64 place-items-center bg-[repeating-conic-gradient(#f3f3f1_0_25%,#fbfbfa_0_50%)] bg-[length:16px_16px] p-2">
                            <img src={item.url} alt={item.alt ?? ''} loading="lazy" decoding="async" className="max-h-60 max-w-full object-contain" />
                        </div>
                    ) : (
                        <div className="flex items-center gap-2 bg-amber-50 px-3 py-4 text-sm text-amber-800">
                            <TriangleAlert className="size-4 shrink-0" />
                            هذه الصورة لم تعد في مكتبة الصور. اختر صورة أخرى أو أزلها.
                        </div>
                    )}
                    <div className="flex flex-wrap items-center gap-2 border-t border-line px-3 py-2">
                        {item?.orientation && <Badge tone="gray">{ORIENTATION_LABELS[item.orientation]}</Badge>}
                        {item?.width && item.height && <span className="num text-[12px] text-muted" dir="ltr">{item.width}×{item.height}</span>}
                        {item && !item.alt && <span className="text-[12px] text-amber-700">بلا وصف — أضفه من مكتبة الصور</span>}
                        <span className="ms-auto flex gap-2">
                            <Button type="button" size="sm" variant="outline" onClick={() => setOpen(true)}>تغيير</Button>
                            <Button type="button" size="sm" variant="ghost" onClick={() => onChange(null)}>إزالة</Button>
                        </span>
                    </div>
                </div>
            )}
            {ref?.id && (
                <div className="space-y-1.5">
                    <Select value={fit} aria-label="طريقة عرض الصورة" onChange={(e) => onChange({ id: ref.id, fit: (e.target.value || 'auto') as ImageFit })}>
                        {FITS.map((f) => (
                            <option key={f.value} value={f.value}>{f.label}</option>
                        ))}
                    </Select>
                    <p className="text-[12px] leading-5 text-muted">
                        {fit === 'auto' && autoLayoutHint(item?.orientation, field.usage)}
                        {fit === 'contain' && 'تظهر الصورة كاملة داخل إطار القسم، مع هوامش بلون الخلفية إن اختلفت نسبتها عنه.'}
                        {fit === 'cover' && 'تملأ الصورة المساحة كلها، وقد يُقص جزء من أطرافها حسب حجم الشاشة.'}
                    </p>
                </div>
            )}
            {error && <p className="text-xs font-medium text-red-600">{error}</p>}
            <Modal open={open} onClose={() => setOpen(false)} title={field.label} description="اختر صورة من مكتبة الموقع أو ارفع صورة جديدة." size="wide">
                <MediaLibrary
                    selectedId={ref?.id ?? null}
                    onSelect={(m) => {
                        onChange({ id: m.id, fit });
                        setOpen(false);
                    }}
                />
            </Modal>
        </div>
    );
}
