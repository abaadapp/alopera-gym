import { typesOnGlass } from './touch';

// Buttons take Enter as a press; there is no typing to end.
const BUTTONS = ['submit', 'button', 'reset', 'image'];

/**
 * «تم» on a phone's or a tablet's keyboard ends the typing, as the reference's: it closes the keyboard and neither saves
 * the form nor jumps to another field — a dialog half filled in is not sent because its user wanted to see the rest of
 * it. A computer keeps Enter as it is. Left alone: a form with `data-enter-submits` (signing in, a barcode, a QR, a
 * discount: Enter is the point there) and what has already taken the key (the listener is on the document, after React).
 */
export function enterEndsTypingOnTouch(): void {
    if (!typesOnGlass()) return;
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.defaultPrevented) return;
        const el = event.target;
        if (!(el instanceof HTMLInputElement) || BUTTONS.includes(el.type)) return;
        if (el.form?.dataset.enterSubmits !== undefined) return;
        event.preventDefault();
        el.blur();
    });
}
