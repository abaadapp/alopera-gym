// The preview frame runs no script (sandboxed without allow-scripts): the sheet's own scripts, which only the print page
// runs, are left out of it, so the browser has nothing to block and nothing to report in the console.
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, render } from '@testing-library/react';
import PaperFrame from '@/Components/Papers/PaperFrame';

// jsdom lays nothing out and has no ResizeObserver.
globalThis.ResizeObserver ??= class { observe() {} unobserve() {} disconnect() {} } as unknown as typeof ResizeObserver;

afterEach(cleanup);

describe('a paper preview', () => {
    it('carries the paper without its scripts', () => {
        const html = '<!doctype html><html><body><div class="paper">كشف راتب</div><SCRIPT>(function () { fit(); })();</SCRIPT>'
            + '<script>window.addEventListener("load", function () { window.print(); });</script></body></html>';
        const { container } = render(<PaperFrame html={html} />);
        const doc = container.querySelector('iframe')?.getAttribute('srcdoc') ?? '';
        expect(doc).toContain('<div class="paper">كشف راتب</div>');
        expect(doc).not.toMatch(/<script/i);
    });
});
