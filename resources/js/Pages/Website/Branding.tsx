import { type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, CardHeader, Field, Input, PageHeader } from '@/Components/ui';

interface Props {
    club: { name: string; logo_url: string; primary_color: string; secondary_color: string };
    siteUrl: string;
}

export default function WebsiteBranding({ club, siteUrl }: Props) {
    const form = useForm({ primary_color: club.primary_color, secondary_color: club.secondary_color });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('club.website.branding.update'), { preserveScroll: true });
    };

    return (
        <ClubLayout title="الهوية والألوان">
            <PageHeader
                title="الهوية والألوان"
                subtitle="شعار النادي الرسمي وألوان الموقع وبوابة الأعضاء ولوحة النادي"
                actions={<Button variant="outline" href={siteUrl} external icon={<ExternalLink className="size-4" />}>فتح الموقع</Button>}
            />
            <form onSubmit={submit} className="grid max-w-4xl gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader title="الشعار" subtitle="الشعار الرسمي للنادي، معتمد وثابت في النظام" />
                    <div className="flex h-32 items-center justify-center rounded-2xl border border-line bg-canvas p-3">
                        <img src={club.logo_url} alt="شعار Alopera Gym" className="h-full w-auto object-contain" />
                    </div>
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
                        <span className="rounded-full px-4 py-2 text-sm font-medium text-white" style={{ background: form.data.primary_color }}>اشترك الآن</span>
                        <span className="rounded-full px-4 py-2 text-sm font-medium" style={{ background: form.data.secondary_color }}>جدول الحصص</span>
                    </div>
                </Card>
                <div className="lg:col-span-2"><Button type="submit" loading={form.processing} disabled={!form.isDirty}>حفظ الهوية</Button></div>
            </form>
        </ClubLayout>
    );
}
