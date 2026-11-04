import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// The listener stays on the document for the page's life, so each case loads the module afresh on a device of its own.
async function onDevice(touch: boolean) {
    vi.resetModules();
    window.matchMedia = vi.fn().mockReturnValue({ matches: touch }) as unknown as typeof window.matchMedia;
    const { enterEndsTypingOnTouch } = await import('@/lib/enter-key');
    const added = vi.spyOn(document, 'addEventListener');
    enterEndsTypingOnTouch();
    const listener = added.mock.calls.find(([type]) => type === 'keydown')?.[1] as EventListener | undefined;
    added.mockRestore();
    if (listener) listeners.push(listener);
    return listener;
}

const listeners: EventListener[] = [];

function field(html: string): HTMLInputElement {
    document.body.innerHTML = html;
    const input = document.querySelector('input') as HTMLInputElement;
    input.focus();
    return input;
}

function pressEnter(el: Element) {
    const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    el.dispatchEvent(event);
    return event;
}

describe('the keyboard\'s Enter on a touch screen', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    afterEach(() => {
        listeners.splice(0).forEach((l) => document.removeEventListener('keydown', l));
    });

    it('ends the typing in a form instead of sending it', async () => {
        await onDevice(true);
        const input = field('<form><input type="text" /></form>');
        const event = pressEnter(input);
        expect(event.defaultPrevented).toBe(true);
        expect(document.activeElement).not.toBe(input);
    });

    it('ends the typing in a field outside any form', async () => {
        await onDevice(true);
        const input = field('<input type="text" />');
        pressEnter(input);
        expect(document.activeElement).not.toBe(input);
    });

    it('leaves a form where Enter is the point: signing in, a barcode', async () => {
        await onDevice(true);
        const input = field('<form data-enter-submits><input type="text" /></form>');
        const event = pressEnter(input);
        expect(event.defaultPrevented).toBe(false);
        expect(document.activeElement).toBe(input);
    });

    it('leaves what already took the key', async () => {
        await onDevice(true);
        const input = field('<form><input type="text" /></form>');
        input.addEventListener('keydown', (e) => e.preventDefault());
        pressEnter(input);
        expect(document.activeElement).toBe(input);
    });

    it('leaves a button: Enter presses it', async () => {
        await onDevice(true);
        const input = field('<form><input type="submit" value="حفظ" /></form>');
        pressEnter(input);
        expect(document.activeElement).toBe(input);
    });

    it('changes nothing on a computer', async () => {
        const listener = await onDevice(false);
        expect(listener).toBeUndefined();
        const input = field('<form><input type="text" /></form>');
        const event = pressEnter(input);
        expect(event.defaultPrevented).toBe(false);
        expect(document.activeElement).toBe(input);
    });
});
