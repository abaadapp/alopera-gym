import { useMemo, useState } from 'react';
import { CircleCheck, Languages } from 'lucide-react';
import { Badge, Card, CardHeader, Input, Notice, Textarea, Toggle } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { getWord, setWord, wordGroups, type WordEntry } from '@/lib/site-words';
import type { FieldSchema, PageKey, SectionTypeSchema, SiteDoc } from '@/types/site';
import { useBuilder } from './context';

type Patch = (fn: (d: SiteDoc) => SiteDoc) => void;

/**
 * «النسخة الإنجليزية»: the English website's words, written next to their Arabic. Everything else (sections and their
 * order, images, links, colours, what is shown or hidden) is the same site and is edited on the Arabic side. The
 * English is saved, previewed and published with the rest («حفظ المسودة»، «معاينة»، «نشر التغييرات»).
 */
export default function EnglishPanel({ doc, patch, schemas, pages, defaults, siteUrlEn }: {
    doc: SiteDoc;
    patch: Patch;
    schemas: Record<string, SectionTypeSchema>;
    pages: { key: PageKey; label: string; fields: FieldSchema[] }[];
    defaults: Record<string, string>;
    siteUrlEn: string;
}) {
    const { errors } = useBuilder();
    const [missingOnly, setMissingOnly] = useState(false);
    const groups = useMemo(() => wordGroups(doc, schemas, pages), [doc, schemas, pages]);
    const all = groups.flatMap((g) => g.entries);
    const written = all.filter((e) => getWord(doc.en, e.path).trim() !== '').length;
    const automatic = all.filter((e) => getWord(doc.en, e.path).trim() === '' && defaults[e.arabic.trim()]).length;
    const left = all.length - written - automatic;

    const set = (entry: WordEntry, value: string) => patch((d) => ({ ...d, en: setWord(d.en, entry.path, value) }));

    return (
        <div className="space-y-4">
            <Notice tone="blue" icon={<Languages />}>
                اكتب هنا نصوص الموقع بالإنجليزية، كل نص بجانب أصله العربي. يظهر الموقع الإنجليزي على{' '}
                <a href={siteUrlEn} target="_blank" rel="noopener noreferrer" dir="ltr" className="font-medium underline">{siteUrlEn.replace(/^https?:\/\//, '')}</a>
                {' '}وفيه زر لتغيير اللغة. الأقسام وترتيبها والصور والروابط والألوان واحدة في اللغتين وتُعدَّل من تبويب «الأقسام» والتبويبات الأخرى. النص الذي لا تكتبه بالإنجليزية يظهر بالعربية، إلا العناوين والأزرار المعتادة فيترجمها الموقع تلقائيًا. تُحفظ وتُعايَن وتُنشر مع بقية التعديلات.
            </Notice>

            <Card className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2 text-[13px]">
                    <Badge tone="green">مكتوب بالإنجليزية: <span className="num">{written}</span> من <span className="num">{all.length}</span></Badge>
                    {automatic > 0 && <Badge tone="blue">ترجمة تلقائية: <span className="num">{automatic}</span></Badge>}
                    {left > 0 && <Badge tone="amber">يظهر بالعربية: <span className="num">{left}</span></Badge>}
                </div>
                <Toggle checked={missingOnly} onChange={setMissingOnly} label="إظهار غير المكتوب بالإنجليزية فقط" />
            </Card>

            {all.length === 0 && (
                <Card><p className="py-8 text-center text-sm text-muted">لا توجد نصوص بعد. اكتب نصوص الموقع بالعربية أولًا، ثم ترجمها هنا.</p></Card>
            )}

            {groups.map((group) => {
                const entries = missingOnly ? group.entries.filter((e) => getWord(doc.en, e.path).trim() === '') : group.entries;
                if (entries.length === 0) return null;
                const done = group.entries.every((e) => getWord(doc.en, e.path).trim() !== '');
                return (
                    <Card key={group.key} className="space-y-3">
                        <CardHeader title={<span className="flex items-center gap-2">{group.title}{done && <CircleCheck className="size-4 text-emerald-600" aria-label="مكتمل" />}</span>} className="mb-0" />
                        {entries.map((entry) => (
                            <WordRow key={entry.path.join('.')} entry={entry} value={getWord(doc.en, entry.path)} auto={defaults[entry.arabic.trim()]} error={errors[entry.errorPath]} onChange={(v) => set(entry, v)} />
                        ))}
                    </Card>
                );
            })}
        </div>
    );
}

function WordRow({ entry, value, auto, error, onChange }: { entry: WordEntry; value: string; auto?: string; error?: string; onChange: (v: string) => void }) {
    const n = [...value].length;
    const state = value.trim() !== '' ? null : auto ? <Badge tone="blue">تلقائي</Badge> : <Badge tone="amber">يظهر بالعربية</Badge>;
    const common = {
        value,
        maxLength: entry.max,
        dir: 'ltr' as const,
        lang: 'en',
        placeholder: auto ?? 'English',
        'aria-label': `${entry.label} بالإنجليزية`,
        'aria-invalid': error ? true : undefined,
    };
    return (
        <div className="grid gap-2 border-t border-line pt-3 first:border-t-0 first:pt-0 md:grid-cols-2 md:gap-4">
            <div className="min-w-0">
                <div className="mb-1 flex items-center justify-between gap-2 text-[13px] font-medium text-ink">
                    <span>{entry.label}</span>
                    {state}
                </div>
                <p className="m-0 rounded-[10px] bg-[#f6f6f4] px-3 py-2 text-[14px] leading-6 whitespace-pre-line text-[#3f3f3f]">{entry.arabic}</p>
            </div>
            <div className="min-w-0">
                <div className="mb-1 flex items-center justify-between gap-2 text-[13px] font-medium text-ink">
                    <span>English</span>
                    <span className={cn('num text-[11px]', n > entry.max ? 'text-red-600' : 'text-muted')}>{n}/{entry.max}</span>
                </div>
                {entry.multiline ? (
                    <Textarea {...common} rows={Math.min(8, Math.max(2, Math.ceil(entry.arabic.length / 70)))} onChange={(e) => onChange(e.target.value)} />
                ) : (
                    <Input {...common} onChange={(e) => onChange(e.target.value)} />
                )}
                {error && <p className="mt-1 text-xs font-medium text-red-600">{error}</p>}
            </div>
        </div>
    );
}
