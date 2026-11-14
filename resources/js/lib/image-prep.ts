import { xsrf } from '@/lib/http';
import { md5Hex } from '@/lib/md5';

/**
 * Before an image is sent, the browser may make a light "display source" for it (sent beside the original as
 * `<field>_preview`), so the server never has to open a heavy file:
 *  - a HEIC/HEIF photo, which most servers cannot decode: Safari decodes it itself, other browsers through libheif
 *    (libheif-js, LGPL-3.0, WebAssembly, loaded only for such a file — see docs/MEDIA.md for its review);
 *  - a photo larger than the display needs (longer side over 2400px): the server then works on 2400px, not 48MP;
 *  - every image, when originals go straight to the Spaces bucket (the server then never has the original).
 * The original itself is never changed: it is sent, or put into the bucket, byte for byte.
 *
 * With the server's optimized-only storage (`media.optimize`, a plan from ImageStore::browserPlan) the browser instead
 * makes the light copy the server keeps, and sends only that (lightCopy): upright, scaled to the use's longest side,
 * never cropped or enlarged, WebP (JPEG where the browser cannot write WebP), lossless for a logo or a picture of
 * text, a QR or a drawing, transparency kept, camera data (EXIF with the location) left behind, colours turned to
 * sRGB as the browser shows them. A picked file that is already light, clean and no larger than needed goes as it is.
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

/** The longest side, WebP quality (0–1) and losslessness of the light copy made for one use (optimized-only). */
export interface OptimizeSpec {
    side: number;
    quality: number;
    lossless: boolean;
}

/** The server's plan for light copies (shared as `media.optimize`), or null when it keeps originals. */
export interface OptimizePlan {
    sides: Record<string, number>;
    quality: number;
    pick_max_kb: number;
}

/** The light copy's settings for a use ('members', 'products', 'brand' for the logo…), or null without a plan. */
export function optimizeSpec(plan: OptimizePlan | null | undefined, use: string): OptimizeSpec | null {
    if (!plan) return null;
    return { side: plan.sides[use] ?? 1920, quality: plan.quality, lossless: use === 'brand' };
}

/** What a form says is kept of the picture it is given. */
export function keptHint(plan: OptimizePlan | null | undefined): string {
    return plan ? 'تُجهَّز الصورة في جهازك قبل الرفع، وتُحفظ منها نسخة خفيفة واضحة دون قص.' : 'تُحفظ الصورة الأصلية كما هي، وتظهر منها نسخة خفيفة.';
}

/** The light copies this module made: a form sends one of these in place of the picked file (withTickets). */
const lightCopies = new WeakSet<File>();
/** Light copies still being made: a form waits for them before sending (imagesReady). */
const making = new Set<Promise<unknown>>();

export function isLightCopy(file: unknown): file is File {
    return file instanceof File && lightCopies.has(file);
}

/** Resolves once every light copy being made is done (or failed: the picked file is then sent as it is). */
export async function imagesReady(): Promise<void> {
    while (making.size) await Promise.allSettled([...making]);
}

/**
 * Whether the bytes of a still JPEG, PNG or WebP carry more than the picture (the same test as the server's
 * ImageInspector::carriesPrivateData): EXIF (the location, the camera), XMP, IPTC, comments, PNG text, or anything
 * after the picture's end. A colour profile does not count. Anything not understood counts.
 */
export function carriesPrivateData(bytes: Uint8Array, type: string): boolean {
    const u16 = (at: number) => (bytes[at] << 8) | bytes[at + 1];
    const u32 = (at: number) => ((bytes[at] << 24) >>> 0) + (bytes[at + 1] << 16) + (bytes[at + 2] << 8) + bytes[at + 3];
    const le32 = (at: number) => bytes[at] + (bytes[at + 1] << 8) + (bytes[at + 2] << 16) + ((bytes[at + 3] << 24) >>> 0);
    const tag = (at: number) => String.fromCharCode(...bytes.subarray(at, at + 4));
    if (type === 'image/jpeg') {
        for (let at = 2; at + 4 <= bytes.length; ) {
            if (bytes[at] !== 0xff) return true;
            const code = bytes[at + 1];
            if (code === 0xd8 || code === 0x01 || code === 0xff || (code >= 0xd0 && code <= 0xd7)) { at += 2; continue; }
            if (code === 0xda || code === 0xd9) return !(bytes[bytes.length - 2] === 0xff && bytes[bytes.length - 1] === 0xd9);
            if (code === 0xfe || (code >= 0xe1 && code <= 0xef && code !== 0xe2 && code !== 0xee)) return true;
            const length = u16(at + 2);
            if (length < 2) return true;
            at += 2 + length;
        }
        return true;
    }
    if (type === 'image/png') {
        for (let at = 8; at + 8 <= bytes.length; ) {
            const name = tag(at + 4);
            if (['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'].includes(name)) return true;
            if (name === 'IEND') return at + 12 !== bytes.length;
            at += 12 + u32(at);
        }
        return true;
    }
    if (type === 'image/webp') {
        if (bytes.length < 12 || le32(4) + 8 !== bytes.length) return true;
        for (let at = 12; at + 8 <= bytes.length; ) {
            if (tag(at) === 'EXIF' || tag(at) === 'XMP ') return true;
            const size = le32(at + 4);
            at += 8 + size + (size & 1);
        }
        return false;
    }
    return true;
}

