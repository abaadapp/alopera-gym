import { useState } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import Modal from '@/Components/ui/Modal';

afterEach(cleanup);

function Page() {
    const [open, setOpen] = useState(false);
    return (
        <>
            <button type="button" onClick={() => setOpen(true)}>افتح</button>
            <Modal open={open} onClose={() => setOpen(false)} title="نافذة"><input aria-label="حقل" /></Modal>
        </>
    );
}

describe('the dialog', () => {
    it('gives the focus back to the button that opened it, though Safari never focused that button', async () => {
        render(<Page />);
        const opener = screen.getByRole('button', { name: 'افتح' });
        // A press in Safari: pointer events and a click, and the focus stays where it was.
        fireEvent.pointerDown(opener);
        fireEvent.click(opener);
        expect(document.activeElement).not.toBe(opener);
        expect(screen.getByRole('dialog')).toBeTruthy();
        fireEvent.keyDown(document.activeElement ?? document.body, { key: 'Escape' });
        expect(screen.queryByRole('dialog')).toBeNull();
        await waitFor(() => expect(document.activeElement).toBe(opener));
    });
});
