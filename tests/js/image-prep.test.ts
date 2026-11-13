import { describe, expect, it } from 'vitest';
import { acceptedTypes, checkFiles } from '@/Components/SiteBuilder/media-api';
import { batches, fitWithin, IMAGE_ACCEPT, isHeif, needsDisplaySource, PREVIEW_MAX_SIDE, sizeProblem } from '@/lib/image-prep';

const file = (name: string, type: string, size = 10) => new File([new Uint8Array(size)], name, { type });

describe('the display source the browser makes', () => {
    it('is drawn to fit 2400px on its longer side, proportions kept, never larger than the photo', () => {
        expect(fitWithin(8064, 6048)).toEqual({ width: 2400, height: 1800 });
        expect(fitWithin(3024, 4032)).toEqual({ width: 1800, height: 2400 });
        expect(fitWithin(1200, 800)).toEqual({ width: 1200, height: 800 });
        expect(PREVIEW_MAX_SIDE).toBe(2400);
    });

    it('is made for a HEIC/HEIF photo, known by its type or, when the browser gives none, by its name', () => {
        expect(isHeif(file('IMG_0001.HEIC', ''))).toBe(true);
        expect(isHeif(file('photo', 'image/heif'))).toBe(true);
        expect(isHeif(file('photo.jpg', 'image/jpeg'))).toBe(false);
        expect(needsDisplaySource(file('IMG_0001.HEIC', 'image/heic'), 400, 300)).toBe(true);
    });

    it('is made for a photo larger than the display needs, and never for a small one or a moving GIF', () => {
        expect(needsDisplaySource(file('big.jpg', 'image/jpeg'), 4032, 3024)).toBe(true);
        expect(needsDisplaySource(file('small.jpg', 'image/jpeg'), 2400, 1600)).toBe(false);
        expect(needsDisplaySource(file('moving.gif', 'image/gif'), 5000, 5000)).toBe(false);
    });

    it('lets the picker offer every accepted format, HEIC included', () => {
        for (const type of ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'image/heic', 'image/heif', '.heic']) {
            expect(IMAGE_ACCEPT.split(',')).toContain(type);
        }
    });
});

describe('the website library checks a file before sending it', () => {
    const limits = { max_image_kb: 4096, types: ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'heic', 'heif'] };

    it('takes the phone formats, a HEIC without a type included', () => {
        expect(acceptedTypes(limits.types)).toEqual(expect.arrayContaining(['image/heic', 'image/heif', 'image/avif', 'image/gif']));
        expect(checkFiles([file('IMG_0001.HEIC', '')], limits)).toBeNull();
        expect(checkFiles([file('a.avif', 'image/avif'), file('b.gif', 'image/gif')], limits)).toBeNull();
    });

    it('refuses what is not an image, by its type', () => {
        expect(checkFiles([file('notes.pdf', 'application/pdf')], limits)).toContain('notes.pdf');
        expect(checkFiles([file('script', '')], limits)).toContain('script');
    });
});

describe('sizes are checked before sending', () => {
    const limits = { max_image_kb: 4096, max_request_kb: 20_480 };
    const sized = (name: string, mb: number) => file(name, 'image/jpeg', Math.round(mb * 1024 * 1024));

    it('names a photo over the limit for one image', () => {
        expect(sizeProblem([sized('ok.jpg', 3), sized('big.jpg', 5)], limits)).toContain('big.jpg');
        expect(sizeProblem([sized('ok.jpg', 3)], limits)).toBeNull();
        expect(sizeProblem([sized('big.jpg', 5)], undefined)).toBeNull();
    });

    it('says when the photos together are more than one save takes, counting those already chosen', () => {
        expect(sizeProblem([sized('a.jpg', 4), sized('b.jpg', 4)], limits, 15 * 1024 * 1024)).toContain('احفظ بعضها');
        expect(sizeProblem([sized('a.jpg', 4)], limits, 10 * 1024 * 1024)).toBeNull();
    });

    it('splits uploads into requests under the size and count limits, in order', () => {
        const groups = batches([5, 5, 5, 12, 1, 1], (n) => n, 12, 3);
        expect(groups).toEqual([[5, 5], [5], [12], [1, 1]]);
        expect(batches(Array.from({ length: 14 }, () => 1), (n) => n, 100, 12).map((g) => g.length)).toEqual([12, 2]);
        expect(batches([30], (n) => n, 12, 3)).toEqual([[30]]);
    });
});
