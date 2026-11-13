import { xsrf } from '@/lib/http';

/**
 * Before an image is sent, the browser may make a light "display source" for it (sent beside the original as
 * `<field>_preview`), so the server never has to open a heavy file:
 *  - a HEIC/HEIF photo, which most servers cannot decode: Safari decodes it itself, other browsers through libheif
 *    (libheif-js, LGPL-3.0, WebAssembly, loaded only for such a file — see docs/MEDIA.md for its review);
 *  - a photo larger than the display needs (longer side over 2400px): the server then works on 2400px, not 48MP;
 *  - every image, when originals go straight to the Spaces bucket (the server then never has the original).
 * The original itself is never changed: it is sent, or put into the bucket, byte for byte.
 */

/** What a picker offers: the formats the server accepts (it checks the bytes, not this list). */
export const IMAGE_ACCEPT = 'image/jpeg,image/png,image/webp,image/gif,image/avif,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.gif,.avif,.heic,.heif';

/** The longest side of a display source; the server refuses a larger one. */
export const PREVIEW_MAX_SIDE = 2400;

const HEIF = /\.(heic|heif)$/i;

export function isHeif(file: File): boolean {
    return /^image\/hei[cf](-sequence)?$/i.test(file.type) || HEIF.test(file.name);
}

/** The size a picture is drawn at so its longer side fits `max`, proportions kept (never cropped). */
export function fitWithin(width: number, height: number, max = PREVIEW_MAX_SIDE): { width: number; height: number } {
    const scale = Math.min(1, max / Math.max(width, height));
    return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

/** Whether a picture of this size and kind needs a display source from the browser. */
export function needsDisplaySource(file: File, width: number, height: number, always = false): boolean {
    if (file.type === 'image/gif') return false; // a GIF is its own display source (see displaySource)
    return always || isHeif(file) || Math.max(width, height) > PREVIEW_MAX_SIDE;
}

/** HEIC/HEIF decoded by libheif in WebAssembly, for browsers that cannot (loaded the first time it is needed). */
async function decodeHeif(file: File): Promise<ImageBitmap | null> {
    try {
        const { default: load } = await import('libheif-js/libheif-wasm/libheif-bundle.mjs');
        const libheif = load();
        const images = new libheif.HeifDecoder().decode(new Uint8Array(await file.arrayBuffer()));
        const image = images[0];
        if (!image) return null;
        const pixels = new ImageData(image.get_width(), image.get_height());
        const shown = await new Promise<ImageData | null>((resolve) => image.display(pixels, resolve));
        images.forEach((i) => i.free?.());
        return shown ? await createImageBitmap(shown) : null;
    } catch {
        return null;
    }
}

async function decode(file: File): Promise<ImageBitmap | null> {
    if (typeof createImageBitmap !== 'function') return null;
    try {
        return await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        return isHeif(file) ? decodeHeif(file) : null;
    }
}

async function encode(bitmap: ImageBitmap, width: number, height: number, transparent: boolean): Promise<Blob | null> {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    if (!context) return null;
    context.imageSmoothingQuality = 'high';
    context.drawImage(bitmap, 0, 0, width, height);
    const blob = (type: string, quality?: number) => new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, type, quality));
    if (!transparent) return blob('image/jpeg', 0.9);
    // WebP keeps the transparency and stays small; a browser that cannot write WebP gives PNG instead.
    const webp = await blob('image/webp', 0.9);
    return webp && webp.type === 'image/webp' ? webp : blob('image/png');
}

/**
 * The display source for `file`, or null when none is needed or the browser cannot make one. With `always` (originals
 * in the bucket) one is made for every image; a GIF is then sent as it is, so a moving one stays moving.
 */
export async function displaySource(file: File, always = false): Promise<File | null> {
    if (always && file.type === 'image/gif') return file;
    const bitmap = await decode(file);
    if (!bitmap) return null;
    try {
        if (!needsDisplaySource(file, bitmap.width, bitmap.height, always)) return null;
        const size = fitWithin(bitmap.width, bitmap.height);
        const transparent = /^image\/(png|webp|avif)$/.test(file.type);
        const blob = await encode(bitmap, size.width, size.height, transparent);
        if (!blob) return null;
        const extension = blob.type === 'image/jpeg' ? 'jpg' : blob.type.slice(6);
        return new File([blob], `${file.name.replace(/\.[^.]+$/, '')}-view.${extension}`, { type: blob.type });
    } finally {
        bitmap.close();
    }
}

