/**
 * Before an image is sent, the browser may make a light "display source" for it (sent beside the original as
 * `<field>_preview`), so the server never has to open a heavy file:
 *  - a HEIC/HEIF photo, which most servers cannot decode (Safari on iPhone and Mac decodes it natively);
 *  - a photo larger than the display needs (longer side over 2400px): the server then works on 2400px, not 48MP.
 * The original is always sent as it is, byte for byte. When the browser cannot decode the file, nothing is made and
 * the server decides (it explains when it cannot show a HEIC). Nothing here is required: an old browser just sends
 * the original, as before.
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
export function needsDisplaySource(file: File, width: number, height: number): boolean {
    if (file.type === 'image/gif') return false; // a moving GIF is shown as it is
    return isHeif(file) || Math.max(width, height) > PREVIEW_MAX_SIDE;
}

async function decode(file: File): Promise<ImageBitmap | null> {
    if (typeof createImageBitmap !== 'function') return null;
    try {
        return await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        return null;
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

/** The display source for `file`, or null when none is needed or the browser cannot make one. */
export async function displaySource(file: File): Promise<File | null> {
    const bitmap = await decode(file);
    if (!bitmap) return null;
    try {
        if (!needsDisplaySource(file, bitmap.width, bitmap.height)) return null;
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

/**
 * Takes a picked file at once (so saving straight away still sends it) and, once the browser has made a display
 * source for it, calls `apply` again with that source. A caller ignores the late call if another file was picked since.
 */
export function pickImage(file: File | null, apply: (file: File | null, source: File | null) => void): void {
    apply(file, null);
    if (file) void displaySource(file).then((source) => source && apply(file, source));
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
