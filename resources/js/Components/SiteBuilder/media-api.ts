import { batches, displaySource, lightCopy, type OptimizePlan, optimizeSpec, uploadOriginal } from '@/lib/image-prep';
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

/**
 * Sends the images in as many requests as needed, each under what the server accepts at once (`maxRequestKb`): each
 * original as it is (or only its key, after a direct upload), and beside it, at the same index, the browser's display
 * source for a HEIC or a very large photo.
 */
export async function uploadMedia(files: File[], direct = false, maxRequestKb = 20_480, optimize: OptimizePlan | null = null): Promise<MediaItem[]> {
    const spec = optimizeSpec(optimize, 'site');
    if (spec) {
        // optimized-only: each light copy is sent in place of its file (or the file itself when already light).
        files = await Promise.all(files.map((f) => lightCopy(f, spec).then((copy) => copy ?? f).catch(() => f)));
        direct = false;
    }
    const sources = spec ? files.map(() => null) : await Promise.all(files.map((f) => displaySource(f, direct).catch(() => null)));
    const items = files.map((file, i) => ({ file, source: sources[i] }));
    const weight = (item: { file: File; source: File | null }) => (direct && item.source ? 0 : item.file.size) + (item.source?.size ?? 0) + 4096;
    const added: MediaItem[] = [];
    for (const group of batches(items, weight, maxRequestKb * 1024 * 0.95, 12)) {
        const form = new FormData();
        for (const [i, { file, source }] of group.entries()) {
            // With originals in the bucket each goes there straight from the browser; the app gets its ticket.
            if (direct && source) form.append(`images_direct[${i}]`, await uploadOriginal(file, route('club.website.media.uploads')));
            else form.append(`images[${i}]`, file);
            if (source) form.append(`images_preview[${i}]`, source);
        }
        const response = await fetch(route('club.website.media.store'), { method: 'POST', credentials: 'same-origin', headers: headers(), body: form });
        added.push(...(await read<{ media: MediaItem[] }>(response)).media);
    }
    return added;
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
    const byExt: Record<string, string> = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', webp: 'image/webp', gif: 'image/gif', avif: 'image/avif', heic: 'image/heic', heif: 'image/heif' };
    const out = types.map((t) => (t.includes('/') ? t.toLowerCase() : byExt[t.toLowerCase().replace(/^\./, '')])).filter((t): t is string => !!t);
    return out.length ? [...new Set(out)] : ['image/jpeg', 'image/png', 'image/webp'];
}

/** Files the server would refuse, refused here first with the reason: wrong kind, too large, too many. */
export function checkFiles(files: File[], limits: { max_image_kb: number; types: string[]; optimize?: OptimizePlan | null }): string | null {
    if (files.length === 0) return null;
    if (files.length > 12) return 'ارفع ١٢ صورة على الأكثر في المرة الواحدة.';
    const allowed = acceptedTypes(limits.types);
    // Some browsers give no type for a HEIC file: its name then decides here, and the server checks its bytes.
    const kind = (f: File) => f.type || acceptedTypes([f.name.split('.').pop() ?? ''])[0];
    const wrong = files.find((f) => !allowed.includes(f.type) && !(f.type === '' && allowed.includes(kind(f)) && /\.(heic|heif|avif)$/i.test(f.name)));
    if (wrong) return `«${wrong.name}» ليست صورة مقبولة. الصيغ المقبولة: JPG وPNG وWebP وHEIC وHEIF وAVIF وGIF.`;
    // With light copies the picked file only has to fit what the browser may open; the copy is what is sent.
    const most = limits.optimize ? limits.optimize.pick_max_kb : limits.max_image_kb;
    const big = files.find((f) => f.size > most * 1024);
    if (big) return `«${big.name}» أكبر من الحد المسموح (${Math.round(most / 1024)} م.ب).`;
    return null;
}
