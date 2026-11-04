import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, render, waitFor } from '@testing-library/react';

const { shared, error } = vi.hoisted(() => ({ shared: { current: {} as Record<string, unknown> }, error: vi.fn() }));
vi.mock('@/hooks/useShared', () => ({ useShared: () => shared.current }));
vi.mock('@inertiajs/react', () => ({ router: { on: () => () => {} } }));
vi.mock('sonner', () => ({ Toaster: () => null, toast: { error, success: vi.fn() } }));

import Flash from '@/Components/ui/Flash';

afterEach(() => {
    cleanup();
    error.mockReset();
});

describe('a refused save', () => {
    it('is told when the page shows its error nowhere', async () => {
        shared.current = { flash: null, errors: { items: 'الكمية المتاحة من «Protein bar» في الفرع 4 فقط.' } };
        render(<Flash />);
        await waitFor(() => expect(error).toHaveBeenCalledWith('الكمية المتاحة من «Protein bar» في الفرع 4 فقط.'));
    });

    it('is not told twice when the page already shows it under its field', async () => {
        shared.current = { flash: null, errors: { name: 'الاسم مطلوب.' } };
        render(<><p>الاسم مطلوب.</p><Flash /></>);
        await new Promise((r) => requestAnimationFrame(() => r(null)));
        expect(error).not.toHaveBeenCalled();
    });
});
