import { describe, expect, it } from 'vitest';
import { withVat } from '@/lib/vat';

describe('a document total with VAT, as the server issues it', () => {
    it('adds nothing when the club charges no VAT', () => {
        expect(withVat(100, 0)).toBe(100);
        expect(withVat(1.5, 0)).toBe(1.5);
    });

    it('adds the rate on top, in thousandths', () => {
        expect(withVat(100, 5)).toBe(105);
        expect(withVat(1.5, 5)).toBe(1.575);
        expect(withVat(30, 14)).toBe(34.2);
    });

    it('rounds the tax to the nearest thousandth as PHP round() does', () => {
        expect(withVat(1.575, 5)).toBe(1.654); // 78.75 → 79
        expect(withVat(0.01, 5)).toBe(0.011); // 0.5 → 1
        expect(withVat(1.575, 0.5)).toBe(1.583); // 7.875 → 8
    });

    it('leaves an empty or fully discounted part at zero', () => {
        expect(withVat(0, 5)).toBe(0);
    });
});
