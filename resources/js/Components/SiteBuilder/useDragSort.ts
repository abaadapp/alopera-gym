import { useState, type DragEvent } from 'react';
import { dropIndex } from '@/lib/site-builder';

/**
 * Drag and drop for a vertical list on a computer (HTML5): an item is dragged by its own row, a line shows the gap it
 * will land in, and dropping moves it. Touch screens have no HTML5 drag, so every list also has up/down buttons.
 * `scope` keeps two lists on one page from taking each other's items.
 */
export function useDragSort(scope: string, length: number, onMove: (from: number, to: number) => void) {
    const [dragging, setDragging] = useState<number | null>(null);
    const [gap, setGap] = useState<number | null>(null);
    const type = `application/x-site-builder-${scope}`;

    const end = () => {
        setDragging(null);
        setGap(null);
    };

    const itemProps = (index: number) => ({
        draggable: true,
        onDragStart: (e: DragEvent) => {
            e.stopPropagation();
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData(type, String(index));
            // Firefox starts no drag without text data.
            e.dataTransfer.setData('text/plain', '');
            setDragging(index);
        },
        onDragOver: (e: DragEvent<HTMLElement>) => {
            if (dragging === null || !e.dataTransfer.types.includes(type)) return;
            e.preventDefault();
            e.stopPropagation();
            e.dataTransfer.dropEffect = 'move';
            const box = e.currentTarget.getBoundingClientRect();
            const next = e.clientY < box.top + box.height / 2 ? index : index + 1;
            if (next !== gap) setGap(next);
        },
        onDrop: (e: DragEvent) => {
            if (dragging === null || gap === null || !e.dataTransfer.types.includes(type)) return;
            e.preventDefault();
            e.stopPropagation();
            const to = dropIndex(dragging, gap);
            if (to !== dragging) onMove(dragging, to);
            end();
        },
        onDragEnd: end,
    });

    /** Whether the drop line shows before item `index` (index === length: after the last). */
    const lineAt = (index: number) => dragging !== null && gap === index && gap !== dragging && gap !== dragging + 1;

    return { itemProps, lineAt, dragging, length };
}
