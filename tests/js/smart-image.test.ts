import { describe, expect, it } from 'vitest';
import { aspectRatioOf, galleryCellSpan, heroLayout, imageOrientation, mayCrop, objectFitClass, orientationOf, sideImageSpan } from '@/lib/smart-image';
import type { ImageData } from '@/types/site';

const img = (width: number | null, height: number | null, fit: ImageData['fit'] = 'auto', orientation: ImageData['orientation'] = null): ImageData => ({
    url: '/storage/x.webp', width, height, orientation, alt: '', fit,
});

describe('the orientation of an uploaded image, from its size only', () => {
    it('is landscape, portrait or square by its ratio', () => {
        expect(orientationOf(1920, 1080)).toBe('landscape');
        expect(orientationOf(1080, 1920)).toBe('portrait');
        expect(orientationOf(1000, 1000)).toBe('square');
        // Close to square counts as square (a 4:3.6 photo is not a "wide" one).
        expect(orientationOf(1100, 1000)).toBe('square');
        expect(orientationOf(900, 1000)).toBe('square');
    });

    it('is unknown when the size is missing (an image uploaded before sizes were kept)', () => {
        expect(orientationOf(null, null)).toBe('unknown');
        expect(orientationOf(0, 500)).toBe('unknown');
        expect(imageOrientation(img(null, null))).toBe('unknown');
    });

    it('follows what the server says when it says it', () => {
        expect(imageOrientation(img(1000, 1000, 'auto', 'portrait'))).toBe('portrait');
    });
});

describe('showing an image whole', () => {
    it('keeps the image proportions as the frame ratio', () => {
        expect(aspectRatioOf(1448, 1086)).toBe('1448 / 1086');
        expect(aspectRatioOf(null, 10)).toBeUndefined();
    });

    it('never crops unless the owner chose «ملء المساحة»', () => {
        expect(mayCrop('auto')).toBe(false);
        expect(mayCrop('contain')).toBe(false);
        expect(mayCrop('cover')).toBe(true);
        expect(objectFitClass('auto')).toBe('object-contain');
        expect(objectFitClass('contain')).toBe('object-contain');
        expect(objectFitClass('cover')).toBe('object-cover');
    });
});

describe('the layout chosen from the image', () => {
    it('hero: text only without an image, a banner for a wide one, side by side for a tall or square one', () => {
        expect(heroLayout(null)).toBe('plain');
        expect(heroLayout(img(1920, 1080))).toBe('banner');
        expect(heroLayout(img(1080, 1350))).toBe('split');
        expect(heroLayout(img(1200, 1200))).toBe('split');
        expect(heroLayout(img(null, null))).toBe('banner');
        expect(heroLayout(img(1080, 1350, 'cover'))).toBe('background');
    });

    it('image beside text: the wide image takes the wider column, the tall one the narrower', () => {
        expect(sideImageSpan(img(1600, 900))).toEqual({ image: 7, text: 5 });
        expect(sideImageSpan(img(900, 1600))).toEqual({ image: 5, text: 7 });
        expect(sideImageSpan(img(1000, 1000))).toEqual({ image: 6, text: 6 });
    });

    it('gallery grid: a tall image spans two rows, a panorama two columns', () => {
        expect(galleryCellSpan(img(800, 1200))).toEqual({ cols: 1, rows: 2 });
        expect(galleryCellSpan(img(2000, 800))).toEqual({ cols: 2, rows: 1 });
        expect(galleryCellSpan(img(1600, 1200))).toEqual({ cols: 1, rows: 1 });
    });
});
