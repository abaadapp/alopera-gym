import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import ExportMenu from '@/Components/Reports/ExportMenu';

vi.mock('sonner', () => ({ toast: { info: vi.fn() } }));

beforeAll(() => {
    // What Radix asks of a real browser.
    window.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} } as unknown as typeof ResizeObserver;
    Element.prototype.scrollIntoView = () => {};
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.releasePointerCapture = () => {};
    URL.createObjectURL = () => 'blob:x';
    URL.revokeObjectURL = () => {};
});

let fetchMock: ReturnType<typeof vi.fn>;
const file = () => new Response('﻿الرقم\n', { status: 200, headers: { 'content-type': 'text/csv; charset=UTF-8', 'content-disposition': 'attachment; filename="sales-all.csv"' } });

beforeEach(() => {
    fetchMock = vi.fn(async () => file());
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

function openMenu() {
    render(<ExportMenu href="/app/sales/export" params={{ range: 'month', branch: '2' }} title="المبيعات" formats={['xlsx', 'csv']} />);
    fireEvent.pointerDown(screen.getByRole('button', { name: 'تصدير المبيعات' }), { button: 0, ctrlKey: false });
}

describe('the export menu', () => {
    it('asks for the file once on a click, with the screen’s filters', async () => {
        openMenu();
        fireEvent.click(screen.getByRole('menuitem', { name: 'تصدير كملف CSV' }));
        await waitFor(() => expect(screen.getByRole('button', { name: 'تصدير المبيعات', hidden: true }).getAttribute('disabled')).toBeNull());
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0][0])).toBe('/app/sales/export?range=month&branch=2&format=csv');
    });

    it('asks once when chosen with the keyboard', async () => {
        openMenu();
        const item = screen.getByRole('menuitem', { name: 'تصدير كملف إكسل' });
        item.focus();
        fireEvent.keyDown(item, { key: 'Enter' });
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        expect(String(fetchMock.mock.calls[0][0])).toContain('format=xlsx');
    });

    it('takes no second request while the first is on its way', async () => {
        let finish: (r: Response) => void = () => {};
        fetchMock.mockImplementation(() => new Promise<Response>((resolve) => { finish = resolve; }));
        openMenu();
        const item = screen.getByRole('menuitem', { name: 'تصدير كملف CSV' });
        fireEvent.click(item);
        fireEvent.click(item);
        fireEvent.click(screen.getByRole('menuitem', { name: 'تصدير كملف إكسل' }));
        expect(fetchMock).toHaveBeenCalledTimes(1);
        await act(async () => finish(file()));
    });

    it.each([
        [403, 'لا تملك صلاحية تصدير هذا التقرير.'],
        [419, 'انتهت الجلسة؛ أعد تحميل الصفحة ثم صدّر مرة أخرى.'],
        [429, 'صدّرت تقارير كثيرة خلال دقيقة؛ حاول بعد قليل.'],
        [500, 'تعذّر التصدير — حاول مرّة أخرى'],
    ])('says what went wrong on %i', async (status, message) => {
        fetchMock.mockImplementation(async () => new Response('', { status }));
        openMenu();
        fireEvent.click(screen.getByRole('menuitem', { name: 'تصدير كملف CSV' }));
        expect((await screen.findByRole('alert', { hidden: true })).textContent).toBe(message);
    });

    it('says so when the network fails', async () => {
        fetchMock.mockImplementation(async () => { throw new TypeError('Failed to fetch'); });
        openMenu();
        fireEvent.click(screen.getByRole('menuitem', { name: 'تصدير كملف CSV' }));
        expect((await screen.findByRole('alert', { hidden: true })).textContent).toBe('تعذّر التصدير — حاول مرّة أخرى');
    });

    it('shows the server’s own reason when it refuses a file (an Excel file too long to hold whole)', async () => {
        const reason = 'عدد العمليات في هذه الفترة والفلاتر (6,000) أكبر من الحد الذي يتسع له ملف Excel (5,000). صدّرها بصيغة CSV، فهو يحملها كلها، أو اختر فترة أقصر.';
        fetchMock.mockImplementation(async () => new Response(JSON.stringify({ message: reason }), { status: 422, headers: { 'content-type': 'application/json' } }));
        openMenu();
        fireEvent.click(screen.getByRole('menuitem', { name: 'تصدير كملف إكسل' }));
        expect((await screen.findByRole('alert', { hidden: true })).textContent).toBe(reason);
    });
});