/** A local address to show the picked image before it is saved: the display source when the browser made one. */
export function localPreview(file: File, source: File | null): string {
    return URL.createObjectURL(source ?? file);
}

/** The file's SHA-256 in hex: the bucket refuses an upload whose bytes do not match it. */
export async function sha256Hex(file: File): Promise<string> {
    const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer());
    return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Puts the original straight into the club's Spaces bucket (when the server keeps originals there) and gives back the
 * ticket the form sends instead of the file. `ticketUrl` is the place's own route (its permission is checked there).
 */
export async function uploadOriginal(file: File, ticketUrl: string): Promise<string> {
    const response = await fetch(ticketUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify({ name: file.name, type: file.type, size: file.size, sha256: await sha256Hex(file) }),
    });
    if (!response.ok) throw new Error('ticket');
    const ticket = (await response.json()) as { url: string; headers: Record<string, string>; ticket: string };
    const put = await fetch(ticket.url, { method: 'PUT', headers: ticket.headers, body: file });
    if (!put.ok) throw new Error('upload');
    return ticket.ticket;
}

/**
 * Takes a picked file at once (so saving straight away still sends it through the app) and calls `apply` again when
 * the browser has made its display source and, with originals in the bucket (`ticketUrl`), put it there: the form
 * then sends the ticket instead of the file. A caller ignores a late call if another file was picked since.
 */
export function pickImage(file: File | null, apply: (file: File | null, source: File | null, ticket: string | null) => void, ticketUrl?: string | null): void {
    apply(file, null, null);
    if (!file) return;
    void (async () => {
        const source = await displaySource(file, !!ticketUrl);
        if (source) apply(file, source, null);
        if (!ticketUrl || !source) return; // without a display source the original must go through the app
        try {
            apply(file, source, await uploadOriginal(file, ticketUrl));
        } catch {
            // The file is still in the form: saving sends it through the app, as without the bucket.
        }
    })();
}

export interface UploadLimits {
    max_image_kb: number;
    max_request_kb: number;
}

/** The reason a picked image cannot be sent as it is, or null: too large on its own, or too much with the others. */
export function sizeProblem(files: File[], limits: UploadLimits | undefined, alreadyQueued = 0): string | null {
    if (!limits) return null;
    const mb = (kb: number) => (Math.round((kb / 1024) * 10) / 10).toString();
    const big = files.find((f) => f.size > limits.max_image_kb * 1024);
    if (big) return `«${big.name}» أكبر من الحد المسموح للصورة (${mb(limits.max_image_kb)} ميجابايت).`;
    const total = files.reduce((sum, f) => sum + f.size, alreadyQueued);
    if (total > limits.max_request_kb * 1024) return `مجموع الصور أكبر مما يقبله الخادم في حفظ واحد (${mb(limits.max_request_kb)} ميجابايت). احفظ بعضها ثم أضف الباقي.`;
    return null;
}

/** Splits files into groups that each stay under `maxBytes` and `maxCount` (one request each). */
export function batches<T>(items: T[], size: (item: T) => number, maxBytes: number, maxCount: number): T[][] {
    const groups: T[][] = [];
    let current: T[] = [];
    let bytes = 0;
    for (const item of items) {
        const s = size(item);
        if (current.length && (bytes + s > maxBytes || current.length >= maxCount)) {
            groups.push(current);
            current = [];
            bytes = 0;
        }
        current.push(item);
        bytes += s;
    }
    if (current.length) groups.push(current);
    return groups;
}

/**
 * What a form sends once its originals are in the bucket: each field whose ticket is set goes without its file (the
 * app gets the ticket and the display source); a list field drops only the files that have one.
 */
export function withTickets<T extends object>(data: T, fields: string[]): T {
    const out = { ...data } as Record<string, unknown>;
    for (const field of fields) {
        const tickets = out[`${field}_direct`];
        const files = out[field];
        if (Array.isArray(tickets) && Array.isArray(files)) out[field] = files.map((file, i) => (tickets[i] ? null : file));
        else if (tickets) out[field] = null;
    }
    return out as T;
}
