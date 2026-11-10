import { Clock, Database, EyeOff } from 'lucide-react';
import { Card, Field, Notice, Select, Toggle } from '@/Components/ui';
import { missingContent } from '@/lib/site-builder';
import type { SectionDoc, Tone } from '@/types/site';
import { useBuilder } from './context';
import FieldInput from './FieldInput';

const TONES: { value: Tone; label: string }[] = [
    { value: 'auto', label: 'تلقائي (حسب القالب)' },
    { value: 'white', label: 'أبيض' },
    { value: 'beige', label: 'بيج فاتح' },
    { value: 'dark', label: 'داكن' },
];

/** The form of the chosen section, drawn from its template: background, shown or hidden, then its own fields. */
export default function SectionEditor({ section, onChange }: { section: SectionDoc; onChange: (next: SectionDoc) => void }) {
    const { schemas, errors, waiting } = useBuilder();
    const schema = schemas[section.type];
    const path = `sections.${section.id}`;

    if (!schema) {
        return (
            <Card>
                <Notice tone="amber">نوع هذا القسم ({section.type}) غير معروف. يمكنك حذفه من القائمة.</Notice>
            </Card>
        );
    }

    return (
        <Card className="space-y-4">
            <div>
                <h2 className="text-[17px] font-bold text-ink">{schema.label}</h2>
                <p className="mt-0.5 text-[13px] text-muted">{schema.description}</p>
                <p className="mt-1 text-[12px] text-muted">رابط هذا القسم داخل الصفحة: <span dir="ltr" className="font-mono">#{section.id}</span></p>
            </div>
            {schema.group === 'system' && (
                <Notice tone="blue" icon={<Database />}>
                    {schema.source} تعديل هذه البيانات نفسها يكون من شاشتها في النظام، ويظهر في الموقع تلقائيًا. هنا تختار العناوين وعدد ما يُعرض فقط.
                </Notice>
            )}
            {section.visible && waiting[section.id] && (
                <Notice tone="amber" icon={<Clock />}>
                    لا يظهر هذا القسم للزوار الآن: {waiting[section.id]} ويظهر وحده بمجرد ذلك، دون نشر جديد. وتُخفى معه الأزرار والروابط التي تفتحه.
                </Notice>
            )}
            {section.visible && missingContent(section) && (
                <Notice tone="amber" icon={<Clock />}>لا يظهر هذا القسم للزوار وهو فارغ، فلا يرون إطارًا خاليًا: {missingContent(section)}</Notice>
            )}
            {!section.visible && (
                <Notice tone="gray" icon={<EyeOff />}>هذا القسم مخفي: لا يظهر للزوار ولا في المعاينة حتى تُظهره.</Notice>
            )}
            {errors[path] && <p className="text-xs font-medium text-red-600">{errors[path]}</p>}
            <div className="grid gap-3 sm:grid-cols-2">
                <Toggle checked={section.visible} onChange={(v) => onChange({ ...section, visible: v })} label="ظاهر للزوار" />
                <Field label="خلفية القسم" error={errors[`${path}.tone`]}>
                    <Select value={section.tone} onChange={(e) => onChange({ ...section, tone: (e.target.value || 'auto') as Tone })}>
                        {TONES.map((t) => (
                            <option key={t.value} value={t.value}>{t.label}</option>
                        ))}
                    </Select>
                </Field>
            </div>
            <div className="space-y-4 border-t border-line pt-4">
                {schema.fields.map((f) => (
                    <FieldInput
                        key={f.key}
                        field={f}
                        value={section.data[f.key]}
                        path={`${path}.data`}
                        onChange={(v) => onChange({ ...section, data: { ...section.data, [f.key]: v } })}
                    />
                ))}
            </div>
        </Card>
    );
}