/**
 * Whether a picture is a drawing rather than a photo, from a small sample of its pixels taken without smoothing
 * (RGBA): a logo, a picture of text, a QR or a barcode has few distinct colours. Such a picture is kept lossless so
 * its edges and letters stay sharp.
 */
export function looksDrawn(rgba: Uint8ClampedArray, maxColours = 512): boolean {
    const seen = new Set<number>();
    for (let i = 0; i + 3 < rgba.length; i += 4) {
        seen.add(((rgba[i] << 24) >>> 0) + (rgba[i + 1] << 16) + (rgba[i + 2] << 8) + rgba[i + 3]);
        if (seen.size > maxColours) return false;
    }
    return true;
}

/** Whether any pixel of the sample is see-through. */
export function seeThrough(rgba: Uint8ClampedArray): boolean {
    for (let i = 3; i < rgba.length; i += 4) if (rgba[i] < 255) return true;
    return false;
}

/** Whether the picked file itself can be sent in place of a light copy of `copySize` bytes. */
export function sendsAsItIs(file: { type: string; size: number }, width: number, height: number, spec: OptimizeSpec, copySize: number, clean: boolean): boolean {
    const types = spec.lossless ? ['image/png'] : ['image/jpeg', 'image/png', 'image/webp'];
    return types.includes(file.type) && Math.max(width, height) <= spec.side && clean && file.size <= copySize;
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

/** The canvas a light copy is written from: the picture upright, scaled to fit, never cropped. */
function drawn(bitmap: ImageBitmap, width: number, height: number): HTMLCanvasElement | null {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    if (!context) return null;
    context.imageSmoothingQuality = 'high';
    context.drawImage(bitmap, 0, 0, width, height);
    return canvas;
}

/** Up to 64×64 pixels of the picture taken without smoothing, so no colour is invented on the way. */
function sample(bitmap: ImageBitmap): Uint8ClampedArray | null {
    const size = fitWithin(bitmap.width, bitmap.height, 64);
    const canvas = document.createElement('canvas');
    canvas.width = size.width;
    canvas.height = size.height;
    const context = canvas.getContext('2d', { willReadFrequently: true });
    if (!context) return null;
    context.imageSmoothingEnabled = false;
    context.drawImage(bitmap, 0, 0, size.width, size.height);
    return context.getImageData(0, 0, size.width, size.height).data;
}

const blobOf = (canvas: HTMLCanvasElement, type: string, quality?: number) => new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, type, quality));

/**
 * The light copy to send in place of `file` (optimized-only), or null to send the picked file as it is: a GIF (a
 * moving one must keep moving; the server makes a still one light), a file already light and clean, or one this
 * browser cannot read (the server then says what to do).
 */
