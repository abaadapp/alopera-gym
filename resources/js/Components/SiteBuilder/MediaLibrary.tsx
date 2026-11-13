import { useRef, useState, type DragEvent } from 'react';
import { Check, ImagePlus, LoaderCircle, Trash2, Upload } from 'lucide-react';
import { Badge, Button, Input, Modal } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { fileSize, ORIENTATION_LABELS } from '@/lib/site-builder';
import type { MediaItem } from '@/types/site';
import { useBuilder } from './context';
import { acceptedTypes, checkFiles, deleteMedia, MediaError, updateMedia, uploadMedia } from './media-api';

/**
 * The club's images for its website: upload (button or drop), each shown whole with its size and shape, a description
 * for screen readers and search engines, and delete — refused while the image is used, with where it is used. With
 * `onSelect` it is the picker of an image field.
 */
export default function MediaLibrary({ onSelect, selectedId }: { onSelect?: (item: MediaItem) => void; selectedId?: number | null }) {
    const { media, setMedia, limits } = useBuilder();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [over, setOver] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    const accept = acceptedTypes(limits.types).join(',');

    const upload = async (list: FileList | File[] | null) => {
        const files = Array.from(list ?? []);
        if (files.length === 0) return;
        const problem = checkFiles(files, limits);
        if (problem) {
            setError(problem);
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const added = await uploadMedia(files, limits.direct === true, limits.max_request_kb);
            setMedia((current) => [...added, ...current.filter((m) => !added.some((a) => a.id === m.id))]);
            if (onSelect && added.length === 1) onSelect(added[0]);
        } catch (e) {
            setError(e instanceof MediaError ? e.message : 'تعذر رفع الصور. تحقق من الاتصال وحاول مرة أخرى.');
        } finally {
            setBusy(false);
        }
    };

    const drop = (e: DragEvent) => {
        if (!e.dataTransfer.types.includes('Files')) return;
        e.preventDefault();
        setOver(false);
        void upload(e.dataTransfer.files);
    };

    return (
        <div className="space-y-4">
            <div
                onDragOver={(e) => {
                    if (!e.dataTransfer.types.includes('Files')) return;
                    e.preventDefault();
                    setOver(true);
                }}
                onDragLeave={() => setOver(false)}
                onDrop={drop}
                className={cn('flex flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed px-4 py-6 text-center transition', over ? 'border-brand bg-brand-50' : 'border-line bg-[#fafafa]')}
            >
                {busy ? <LoaderCircle className="size-6 animate-spin text-muted" /> : <Upload className="size-6 text-muted" />}
                <p className="text-sm font-medium text-ink">{busy ? 'جارٍ رفع الصور…' : 'اسحب الصور إلى هنا أو'}</p>
                <Button type="button" variant="outline" size="sm" icon={<ImagePlus />} disabled={busy} onClick={() => input.current?.click()}>
                    اختيار صور من جهازك
                </Button>
                <p className="text-[12px] text-muted">JPG وPNG وWebP وHEIC وAVIF وGIF، حتى {Math.round(limits.max_image_kb / 1024)} م.ب للصورة و١٢ صورة في المرة. تُحفظ الصورة الأصلية كما هي، ويظهر في الموقع نسخة كاملة دون قص وبحجم أخف.</p>
                <input ref={input} type="file" multiple accept={accept} className="sr-only" onChange={(e) => { void upload(e.target.files); e.target.value = ''; }} />
            </div>
            {error && <p role="alert" className="rounded-xl bg-red-50 px-3 py-2 text-sm font-medium text-red-700">{error}</p>}

            {media.length === 0 ? (
                <p className="py-6 text-center text-sm text-muted">لا توجد صور بعد. الصور التي ترفعها هنا هي وحدها التي تظهر في الموقع.</p>
            ) : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                    {media.map((item) => (
                        <MediaCard key={item.id} item={item} selected={selectedId === item.id} onSelect={onSelect} />
                    ))}
                </div>
            )}
        </div>
    );
}

