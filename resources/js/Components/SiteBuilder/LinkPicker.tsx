import { useEffect, useState } from 'react';
import { Input, Select } from '@/Components/ui';
import { checkHref, hrefKind, sectionSummary } from '@/lib/site-builder';
import { sectionLabel, useBuilder } from './context';

const EXTERNAL = '__external__';

/**
 * Where a link goes, chosen rather than typed: no link, a page of the site, a section of the home page, or an outside
 * address (https://, tel:, mailto:) typed in a field that only then appears. The same check as the server's runs as
 * it is typed.
 */
export default function LinkPicker({ value, onChange, error, id }: { value: string; onChange: (href: string) => void; error?: string; id?: string }) {
    const { targets, sections, schemas } = useBuilder();
    const kind = hrefKind(value);
    const [external, setExternal] = useState(kind === 'external');

    useEffect(() => {
        if (hrefKind(value) === 'external') setExternal(true);
        else if (value !== '') setExternal(false);
    }, [value]);

    const pageKnown = targets.some((t) => t.value === value);
    const sectionKnown = sections.some((s) => `#${s.id}` === value);
    const selected = external ? EXTERNAL : value;
    const problem = checkHref(value);
    const shown = error ?? (value !== '' ? problem ?? undefined : undefined);

    return (
        <div className="space-y-2">
            <Select
                id={id}
                value={selected}
                placeholder="بدون رابط"
                aria-label="وجهة الرابط"
                onChange={(e) => {
                    const next = e.target.value;
                    if (next === EXTERNAL) {
                        setExternal(true);
                        if (hrefKind(value) !== 'external') onChange('');
                    } else {
                        setExternal(false);
                        onChange(next);
                    }
                }}
            >
                <option value="">بدون رابط</option>
                {targets.map((t) => (
                    <option key={t.value} value={t.value}>{t.label}</option>
                ))}
                {kind === 'page' && !pageKnown && <option value={value}>صفحة غير متاحة ({value})</option>}
                {sections.map((s) => {
                    const summary = sectionSummary(s);
                    return (
                        <option key={s.id} value={`#${s.id}`}>
                            {`قسم في الصفحة الرئيسية: ${sectionLabel(s, schemas)}${summary ? ` — ${summary.slice(0, 40)}` : ''}`}
                        </option>
                    );
                })}
                {kind === 'anchor' && !sectionKnown && <option value={value}>قسم محذوف ({value})</option>}
                <option value={EXTERNAL}>رابط خارجي (موقع، هاتف، بريد)</option>
            </Select>
            {external && (
                <Input
                    dir="ltr"
                    value={value}
                    onChange={(e) => onChange(e.target.value.trim())}
                    placeholder="https://… أو tel:+968… أو mailto:…"
                    aria-label="الرابط الخارجي"
                    aria-invalid={shown ? true : undefined}
                    maxLength={1000}
                />
            )}
            {shown && <p className="text-xs font-medium text-red-600">{shown}</p>}
        </div>
    );
}
