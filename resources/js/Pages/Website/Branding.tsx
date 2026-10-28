import { useMemo, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { ExternalLink, ImagePlus } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, CardHeader, Field, Input, PageHeader } from '@/Components/ui';

interface Props {
    club: { name: string; logo_url: string | null; primary_color: string; secondary_color: string };
    siteUrl: string;
}

export default function WebsiteBranding({ club, siteUrl }: Props) {
    const form = useForm<{ primary_color: string; secondary_color: string; logo: File | null }>({ primary_color: club.primary_color, secondary_color: club.secondary_color, logo: null });
    const preview = useMemo(() => (form.data.logo ? URL.createObjectURL(form.data.logo) : club.logo_url), [form.data.logo, club.logo_url]);
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.website.branding.update'), { forceFormData: true, preserveScroll: true, onSuccess: () => form.setData('logo', null) });
    };

    return (
        <ClubLayout title="الهوية والألوان">
            <PageHeader
                title="الهوية والألوان"
                subtitle="شعار النادي وألوانه كما تظهر في الموقع وبوابة المشتركات ولوحة النادي"
                actions={<Button variant="outline" href={siteUrl} external icon={<ExternalLink className="size-4" />}>فتح الموقع</Button>}
            />
            <form onSubmit={submit} className="grid max-w-4xl gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="الشعار" subtitle="صورة مربعة، تُحوَّل إلى WebP ويُصغَّر حجمها تلقائيًا" />
                    <div className="flex items-center gap-4">
                        <div className="grid size-24 shrink-0 place-items-center overflow-hidden rounded-2xl border border-dashed border-line bg-canvas">
                            {preview ? <img src={preview} alt="" className="size-full object-cover" /> : <span className="font-display text-3xl" style={{ color: form.data.primary_color }}>{club.name[0]}</span>}
                        </div>
                        <label className="inline-flex cursor-pointer items-center gap-2 rounded-[10px] border border-[#dcdcdc] bg-white px-3 py-2 text-sm hover:bg-[#fafafa]">
                            <ImagePlus className="size-4" /> {preview ? 'تغيير الشعار' : 'رفع شعار'}
                            <input type="file" accept="image/*" className="hidden" onChange={(e) => form.setData('logo', e.target.files?.[0] ?? null)} />
                        </label>
                    </div>
                    {form.errors.logo && <p className="mt-2 text-xs font-medium text-red-600">{form.errors.logo}</p>}
                </Card>
                <Card>
                    <CardHeader title="الألوان" />
                    <div className="grid gap-4 sm:grid-cols-2">
                        {(['primary_color', 'secondary_color'] as const).map((key) => (
                            <Field key={key} label={key === 'primary_color' ? 'اللون الرئيسي' : 'اللون الثانوي'} error={form.errors[key]}>
                                <div className="flex items-center gap-2">
                                    <input type="color" value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} className="h-10 w-12 cursor-pointer rounded-lg border border-line bg-white p-1" aria-label={key} />
                                    <Input dir="ltr" value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} className="font-mono" />
                                </div>
                            </Field>
                        ))}
                    </div>
                    <div className="mt-4 flex items-center gap-2">
                        <span className="rounded-full px-4 py-2 text-sm font-medium text-white" style={{ background: form.data.primary_color }}>اشتركي الآن</span>
                        <span className="rounded-full px-4 py-2 text-sm font-medium" style={{ background: form.data.secondary_color }}>جدول الحصص</span>
                    </div>
                </Card>
                <div className="lg:col-span-2"><Button type="submit" loading={form.processing} disabled={!form.isDirty}>حفظ الهوية</Button></div>
            </form>
        </ClubLayout>
    );
}
