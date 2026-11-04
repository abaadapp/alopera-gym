import { afterEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, renderHook } from '@testing-library/react';

const { printReceipt } = vi.hoisted(() => ({ printReceipt: vi.fn() }));
vi.mock('@/lib/printing/printer-service', async (actual) => ({ ...(await actual<object>()), printReceipt }));
vi.mock('@/lib/printing/print-log', () => ({ recordPrint: vi.fn() }));

import usePrint from '@/hooks/usePrint';

afterEach(() => {
    cleanup();
    printReceipt.mockReset();
});

const job = { title: 'INV-1', url: '/app/papers/invoice/1' };

describe('printing a receipt', () => {
    it('prints once when «print» is pressed twice while the first is still working', async () => {
        let finish: (v: unknown) => void = () => {};
        printReceipt.mockImplementation(() => new Promise((resolve) => (finish = resolve)));
        const { result } = renderHook(() => usePrint(null));

        let first: Promise<unknown> = Promise.resolve();
        let second: unknown;
        await act(async () => {
            first = result.current.print(job);
            second = await result.current.print(job);
        });
        expect(second).toBeNull();
        expect(printReceipt).toHaveBeenCalledTimes(1);

        await act(async () => {
            finish({ ok: true, via: 'tab' });
            await first;
        });
        await act(async () => {
            void result.current.print(job);
        });
        expect(printReceipt).toHaveBeenCalledTimes(2);
    });
});
