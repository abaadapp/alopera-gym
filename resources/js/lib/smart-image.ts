/**
 * How the website shows an image the owner uploaded: decided from its width and height only — never from what the
 * picture shows. The rule the owner set: by default an image is shown whole (no cropping, no stretching); only the
 * owner's explicit «ملء المساحة» (fit 'cover') may trim its edges.
 */
import type { ImageData, ImageFit } from '@/types/site';

export type Orientation = 'landscape' | 'portrait' | 'square' | 'unknown';

/** Wider than 1.15:1 is landscape, narrower than 0.87:1 portrait, in between square; no size known → unknown. */
export function orientationOf(width: number | null | undefined, height: number | null | undefined): Orientation {
    if (!width || !height || width <= 0 || height <= 0) return 'unknown';
    const ratio = width / height;
    if (ratio > 1.15) return 'landscape';
    if (ratio < 0.87) return 'portrait';
    return 'square';
}

/** The image's own orientation: the server's word when it has one, else computed from the size. */
export function imageOrientation(image: Pick<ImageData, 'width' | 'height' | 'orientation'>): Orientation {
    return image.orientation ?? orientationOf(image.width, image.height);
}

/** The CSS aspect-ratio that keeps the image's own proportions, or undefined when its size is unknown. */
export function aspectRatioOf(width: number | null | undefined, height: number | null | undefined): string | undefined {
    return width && height && width > 0 && height > 0 ? `${width} / ${height}` : undefined;
}

/** Only an explicit 'cover' may crop; 'auto' and 'contain' always show the whole image. */
export function mayCrop(fit: ImageFit | null | undefined): boolean {
    return fit === 'cover';
}

/** object-fit class for the fit. */
export function objectFitClass(fit: ImageFit | null | undefined): 'object-cover' | 'object-contain' {
    return mayCrop(fit) ? 'object-cover' : 'object-contain';
}

export type HeroLayout = 'plain' | 'background' | 'banner' | 'split';

/**
 * The hero's layout:
 * - no image → 'plain' (dark background, text only);
 * - fit 'cover' → 'background' (full-bleed, may crop — the owner's choice);
 * - landscape (or unknown size) → 'banner': the whole image at its own ratio, text over its lower part on wide
 *   screens and under it on narrow ones;
 * - portrait or square → 'split': text on one side, the whole image on the other.
 */
export function heroLayout(image: Pick<ImageData, 'width' | 'height' | 'orientation' | 'fit'> | null | undefined): HeroLayout {
    if (!image) return 'plain';
    if (mayCrop(image.fit)) return 'background';
    const o = imageOrientation(image);
    return o === 'portrait' || o === 'square' ? 'split' : 'banner';
}

/**
 * Columns for an image beside text, in a 12-column grid on large screens: a landscape image takes the wider
 * share, a portrait one the narrower, a square one half.
 */
export function sideImageSpan(image: Pick<ImageData, 'width' | 'height' | 'orientation'>): { image: number; text: number } {
    const o = imageOrientation(image);
    if (o === 'landscape' || o === 'unknown') return { image: 7, text: 5 };
    if (o === 'portrait') return { image: 5, text: 7 };
    return { image: 6, text: 6 };
}

/** Gallery cell for the 'grid' layout: a portrait image spans two rows, a wide landscape one two columns. */
export function galleryCellSpan(image: Pick<ImageData, 'width' | 'height' | 'orientation'>): { cols: 1 | 2; rows: 1 | 2 } {
    const { width, height } = image;
    const o = imageOrientation(image);
    if (o === 'portrait') return { cols: 1, rows: 2 };
    if (o === 'landscape' && width && height && width / height >= 1.9) return { cols: 2, rows: 1 };
    return { cols: 1, rows: 1 };
}
