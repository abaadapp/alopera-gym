import { useState, type CSSProperties } from 'react';
import { cn } from '@/lib/cn';
import { aspectRatioOf, mayCrop } from '@/lib/smart-image';
import type { ImageData } from '@/types/site';

/**
 * An image the owner uploaded, shown by the owner's rule: whole, at its own proportions, never stretched.
 *
 * - mode 'natural' (default): the image sets its own height from its width (aspect-ratio from its stored size), so
 *   nothing is cut. A max height (maxHeightClass) letterboxes it instead of cropping (object-contain).
 * - mode 'fill': the image fills its parent box; object-contain keeps it whole inside the box, unless the owner
 *   chose «ملء المساحة» (fit 'cover'), the only case where its edges may be trimmed.
 */
export default function SmartImage({ image, mode = 'natural', eager = false, className, maxHeightClass, sizes }: {
    image: ImageData;
    mode?: 'natural' | 'fill';
    eager?: boolean;
    className?: string;
    maxHeightClass?: string;
    sizes?: string;
}) {
    // A file missing on the server draws nothing rather than a broken-image icon.
    const [failed, setFailed] = useState(false);
    const crop = mayCrop(image.fit);
    const ratio = aspectRatioOf(image.width, image.height);
    const style: CSSProperties | undefined = mode === 'natural' && ratio ? { aspectRatio: ratio } : undefined;

    if (failed) return null;

    return (
        <img
            onError={() => setFailed(true)}
            src={image.url}
            alt={image.alt ?? ''}
            width={image.width ?? undefined}
            height={image.height ?? undefined}
            loading={eager ? 'eager' : 'lazy'}
            decoding="async"
            {...(eager ? { fetchPriority: 'high' as const } : {})}
            sizes={sizes}
            style={style}
            className={cn(
                'block max-w-full',
                mode === 'fill' ? 'size-full' : 'h-auto w-full',
                crop && mode === 'fill' ? 'object-cover' : 'object-contain',
                maxHeightClass,
                className,
            )}
        />
    );
}
