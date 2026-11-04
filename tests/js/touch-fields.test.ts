import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

describe('a field on a touch screen', () => {
    it('is 16px, or Safari zooms the page into it and stays zoomed', () => {
        const css = readFileSync('resources/css/app.css', 'utf8');
        expect(css).toMatch(/@media \(hover: none\) and \(pointer: coarse\) \{\s*input:not\(\[type='checkbox'\]\):not\(\[type='radio'\]\):not\(\[type='range'\]\),\s*select,\s*textarea \{\s*font-size: 16px !important;/);
    });
});
