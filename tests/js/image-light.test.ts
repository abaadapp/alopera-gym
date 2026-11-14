import { describe, expect, it } from 'vitest';
import { checkFiles } from '@/Components/SiteBuilder/media-api';
import { carriesPrivateData, imagesReady, isLightCopy, keptHint, looksDrawn, optimizeSpec, seeThrough, sendsAsItIs, sizeProblem, withTickets } from '@/lib/image-prep';

/** optimized-only: what the browser decides before it sends a light copy in place of the picked file. */

const bytes = (...parts: (number[] | string)[]) => new Uint8Array(parts.flatMap((p) => (typeof p === 'string' ? [...p].map((c) => c.charCodeAt(0)) : p)));
const u16 = (n: number) => [n >> 8, n & 255];
const u32 = (n: number) => [n >>> 24, (n >> 16) & 255, (n >> 8) & 255, n & 255];
const le32 = (n: number) => [n & 255, (n >> 8) & 255, (n >> 16) & 255, n >>> 24];

const jpegBody = [0xff, 0xdb, ...u16(4), 1, 2, 0xff, 0xda, ...u16(4), 9, 9, 7, 7, 0xff, 0xd9];
const jfif = [0xff, 0xe0, ...u16(16), ...bytes('JFIF\0'), 1, 1, 0, 0, 1, 0, 1, 0, 0];
const plainJpeg = bytes([0xff, 0xd8], jfif, jpegBody);
const exifJpeg = bytes([0xff, 0xd8, 0xff, 0xe1, ...u16(12)], 'Exif\0\0MM\0*', jpegBody);
const iccJpeg = bytes([0xff, 0xd8, 0xff, 0xe2, ...u16(16)], 'ICC_PROFILE\0\x01\x01', jpegBody);

const chunk = (type: string, data: number[] = []) => [...u32(data.length), ...bytes(type), ...data, 0, 0, 0, 0];
const png = (...chunks: number[][]) => bytes([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a], ...chunks);
const ihdr = chunk('IHDR', new Array(13).fill(0));

const webp = (...chunks: number[][]) => {
    const body = chunks.flat();
    return bytes('RIFF', le32(body.length + 4), 'WEBP', body);
};
const riffChunk = (tag: string, data: number[]) => [...bytes(tag), ...le32(data.length), ...data, ...(data.length & 1 ? [0] : [])];

describe('camera data in a picked file', () => {
    it('is found in a JPEG (EXIF, comment, bytes after the end) but a colour profile is not such data', () => {
        expect(carriesPrivateData(plainJpeg, 'image/jpeg')).toBe(false);
        expect(carriesPrivateData(iccJpeg, 'image/jpeg')).toBe(false);
        expect(carriesPrivateData(exifJpeg, 'image/jpeg')).toBe(true);
        expect(carriesPrivateData(bytes([0xff, 0xd8, 0xff, 0xfe, ...u16(4)], 'hi', jpegBody), 'image/jpeg')).toBe(true);
        expect(carriesPrivateData(bytes(plainJpeg as unknown as number[], 'video'), 'image/jpeg')).toBe(true);
    });

    it('is found in a PNG (text, EXIF, bytes after the end)', () => {
        expect(carriesPrivateData(png(ihdr, chunk('IDAT', [1, 2]), chunk('IEND')), 'image/png')).toBe(false);
        expect(carriesPrivateData(png(ihdr, chunk('iCCP', [1]), chunk('IDAT', [1]), chunk('IEND')), 'image/png')).toBe(false);
        expect(carriesPrivateData(png(ihdr, chunk('tEXt', [65, 0, 66]), chunk('IDAT', [1]), chunk('IEND')), 'image/png')).toBe(true);
        expect(carriesPrivateData(png(ihdr, chunk('eXIf', [1]), chunk('IEND')), 'image/png')).toBe(true);
        expect(carriesPrivateData(png(ihdr, chunk('IEND'), [1, 2, 3]), 'image/png')).toBe(true);
    });

    it('is found in a WebP (EXIF, XMP, a wrong length)', () => {
        expect(carriesPrivateData(webp(riffChunk('VP8 ', [1, 2, 3, 4])), 'image/webp')).toBe(false);
        expect(carriesPrivateData(webp(riffChunk('VP8X', new Array(10).fill(0)), riffChunk('ICCP', [1, 2]), riffChunk('VP8 ', [1, 2])), 'image/webp')).toBe(false);
        expect(carriesPrivateData(webp(riffChunk('VP8 ', [1, 2]), riffChunk('EXIF', [1, 2, 3])), 'image/webp')).toBe(true);
        expect(carriesPrivateData(webp(riffChunk('VP8 ', [1, 2]), riffChunk('XMP ', [1])), 'image/webp')).toBe(true);
        expect(carriesPrivateData(bytes(webp(riffChunk('VP8 ', [1, 2])) as unknown as number[], [0, 0]), 'image/webp')).toBe(true);
        expect(carriesPrivateData(new Uint8Array([1, 2, 3]), 'image/heic')).toBe(true);
    });
});

