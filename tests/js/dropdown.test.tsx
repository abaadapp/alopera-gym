import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { Dropdown, DropdownItem } from '@/Components/ui/Dropdown';

beforeAll(() => {
    // What Radix asks of a real browser.
    window.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} } as unknown as typeof ResizeObserver;
    Element.prototype.scrollIntoView = () => {};
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.releasePointerCapture = () => {};
});

afterEach(cleanup);

function open(children: React.ReactNode) {
    render(<Dropdown label="قائمة" trigger={() => <span>افتح</span>}>{children}</Dropdown>);
    fireEvent.pointerDown(screen.getByRole('button', { name: 'قائمة' }), { button: 0, ctrlKey: false });
}

describe('the menu', () => {
    it('is never taller than the room beside its trigger: on a phone on its side it scrolls within itself', () => {
        open(<DropdownItem>صف</DropdownItem>);
        const menu = screen.getByRole('menu');
        expect(menu.className).toContain('max-h-[var(--radix-dropdown-menu-content-available-height)]');
        expect(menu.className).toContain('overflow-y-auto');
    });

    it('closes on a plain choice', () => {
        const chosen = vi.fn();
        open(<DropdownItem onClick={chosen}>صف</DropdownItem>);
        fireEvent.click(screen.getByRole('menuitem', { name: 'صف' }));
        expect(chosen).toHaveBeenCalledTimes(1);
        expect(screen.queryByRole('menu')).toBeNull();
    });

    it('stays behind the dialog a `keepOpen` item opens', () => {
        const chosen = vi.fn();
        open(<DropdownItem keepOpen onClick={chosen}>حذف</DropdownItem>);
        fireEvent.click(screen.getByRole('menuitem', { name: 'حذف' }));
        expect(chosen).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('menu')).toBeTruthy();
    });

    it('stays open on a `file` link, which is handed its click once and does not navigate', () => {
        const chosen = vi.fn();
        open(<DropdownItem href="/export?format=csv" file onClick={chosen}>تصدير كملف CSV</DropdownItem>);
        const link = screen.getByRole('menuitem', { name: 'تصدير كملف CSV' });
        expect(link.tagName).toBe('A');
        expect(fireEvent.click(link)).toBe(false);
        expect(chosen).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('menu')).toBeTruthy();
    });

    it('opens an `external` link in a tab of its own and closes', () => {
        open(<DropdownItem href="/export?format=pdf" external>تصدير كملف PDF</DropdownItem>);
        const link = screen.getByRole('menuitem', { name: 'تصدير كملف PDF' });
        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.getAttribute('rel')).toBe('noreferrer');
    });
});
