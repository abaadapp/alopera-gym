/**
 * Shorter than this is not a keyboard: Safari's address bar folding and unfolding moves the visible area by tens of
 * pixels, and the dialogs would jump with every scroll if that counted.
 */
const MIN_KEYBOARD = 120;

/**
 * The on-screen keyboard's height, in pixels, kept in --kb on the page's root, as the reference does. On a tablet the
 * keyboard does not shrink the page — 100dvh stays the whole screen while its lower part is covered — so what is built
 * on the screen's height (a dialog centred and capped to it, the notices at the bottom) takes --kb off and stays above
 * the keyboard. Only visualViewport knows the keyboard is there; what lies under the visible area (its height and how
 * far the browser moved it to show the focused field) is what the keyboard hides.
 */
export function watchOnScreenKeyboard(): void {
    const viewport = window.visualViewport;
    if (!viewport) return;
    const root = document.documentElement;
    const measure = () => {
        const hidden = window.innerHeight - viewport.height - viewport.offsetTop;
        root.style.setProperty('--kb', `${hidden > MIN_KEYBOARD ? Math.round(hidden) : 0}px`);
    };
    measure();
    viewport.addEventListener('resize', measure);
    viewport.addEventListener('scroll', measure);
}
