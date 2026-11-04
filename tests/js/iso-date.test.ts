import { afterEach, describe, expect, it, vi } from 'vitest';
import { isoDate } from '@/lib/format';

const zone = process.env.TZ;
afterEach(() => {
    vi.useRealTimers();
    process.env.TZ = zone;
});

describe('the day a form starts on', () => {
    it('is the day on the device, not the UTC day, in the small hours in Muscat', () => {
        process.env.TZ = 'Asia/Muscat';
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-11-01T01:30:00+04:00'));
        expect(new Date().toISOString().slice(0, 10)).toBe('2026-10-31');
        expect(isoDate()).toBe('2026-11-01');
    });

    it('turns a renewal a year on into the same calendar day', () => {
        process.env.TZ = 'Asia/Muscat';
        const d = new Date('2026-11-04T00:00:00');
        d.setMonth(d.getMonth() + 12);
        expect(isoDate(d)).toBe('2027-11-04');
    });
});
