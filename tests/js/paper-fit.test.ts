// The sheet's own fit script (resources/views/papers/sheet.blade.php), run as the page runs it: a window narrower
// than an A4 sheet (794px) shows the whole sheet scaled to it, a wider one leaves it at real size, and printing
// is always at real size. jsdom lays nothing out, so the two widths are given.
import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';

const blade = readFileSync('resources/views/papers/sheet.blade.php', 'utf8');
const script = blade.match(/<script>\s*\(function \(\) \{[\s\S]*?var paper = document\.querySelector\('\.paper'\);[\s\S]*?\}\)\(\);\s*<\/script>/)?.[0].replace(/<\/?script>/g, '') ?? '';

function sheetIn(windowWidth: number) {
    document.body.innerHTML = '<div class="paper">كشف راتب</div>';
    const paper = document.querySelector('.paper') as HTMLElement;
    Object.defineProperty(paper, 'offsetWidth', { configurable: true, get: () => 794 });
    Object.defineProperty(document.documentElement, 'clientWidth', { configurable: true, get: () => windowWidth });
    new Function(script)();

    return paper;
}

afterEach(() => { document.body.innerHTML = ''; });

describe('an A4 sheet on screen', () => {
    it('is in the template', () => expect(script).toContain('paper.style.zoom'));

    it('fits a 768px window whole, with no sideways scroll', () => {
        const paper = sheetIn(768);
        expect(Number(paper.style.zoom)).toBeCloseTo(768 / 794, 4);
        expect(794 * Number(paper.style.zoom)).toBeLessThanOrEqual(768);
    });

    it.each([794, 1280, 1440])('stays at real size in a %ipx window', (w) => {
        expect(sheetIn(w).style.zoom).toBe('');
    });

    it('prints at real size and fits again after printing', () => {
        const paper = sheetIn(768);
        window.dispatchEvent(new Event('beforeprint'));
        expect(paper.style.zoom).toBe('');
        window.dispatchEvent(new Event('afterprint'));
        expect(Number(paper.style.zoom)).toBeCloseTo(768 / 794, 4);
    });

    // A browser may print without firing beforeprint: the print rule outranks the inline zoom on its own.
    it('prints at real size even without the print event', () => {
        expect(blade).toMatch(/@media print \{ \.paper \{ zoom: 1 !important; \} \}/);
    });
});
