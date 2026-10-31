import { useEffect, type RefObject } from 'react';

/**
 * The panel's tables never scroll sideways. A table wider than its card — on a phone, a tablet, or in a narrow column of
 * a wide screen — first lets its cells wrap (`data-wrap`; amounts stay on one line); if it still does not fit, it is laid
 * out as cards (`data-stacked`, styled in app.css): each row a card, its first cell the card's title, every other cell
 * under its column's name, the row's actions in the card's corner — the same data, nothing hidden.
 *
 * Each cell is given its column's name (`data-label`) from the table's head, and each table is measured as a table
 * whenever the page, its rows or the width change. A table marked `data-stack="never"` keeps its own layout.
 */
export function useStackedTables(root: RefObject<HTMLElement | null>) {
    useEffect(() => {
        const el = root.current;
        if (!el) return;
        let frame = 0;
        const run = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => el.querySelectorAll<HTMLTableElement>('table.table-base').forEach(fit));
        };
        const resized = new ResizeObserver(run);
        resized.observe(el);
        const changed = new MutationObserver(run);
        changed.observe(el, { childList: true, subtree: true });
        run();

        return () => {
            cancelAnimationFrame(frame);
            resized.disconnect();
            changed.disconnect();
        };
    }, [root]);
}

function fit(table: HTMLTableElement) {
    if (table.dataset.stack === 'never') return;
    label(table);
    // Measured as a table each time (and settled before the screen is painted).
    delete table.dataset.stacked;
    delete table.dataset.wrap;
    const box = table.parentElement;
    if (!box || box.clientWidth === 0) return;
    const over = () => Math.max(table.scrollWidth, table.offsetWidth) > box.clientWidth + 1;
    if (!over()) return;
    table.dataset.wrap = '';
    if (!over()) return;
    delete table.dataset.wrap;
    table.dataset.stacked = '';
}

/** Each cell of the body and the foot gets the name of the column it starts in; one spanning several gets none. */
function label(table: HTMLTableElement) {
    const head = table.tHead?.rows[table.tHead.rows.length - 1];
    if (!head) return;
    const names: string[] = [];
    for (const th of Array.from(head.cells)) {
        // A heading only screen readers hear (the actions column) labels nothing on screen.
        const copy = th.cloneNode(true) as HTMLElement;
        copy.querySelectorAll('.sr-only').forEach((n) => n.remove());
        const name = (copy.textContent ?? '').replace(/\s+/g, ' ').trim();
        for (let i = 0; i < th.colSpan; i++) names.push(i === 0 ? name : '');
    }
    for (const section of [...Array.from(table.tBodies), table.tFoot]) {
        if (!section) continue;
        for (const row of Array.from(section.rows)) {
            let column = 0;
            for (const cell of Array.from(row.cells)) {
                const name = cell.colSpan > 1 ? '' : (names[column] ?? '');
                if (cell.dataset.label !== name) cell.dataset.label = name;
                column += cell.colSpan;
            }
        }
    }
}
