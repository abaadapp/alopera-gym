import { describe, expect, it, vi } from 'vitest';

vi.mock('@/Layouts/AppLayout', () => ({ default: () => null }));

describe('the members list export', () => {
    it('sends the list’s filters and order, never its page', async () => {
        const { exportFilters } = await import('@/Pages/Gym/Members/Index');
        expect(exportFilters({ q: 'سلمى', membership: 'active', status: 'inactive', sort: 'name', dir: 'desc', per_page: '50', page: '2' }))
            .toEqual({ q: 'سلمى', membership: 'active', status: 'inactive', sort: 'name', dir: 'desc' });
        expect(exportFilters({ q: undefined, membership: '' })).toEqual({});
    });
});
