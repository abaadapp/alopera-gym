import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/react', async (actual) => ({ ...(await actual<object>()), router: { get, on: () => () => {} } }));

import PeriodTabs, { periodLabel } from '@/Components/PeriodTabs';
import { date } from '@/lib/format';

beforeAll(() => {
    window.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} } as unknown as typeof ResizeObserver;
    Element.prototype.scrollIntoView = () => {};
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.releasePointerCapture = () => {};
});

afterEach(() => {
    cleanup();
    get.mockReset();
});

describe('a period in words', () => {
    it('names whole months and years as the reference does, and other dates by day', () => {
        expect(periodLabel('custom', '2026-10-01', '2026-10-31')).toBe('أكتوبر 2026');
        expect(periodLabel('custom', '2026-09-01', '2026-11-30')).toBe('سبتمبر 2026 – نوفمبر 2026');
        expect(periodLabel('custom', '2026-01-01', '2026-12-31')).toBe('2026');
        expect(periodLabel('custom', '2026-10-01', '2026-10-15')).toBe('1 أكتوبر 2026 – 15 أكتوبر 2026');
        expect(periodLabel('all', '2024-11-04', '2026-11-04')).toBe('كل الفترات');
    });

    it('writes a date the same in every browser, with no comma before the year', () => {
        expect(date('2026-10-01')).toBe('1 أكتوبر 2026');
    });
});

describe('the period dialog', () => {
    const open = () => render(<PeriodTabs period={{ range: 'month', from: '2026-11-01', to: '2026-11-04' }} open onOpenChange={() => {}} />);

    it('offers the reference kinds of period and applies a whole year as its dates', () => {
        open();
        for (const k of ['الشهر السابق', 'شهر محدد', 'نطاق أشهر', 'سنة كاملة', 'فترة مخصصة', 'كل الفترات']) expect(screen.getByLabelText(k)).toBeTruthy();
        fireEvent.click(screen.getByLabelText('سنة كاملة'));
        fireEvent.click(screen.getByRole('button', { name: 'تطبيق' }));
        const year = new Date().getFullYear();
        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][1]).toEqual({ range: 'custom', from: `${year}-01-01`, to: `${year}-12-31` });
    });

    it('says what is wrong instead of applying dates the wrong way round', () => {
        open();
        fireEvent.click(screen.getByLabelText('فترة مخصصة'));
        fireEvent.change(screen.getByLabelText('من تاريخ'), { target: { value: '2026-10-20' } });
        fireEvent.change(screen.getByLabelText('إلى تاريخ'), { target: { value: '2026-10-01' } });
        fireEvent.click(screen.getByRole('button', { name: 'تطبيق' }));
        expect(screen.getByRole('alert').textContent).toBe('تاريخ البداية بعد تاريخ النهاية.');
        expect(get).not.toHaveBeenCalled();
    });

    it('opens on whole months as a month', () => {
        render(<PeriodTabs period={{ range: 'custom', from: '2025-03-01', to: '2025-03-31' }} open onOpenChange={() => {}} />);
        expect((screen.getByLabelText('شهر محدد') as HTMLInputElement).checked).toBe(true);
    });
});