export async function lightCopy(file: File, spec: OptimizeSpec): Promise<File | null> {
    if (file.type === 'image/gif') return null;
    const bitmap = await decode(file);
    if (!bitmap) return null;
    try {
        const size = fitWithin(bitmap.width, bitmap.height, spec.side);
        const canvas = drawn(bitmap, size.width, size.height);
        const pixels = sample(bitmap);
        if (!canvas || !pixels) return null;
        let blob: Blob | null;
        if (spec.lossless) {
            blob = await blobOf(canvas, 'image/png');
        } else if (looksDrawn(pixels)) {
            // Lossless WebP where the browser writes it (quality 1), else PNG: whichever is smaller.
            const [webp, png] = await Promise.all([blobOf(canvas, 'image/webp', 1), blobOf(canvas, 'image/png')]);
            blob = webp && webp.type === 'image/webp' && (!png || webp.size <= png.size) ? webp : png;
            if (blob && blob.size > 1_048_576) {
                // Too rich to be a drawing after all (a photo of a whiteboard): a high-quality WebP keeps letters sharp.
                const lossy = await blobOf(canvas, 'image/webp', Math.max(spec.quality, 0.9));
                if (lossy && lossy.type === 'image/webp' && lossy.size < blob.size / 2) blob = lossy;
            }
        } else {
            const webp = await blobOf(canvas, 'image/webp', spec.quality);
            blob = webp && webp.type === 'image/webp' ? webp : await blobOf(canvas, seeThrough(pixels) ? 'image/png' : 'image/jpeg', spec.quality);
        }
        if (!blob) return null;
        if (file.size <= blob.size && Math.max(bitmap.width, bitmap.height) <= spec.side && /^image\/(jpeg|png|webp)$/.test(file.type)) {
            const clean = !carriesPrivateData(new Uint8Array(await file.arrayBuffer()), file.type);
            if (sendsAsItIs(file, bitmap.width, bitmap.height, spec, blob.size, clean)) return null;
        }
        const extension = blob.type === 'image/jpeg' ? 'jpg' : blob.type.slice(6);
        const copy = new File([blob], `${file.name.replace(/\.[^.]+$/, '') || 'image'}.${extension}`, { type: blob.type });
        lightCopies.add(copy);
        return copy;
    } finally {
        bitmap.close();
    }
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

/** The file's SHA-256 in hex. */
export async function sha256Hex(file: File): Promise<string> {
    return (await fingerprints(file)).sha256;
}

/**
 * The file's SHA-256 and MD5 in hex, from one read. The signed address carries both, so the bucket refuses other bytes;
 * the server then compares the MD5 the bucket computed itself from what it stored (its ETag).
 */
export async function fingerprints(file: File): Promise<{ sha256: string; md5: string }> {
    const bytes = new Uint8Array(await file.arrayBuffer());
    const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', bytes));
    return { sha256: [...digest].map((b) => b.toString(16).padStart(2, '0')).join(''), md5: md5Hex(bytes) };
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
        body: JSON.stringify({ name: file.name, type: file.type, size: file.size, ...(await fingerprints(file)) }),
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
export function pickImage(file: File | null, apply: (file: File | null, source: File | null, ticket: string | null) => void, ticketUrl?: string | null, optimize?: OptimizeSpec | null): void {
    apply(file, null, null);
    if (!file) return;
    if (optimize) {
        // optimized-only: the light copy goes in the source's place, and the form sends it instead of the file.
        const job = lightCopy(file, optimize)
            .then((copy) => copy && apply(file, copy, null))
            .catch(() => undefined)
            .finally(() => making.delete(job));
        making.add(job);
        return;
    }
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

/**
 * The reason a picked image cannot be sent as it is, or null: too large on its own, or too much with the others. With
 * light copies (optimized-only) a picked file only has to fit what the browser may open: the copy is what is sent.
 */
export function sizeProblem(files: File[], limits: (UploadLimits & { optimize?: OptimizePlan | null }) | undefined, alreadyQueued = 0): string | null {
    if (!limits) return null;
    const mb = (kb: number) => (Math.round((kb / 1024) * 10) / 10).toString();
    if (limits.optimize) {
        const huge = files.find((f) => f.size > limits.optimize!.pick_max_kb * 1024);
        return huge ? `«${huge.name}» أكبر من الحد المسموح للصورة (${mb(limits.optimize.pick_max_kb)} ميجابايت).` : null;
    }
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
 * What a form sends: a light copy (optimized-only) in place of its picked file, with no display source beside it;
 * once originals are in the bucket, each field whose ticket is set goes without its file (the app gets the ticket and
 * the display source); a list field changes only the files concerned.
 */
export function withTickets<T extends object>(data: T, fields: string[]): T {
    const out = { ...data } as Record<string, unknown>;
    for (const field of fields) {
        const sources = out[`${field}_preview`];
        if (Array.isArray(sources) && Array.isArray(out[field])) {
            out[field] = (out[field] as unknown[]).map((file, i) => (isLightCopy(sources[i]) ? sources[i] : file));
            out[`${field}_preview`] = sources.map((source) => (isLightCopy(source) ? null : source));
        } else if (isLightCopy(sources)) {
            out[field] = sources;
            out[`${field}_preview`] = null;
        }
        const tickets = out[`${field}_direct`];
        const files = out[field];
        if (Array.isArray(tickets) && Array.isArray(files)) out[field] = files.map((file, i) => (tickets[i] ? null : file));
        else if (tickets) out[field] = null;
    }
    return out as T;
}
