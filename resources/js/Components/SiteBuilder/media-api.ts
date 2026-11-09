import { xsrf } from '@/lib/http';
import type { MediaItem } from '@/types/site';

/** The media library's own requests: JSON in and out, so a picked or uploaded image shows at once without a reload. */

const headers = () => ({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() });

export class MediaError extends Error {}

async function read<T>(response: Response): Promise<T> {
    let body: unknown = null;
    try {
        body = await response.json();
    } catch {
        body = null;
    }
    if (!response.ok) {
        const b = (body ?? {}) as { message?: string; errors?: Record<string, string[] | string> };
        const first = b.errors ? Object.values(b.errors)[0] : undefined;
        const message = (Array.isArray(first) ? first[0] : first) ?? b.message;
        if (response.status === 413) throw new MediaError('الملف أكبر مما يقبله الخادم.');
        if (response.status === 419) throw new MediaError('انتهت الجلسة. أعد تحميل الصفحة ثم حاول مرة أخرى.');
        if (response.status === 403) throw new MediaError('لا تملك صلاحية إدارة الموقع.');
        throw new MediaError(message || 'تعذر إكمال الطلب. حاول مرة أخرى.');
    }
    return body as T;
}

export async function uploadMedia(files: File[]): Promise<MediaItem[]> {
    const form = new FormData();
    files.forEach((f) => form.append('images[]', f));
    const response = await fetch(route('club.website.media.store'), { method: 'POST', credentials: 'same-origin', headers: headers(), body: form });
    return (await read<{ media: MediaItem[] }>(response)).media;
}

export async function updateMedia(id: number, data: { alt: string; title: string }): Promise<MediaItem> {
    const response = await fetch(route('club.website.media.update', { media: id }), {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: { ...headers(), 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
    });
    return (await read<{ media: MediaItem }>(response)).media;
}

export async function deleteMedia(id: number): Promise<void> {
    const response = await fetch(route('club.website.media.destroy', { media: id }), { method: 'DELETE', credentials: 'same-origin', headers: headers() });
    await read<unknown>(response);
}

/** The image types the server accepts (it may name them by extension or by type), as file types for the browser. */
export function acceptedTypes(types: string[]): string[] {
    const byExt: Record<string, string> = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp' };
    const out = types.map((t) => (t.includes('/') ? t.toLowerCase() : byExt[t.toLowerCase().replace(/^\./, '')])).filter((t): t is string => !!t);
    return out.length ? [...new Set(out)] : ['image/jpeg', 'image/png', 'image/webp'];
}

/** Files the server would refuse, refused here first with the reason: wrong kind, too large, too many. */
export function checkFiles(files: File[], limits: { max_image_kb: number; types: string[] }): string | null {
    if (files.length === 0) return null;
    if (files.length > 12) return 'ارفع ١٢ صورة على الأكثر في المرة الواحدة.';
    const allowed = acceptedTypes(limits.types);
    const wrong = files.find((f) => !allowed.includes(f.type));
    if (wrong) return `«${wrong.name}» ليست صورة مقبولة. الصيغ المقبولة: JPG وPNG وWebP.`;
    const big = files.find((f) => f.size > limits.max_image_kb * 1024);
    if (big) return `«${big.name}» أكبر من الحد المسموح (${Math.round(limits.max_image_kb / 1024)} م.ب).`;
    return null;
}
