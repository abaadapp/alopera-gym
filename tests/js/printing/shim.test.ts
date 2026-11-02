import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { JSDOM } from 'jsdom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * The shim the apps inject (mobile/shared/js/alopera-printer-shim.js), loaded as the apps load it: a config line, then
 * the file as it is. A fake native side receives the posts and answers.
 */
const SHIM = readFileSync(resolve(__dirname, '../../../mobile/shared/js/alopera-printer-shim.js'), 'utf8');

/** A fresh page per case (the bridge, once defined, cannot be replaced): the shim evaluated in it as the apps do. */
function install(trusted: string[] = ['http://pos.test:8000']) {
    const dom = new JSDOM('<!doctype html><title>pos</title>', { url: 'http://pos.test:8000/app/pos', runScripts: 'outside-only' });
    const win = dom.window as unknown as Window & { eval(code: string): unknown; __aloperaPrinterConfig?: unknown; AloperaPrinter?: any };
    // The page's timers are the test's fake ones, so a two-minute silence takes no time.
    Object.assign(win, { setTimeout: globalThis.setTimeout, clearTimeout: globalThis.clearTimeout });
    const posted: any[] = [];
    let receive: (raw: string) => void = () => undefined;
    win.__aloperaPrinterConfig = {
        platform: 'android',
        trustedOrigins: trusted,
        post: (json: string) => posted.push(JSON.parse(json)),
        listen: (r: (raw: string) => void) => {
            receive = r;
        },
    };
    win.eval(SHIM);

    return { win, posted, reply: (msg: unknown) => receive(JSON.stringify(msg)), api: win.AloperaPrinter };
}

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

describe('the injected bridge', () => {
    it('is frozen, cannot be replaced, and takes its config away from the page', () => {
        const { api, win } = install();
        expect(api.version).toBe(1);
        expect(Object.isFrozen(api)).toBe(true);
        expect(win.__aloperaPrinterConfig).toBeUndefined();
        win.eval('try { window.AloperaPrinter = {}; } catch (e) {}');
        expect(win.AloperaPrinter).toBe(api);
        expect(api.isAvailable()).toBe(true);
    });

    it('posts one structured request per job and resolves with its one result; progress is relayed', async () => {
        const { api, posted, reply } = install();
        const stages: string[] = [];
        const pending = api.printReceipt({ invoice: 'POS-1', receiptUrl: 'http://pos.test:8000/app/sales/1/receipt?format=pdf', printer: { connection: 'network' } }, (s: string) => stages.push(s));

        expect(posted).toHaveLength(1);
        expect(posted[0]).toMatchObject({ v: 1, method: 'printReceipt', payload: { invoice: 'POS-1' } });
        const id = posted[0].id;
        reply({ id, type: 'progress', stage: 'connecting' });
        reply({ id, type: 'result', result: { ok: true, jobId: id, printed: true } });
        reply({ id, type: 'result', result: { ok: false, code: 'INTERNAL' } }); // a second result is ignored

        await expect(pending).resolves.toMatchObject({ ok: true, printed: true });
        expect(stages).toEqual(['connecting']);
    });

    it('times out on silence, not on length: every message from the app restarts the wait, and a print may have reached the paper', async () => {
        const { api, posted, reply } = install();
        const pending = api.printReceipt({ invoice: 'POS-1' });
        const id = posted[0].id;
        for (let i = 0; i < 5; i++) {
            vi.advanceTimersByTime(100_000);
            reply({ id, type: 'progress', stage: 'sending' });
        }
        vi.advanceTimersByTime(119_000);
        reply({ id, type: 'result', result: { ok: true, printed: true } });
        await expect(pending).resolves.toMatchObject({ ok: true });

        const silent = api.printReceipt({ invoice: 'POS-2' });
        vi.advanceTimersByTime(120_001);
        await expect(silent).resolves.toMatchObject({ ok: false, code: 'BRIDGE_TIMEOUT', mayHavePrinted: true });

        const open = api.openReceipt({ invoice: 'INV-1' });
        vi.advanceTimersByTime(120_001);
        await expect(open).resolves.toMatchObject({ ok: false, code: 'BRIDGE_TIMEOUT', mayHavePrinted: false });
    });

    it('refuses on an untrusted page without posting anything', async () => {
        const { api, posted } = install(['https://gym.example.test']);
        expect(api.isAvailable()).toBe(false);
        await expect(api.printReceipt({})).resolves.toMatchObject({ ok: false, code: 'UNTRUSTED_ORIGIN' });
        expect(posted).toHaveLength(0);
    });
});