function MediaCard({ item, selected, onSelect }: { item: MediaItem; selected: boolean; onSelect?: (item: MediaItem) => void }) {
    const { setMedia, localUses } = useBuilder();
    const [alt, setAlt] = useState(item.alt ?? '');
    const [saving, setSaving] = useState(false);
    const [note, setNote] = useState<string | null>(null);
    const [confirm, setConfirm] = useState(false);
    const [deleting, setDeleting] = useState(false);
    // Where the server knows it is used (saved draft, published site), and where the editor's unsaved edits use it.
    const uses = [...item.used_in, ...(localUses[item.id] ?? []).map((s) => `التعديلات الحالية: ${s}`)];
    const used = uses.length > 0;
    const changed = alt.trim() !== (item.alt ?? '');

    const saveAlt = async () => {
        setSaving(true);
        setNote(null);
        try {
            const updated = await updateMedia(item.id, { alt: alt.trim(), title: item.title ?? '' });
            setMedia((current) => current.map((m) => (m.id === updated.id ? updated : m)));
            setAlt(updated.alt ?? '');
            setNote('حُفظ الوصف.');
        } catch (e) {
            setNote(e instanceof MediaError ? e.message : 'تعذر حفظ الوصف.');
        } finally {
            setSaving(false);
        }
    };

    const remove = async () => {
        setDeleting(true);
        try {
            await deleteMedia(item.id);
            setMedia((current) => current.filter((m) => m.id !== item.id));
            setConfirm(false);
        } catch (e) {
            setNote(e instanceof MediaError ? e.message : 'تعذر حذف الصورة.');
            setConfirm(false);
        } finally {
            setDeleting(false);
        }
    };

    return (
        <div className={cn('flex min-w-0 flex-col overflow-hidden rounded-2xl border bg-white', selected ? 'border-ink ring-2 ring-ink/15' : 'border-line')}>
            <div className="relative grid aspect-square place-items-center bg-[repeating-conic-gradient(#f3f3f1_0_25%,#fbfbfa_0_50%)] bg-[length:16px_16px] p-2">
                <img src={item.thumb_url ?? item.url} alt={item.alt ?? ''} loading="lazy" decoding="async" className="max-h-full max-w-full object-contain" />
                {selected && <span className="absolute top-2 start-2 grid size-6 place-items-center rounded-full bg-ink text-white"><Check className="size-4" /></span>}
            </div>
            <div className="flex flex-1 flex-col gap-2 p-3">
                <div className="flex flex-wrap items-center gap-1.5 text-[12px] text-muted">
                    {item.orientation && <Badge tone="gray">{ORIENTATION_LABELS[item.orientation]}</Badge>}
                    {item.width && item.height && <span className="num" dir="ltr">{item.width}×{item.height}</span>}
                    <span>{fileSize(item.size)}</span>
                    {item.download_url && (
                        <a href={item.download_url} className="ms-auto underline-offset-2 hover:text-ink hover:underline">{item.has_original ? 'الأصلية' : 'تنزيل'}</a>
                    )}
                </div>
                <label className="text-[12px] text-muted" htmlFor={`alt-${item.id}`}>وصف الصورة لقارئات الشاشة ومحركات البحث</label>
                <Input id={`alt-${item.id}`} value={alt} maxLength={255} onChange={(e) => setAlt(e.target.value)} placeholder="مثال: صالة الأوزان الحرة" className="h-9 text-[13px]" />
                {changed && (
                    <Button type="button" size="sm" variant="secondary" loading={saving} onClick={() => void saveAlt()}>حفظ الوصف</Button>
                )}
                {note && <p className="text-[12px] text-muted" role="status">{note}</p>}
                {used && (
                    <div className="rounded-lg bg-[#fafafa] px-2 py-1.5 text-[11px] leading-5 text-[#4b4b4b]">
                        <span className="font-medium">مستخدمة في:</span> {uses.join('، ')}
                    </div>
                )}
                <div className="mt-auto flex flex-wrap gap-2 pt-1">
                    {onSelect && (
                        <Button type="button" size="sm" variant={selected ? 'primary' : 'outline'} onClick={() => onSelect(item)}>
                            {selected ? 'مختارة' : 'اختيار'}
                        </Button>
                    )}
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        icon={<Trash2 />}
                        disabled={used}
                        title={used ? 'لا يمكن حذفها لأنها مستخدمة في الموقع. أزلها من الأقسام أولًا ثم احفظ.' : 'حذف الصورة'}
                        onClick={() => setConfirm(true)}
                        className="text-red-700"
                    >
                        حذف
                    </Button>
                </div>
            </div>
            <Modal
                open={confirm}
                onClose={() => setConfirm(false)}
                title="حذف الصورة؟"
                size="confirm"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setConfirm(false)}>إلغاء</Button>
                        <Button variant="danger" loading={deleting} onClick={() => void remove()}>حذف نهائي</Button>
                    </>
                }
            >
                <p className="text-sm leading-7 text-[#4b4b4b]">تُحذف الصورة من مكتبة الموقع ومن الخادم، ولا يمكن استرجاعها. الصور المستخدمة في المسودة أو الموقع المنشور لا تُحذف.</p>
            </Modal>
        </div>
    );
}
