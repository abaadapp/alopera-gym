import { createHash, randomBytes } from 'node:crypto';
import { describe, expect, it } from 'vitest';
import { md5Hex } from '@/lib/md5';

const text = (s: string) => new TextEncoder().encode(s);

describe('md5Hex', () => {
    it('gives the RFC 1321 test values', () => {
        expect(md5Hex(text(''))).toBe('d41d8cd98f00b204e9800998ecf8427e');
        expect(md5Hex(text('a'))).toBe('0cc175b9c0f1b6a831c399e269772661');
        expect(md5Hex(text('abc'))).toBe('900150983cd24fb0d6963f7d28e17f72');
        expect(md5Hex(text('message digest'))).toBe('f96b697d7cb7938d525a2f31aaf161d0');
        expect(md5Hex(text('abcdefghijklmnopqrstuvwxyz'))).toBe('c3fcd3d76192e4007dfb496cca67e13b');
        expect(md5Hex(text('12345678901234567890123456789012345678901234567890123456789012345678901234567890'))).toBe('57edf4a22be3c955ac49da2e2107b67a');
    });

    it('matches node for every length around the block edges and for a part of a larger buffer', () => {
        for (const n of [1, 55, 56, 57, 63, 64, 65, 119, 120, 127, 128, 129, 1000, 65_537]) {
            const bytes = randomBytes(n);
            expect(md5Hex(new Uint8Array(bytes)), `length ${n}`).toBe(createHash('md5').update(bytes).digest('hex'));
        }
        const big = new Uint8Array(randomBytes(300));
        const part = big.subarray(7, 207);
        expect(md5Hex(part)).toBe(createHash('md5').update(part).digest('hex'));
    });

    it('reads a 25 MB photo quickly', () => {
        const bytes = new Uint8Array(randomBytes(25 * 1024 * 1024));
        const started = performance.now();
        const hex = md5Hex(bytes);
        const ms = performance.now() - started;
        expect(hex).toBe(createHash('md5').update(bytes).digest('hex'));
        expect(ms).toBeLessThan(3000);
    });
});
