import { StatCard } from '@/Components/ui';
import { number } from '@/lib/format';
import { TableCard, useFormats } from './parts';

type Kind = 'text' | 'int' | 'number' | 'money' | 'percent';
export interface Tables {
    figures: { label: string; value: number | string; kind: Kind }[];
    tables: { title: string; columns: { label: string; kind: Kind }[]; rows: (string | number | null)[][]; total: number }[];
    options: Record<string, unknown>;
}

/**
 * The body of a figures-and-tables report (inventory and purchasing): the figures as cards, then each table as the file
 * has it. A long table shows its first rows; the exported file has every row.
 */
export function TablesBody({ data }: { data: Tables }) {
    const f = useFormats();
    const show = (value: string | number | null, kind: Kind) => {
        if (value === null || value === '') return '—';
        if (kind === 'money') return f.money(value);
        if (kind === 'percent') return f.pct(value);
        if (kind === 'int') return number(value);
        if (kind === 'number') return number(value, Number(value) % 1 ? 3 : 0);
        return String(value);
    };

    return (
        <>
            <div className="mb-4 grid auto-rows-fr grid-cols-2 gap-4 xl:grid-cols-4">
                {data.figures.map((x) => <StatCard key={x.label} label={x.label} value={show(x.value, x.kind)} tone="gray" />)}
            </div>
            {data.tables.map((t) => (
                <TableCard
                    key={t.title}
                    className="mb-4"
                    title={t.title}
                    subtitle={t.total > t.rows.length ? `أول ${number(t.rows.length)} من ${number(t.total)}؛ الملف المصدَّر يحتويها كلها` : undefined}
                    empty="لا بيانات لهذه الفترة والفلاتر"
                    head={t.columns.map((c, i) => <span key={i} className={['money', 'int', 'number', 'percent'].includes(c.kind) ? 'block text-end' : undefined}>{c.label}</span>)}
                >
                    {t.rows.map((row, i) => (
                        <tr key={i}>
                            {row.map((cell, j) => <td key={j} className={['money', 'int', 'number', 'percent'].includes(t.columns[j]?.kind) ? 'text-end num' : j === 0 ? 'font-medium' : 'text-[13px]'}>{show(cell, t.columns[j]?.kind ?? 'text')}</td>)}
                        </tr>
                    ))}
                </TableCard>
            ))}
        </>
    );
}