describe('what kind of picture it is', () => {
    const pixels = (count: number, colour: (i: number) => [number, number, number, number]) => {
        const out = new Uint8ClampedArray(count * 4);
        for (let i = 0; i < count; i++) out.set(colour(i), i * 4);
        return out;
    };

    it('a drawing (text, QR, logo) has few colours and is kept lossless; a photo has many', () => {
        expect(looksDrawn(pixels(4096, (i) => (i % 7 === 0 ? [0, 0, 0, 255] : [255, 255, 255, 255])))).toBe(true);
        expect(looksDrawn(pixels(4096, (i) => [(i * 37) % 251, (i * 91) % 241, (i * 53) % 239, 255]))).toBe(false);
    });

    it('see-through when any pixel is', () => {
        expect(seeThrough(pixels(16, () => [1, 2, 3, 255]))).toBe(false);
        expect(seeThrough(pixels(16, (i) => [1, 2, 3, i === 5 ? 0 : 255]))).toBe(true);
    });
});

describe('the light copy sent in place of the picked file', () => {
    const plan = { sides: { members: 800, products: 1200, site: 1920, brand: 1600 }, quality: 0.82, pick_max_kb: 40_960 };

    it('is made to the use\'s longest side and quality; a logo is lossless; nothing without a plan', () => {
        expect(optimizeSpec(plan, 'members')).toEqual({ side: 800, quality: 0.82, lossless: false });
        expect(optimizeSpec(plan, 'brand')).toEqual({ side: 1600, quality: 0.82, lossless: true });
        expect(optimizeSpec(plan, 'unknown')?.side).toBe(1920);
        expect(optimizeSpec(null, 'members')).toBeNull();
        expect(keptHint(plan)).toContain('نسخة خفيفة');
        expect(keptHint(null)).toContain('الأصلية كما هي');
    });

    it('is skipped for a file already light, clean and no larger than needed, and only then', () => {
        const spec = optimizeSpec(plan, 'members')!;
        const small = { type: 'image/jpeg', size: 90_000 };
        expect(sendsAsItIs(small, 800, 600, spec, 120_000, true)).toBe(true);
        expect(sendsAsItIs(small, 800, 600, spec, 120_000, false)).toBe(false); // camera data: the copy goes
        expect(sendsAsItIs(small, 801, 600, spec, 120_000, true)).toBe(false); // larger than the use needs
        expect(sendsAsItIs(small, 800, 600, spec, 80_000, true)).toBe(false); // the copy is smaller
        expect(sendsAsItIs({ type: 'image/heic', size: 1 }, 100, 100, spec, 9, true)).toBe(false);
        expect(sendsAsItIs(small, 400, 300, optimizeSpec(plan, 'brand')!, 120_000, true)).toBe(false); // a logo needs a PNG
    });

    it('a picked file only has to fit what the browser may open; the copy is what is sent', () => {
        const big = new File([new Uint8Array(10 * 1024 * 1024)], 'big.jpg', { type: 'image/jpeg' });
        expect(sizeProblem([big], { max_image_kb: 4096, max_request_kb: 20_480 })).toContain('أكبر من الحد');
        expect(sizeProblem([big], { max_image_kb: 4096, max_request_kb: 20_480, optimize: plan })).toBeNull();
        expect(sizeProblem([big], { max_image_kb: 4096, max_request_kb: 20_480, optimize: { ...plan, pick_max_kb: 5000 } })).toContain('أكبر من الحد');
        expect(checkFiles([big], { max_image_kb: 4096, types: ['jpg'], optimize: plan })).toBeNull();
        expect(checkFiles([big], { max_image_kb: 4096, types: ['jpg'] })).toContain('أكبر من الحد');
    });

    it('a form sends only light copies this module made in place of their files, and nothing else changes', async () => {
        const picked = new File(['x'], 'a.jpg', { type: 'image/jpeg' });
        const source = new File(['y'], 'a-view.jpg', { type: 'image/jpeg' }); // a display source, not a light copy
        expect(isLightCopy(source)).toBe(false);
        expect(withTickets({ photo: picked, photo_preview: source }, ['photo'])).toEqual({ photo: picked, photo_preview: source });
        expect(withTickets({ photos: [picked], photos_preview: [null] }, ['photos'])).toEqual({ photos: [picked], photos_preview: [null] });
        await expect(imagesReady()).resolves.toBeUndefined();
    });
});
