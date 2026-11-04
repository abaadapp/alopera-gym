/**
 * Whether this device types on its glass: a phone or a tablet, an iPad too (it says it is a Mac, and only its touch points
 * tell). One answer for the bigger tap targets (`touch:`, through [data-touch]) and the keyboard's Enter (lib/enter-key).
 */
export function typesOnGlass(): boolean {
    if (window.matchMedia?.('(hover: none) and (pointer: coarse)').matches) return true;
    return (navigator.maxTouchPoints ?? 0) > 1 && /Macintosh/.test(navigator.userAgent);
}

export function markTouchDevice(): void {
    if (typesOnGlass()) document.documentElement.setAttribute('data-touch', '');
}
