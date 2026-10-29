import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Eye, EyeOff, ExternalLink, LayoutTemplate } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, PageHeader } from '@/Components/ui';
import { cn } from '@/lib/cn';

type Section = { key: string; label: string; visible: boolean };

const HINTS: Record<string, string> = {
    features: 'ثلاث بطاقات قصيرة عن مزايا النادي (من تبويب المحتوى)',
    about: 'نبذة النادي وصورتها',
    offers: 'العروض المنشورة من «الأخبار والعروض»',
    plans: 'الباقات المعروضة للبيع مع زر الاشتراك',
    programs: 'برامج الحصص النشطة',
    trainers: 'المدربون الظاهرون في الموقع',
    gallery: 'صور معرض الموقع',
    news: 'آخر الأخبار المنشورة',
    cta: 'دعوة للاشتراك والدفع وحجز الحصص من البوابة',
};

export default function WebsiteSections({ sections, siteUrl }: { sections: Section[]; siteUrl: string }) {
    const form = useForm({ sections });
    const move = (i: number, dir: -1 | 1) => {
        const next = [...form.data.sections];
        [next[i], next[i + dir]] = [next[i + dir], next[i]];
        form.setData('sections', next);
    };
    const toggle = (i: number) => form.setData('sections', form.data.sections.map((s, j) => (j === i ? { ...s, visible: !s.visible } : s)));
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('club.website.sections.update'), { preserveScroll: true });
    };

    return (
        <ClubLayout title="أقسام الموقع">
            <PageHeader
                title="أقسام الموقع"
                subtitle="ترتيب أقسام الصفحة الرئيسية بعد الواجهة، وإظهارها أو إخفاؤها. القسم يظهر فقط حين يكون له محتوى."
                actions={<Button variant="outline" href={siteUrl} external icon={<ExternalLink className="size-4" />}>فتح الموقع</Button>}
            />
            <form onSubmit={submit} className="max-w-3xl space-y-4">
                <Card padded={false}>
                    <div className="flex items-center gap-3 border-b border-line px-5 py-3 text-[13px] text-muted"><LayoutTemplate className="size-4" /> الواجهة الرئيسية — أول الصفحة دائمًا</div>
                    <ul className="divide-y divide-line">
                        {form.data.sections.map((s, i) => (
                            <li key={s.key} className={cn('flex items-center gap-3 px-5 py-3', !s.visible && 'bg-[#fafafa]')}>
                                <span className="w-6 text-center text-xs text-muted num">{i + 1}</span>
                                <div className="min-w-0 flex-1">
                                    <p className={cn('font-medium', !s.visible && 'text-muted line-through')}>{s.label}</p>
                                    <p className="text-xs text-muted">{HINTS[s.key]}</p>
                                </div>
                                <button type="button" onClick={() => toggle(i)} className={cn('inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-[13px]', s.visible ? 'text-emerald-700 hover:bg-emerald-50' : 'text-muted hover:bg-ink/[0.045]')} aria-pressed={s.visible}>
                                    {s.visible ? <Eye className="size-4" /> : <EyeOff className="size-4" />} {s.visible ? 'ظاهر' : 'مخفي'}
                                </button>
                                <span className="flex">
                                    <button type="button" disabled={i === 0} onClick={() => move(i, -1)} className="grid size-8 place-items-center rounded-lg text-muted hover:bg-ink/[0.045] hover:text-ink disabled:opacity-30" aria-label={`رفع ${s.label}`}><ArrowUp className="size-4" /></button>
                                    <button type="button" disabled={i === form.data.sections.length - 1} onClick={() => move(i, 1)} className="grid size-8 place-items-center rounded-lg text-muted hover:bg-ink/[0.045] hover:text-ink disabled:opacity-30" aria-label={`إنزال ${s.label}`}><ArrowDown className="size-4" /></button>
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>
                <div className="flex items-center gap-3">
                    <Button type="submit" loading={form.processing} disabled={!form.isDirty}>حفظ الترتيب</Button>
                    {form.isDirty && <Button type="button" variant="ghost" onClick={() => form.reset()}>تجاهل</Button>}
                </div>
            </form>
        </ClubLayout>
    );
}
