import { Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { ArrowRight, Camera, UserRound } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Button, Card, CardHeader, Field, Input, PageHeader, Select, Textarea, Toggle } from '@/Components/ui';
import type { Option } from '@/types';
import { useBranchDefault } from '@/hooks/useShared';

interface MemberData {
    id: number;
    full_name: string;
    phone: string;
    phone_alt: string | null;
    email: string | null;
    gender: string | null;
    national_id: string | null;
    nationality: string | null;
    address: string | null;
    emergency_contact_name: string | null;
    emergency_contact_phone: string | null;
    goals: string | null;
    source: string | null;
    status: string;
    notes: string | null;
    whatsapp_opt_in: boolean;
    marketing_opt_in: boolean;
    branch_id: number | null;
    date_of_birth: string | null;
    health_notes: string | null;
    photo_url: string | null;
}

export const SOURCES: Record<string, string> = {
    walk_in: 'زيارة مباشرة',
    website: 'الموقع الإلكتروني',
    instagram: 'إنستغرام',
    tiktok: 'تيك توك',
    referral: 'ترشيح صديق',
    whatsapp: 'واتساب',
    other: 'أخرى',
};

export default function MemberForm({ member, branches }: { member: MemberData | null; branches: Option[] }) {
    const editing = member !== null;
    const [preview, setPreview] = useState<string | null>(member?.photo_url ?? null);

    const branchDefault = useBranchDefault();
    const form = useForm({
        full_name: member?.full_name ?? '',
        phone: member?.phone ?? '',
        phone_alt: member?.phone_alt ?? '',
        email: member?.email ?? '',
        gender: member?.gender ?? 'female',
        date_of_birth: member?.date_of_birth ?? '',
        national_id: member?.national_id ?? '',
        nationality: member?.nationality ?? 'OM',
        address: member?.address ?? '',
        emergency_contact_name: member?.emergency_contact_name ?? '',
        emergency_contact_phone: member?.emergency_contact_phone ?? '',
        health_notes: member?.health_notes ?? '',
        goals: member?.goals ?? '',
        source: member?.source ?? 'walk_in',
        status: member?.status ?? 'active',
        notes: member?.notes ?? '',
        whatsapp_opt_in: member?.whatsapp_opt_in ?? true,
        marketing_opt_in: member?.marketing_opt_in ?? false,
        branch_id: member ? (member.branch_id ? String(member.branch_id) : '') : branchDefault,
        photo: null as File | null,
        _method: editing ? 'put' : 'post',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const url = editing ? route('club.members.update', member.id) : route('club.members.store');
        form.post(url, {
            forceFormData: true,
            // Keep the position only when the form comes back with errors (not on the redirect to the profile).
            preserveScroll: 'errors',
            // On phones the submit button sits far below the fields: bring the first error into view.
            onError: () => requestAnimationFrame(() => document.querySelector('form .text-red-600')?.parentElement?.scrollIntoView({ behavior: 'smooth', block: 'center' })),
        });
    };

    const err = form.errors as Record<string, string | undefined>;

    return (
        <ClubLayout title={editing ? 'تعديل بيانات عضو' : 'عضو جديد'}>
            <PageHeader
                title={editing ? `تعديل: ${member.full_name}` : 'تسجيل عضو جديد'}
                subtitle={editing ? 'تحديث البيانات الأساسية والتواصل والموافقات' : 'سيُنشأ رقم العضوية تلقائيًا، ثم يمكنك بيع العضوية من ملفه'}
                back={
                    <Link href={editing ? route('club.members.show', member.id) : route('club.members.index')} className="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-brand">
                        <ArrowRight className="size-4" /> {editing ? 'ملف العضو' : 'الأعضاء'}
                    </Link>
                }
            />

            <form onSubmit={submit} className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <Card>
                        <CardHeader title="البيانات الأساسية" />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="الاسم الكامل" error={err.full_name} required className="sm:col-span-2">
                                <Input value={form.data.full_name} onChange={(e) => form.setData('full_name', e.target.value)} required autoFocus={!editing} />
                            </Field>
                            <Field label="رقم الهاتف (واتساب)" error={err.phone} required hint="الرقم المحلي يكفي، مثل 9123 4567">
                                <Input dir="ltr" inputMode="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} required />
                            </Field>
                            <Field label="هاتف إضافي" error={err.phone_alt}>
                                <Input dir="ltr" inputMode="tel" value={form.data.phone_alt} onChange={(e) => form.setData('phone_alt', e.target.value)} />
                            </Field>
                            <Field label="البريد الإلكتروني" error={err.email}>
                                <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                            </Field>
                            <Field label="تاريخ الميلاد" error={err.date_of_birth}>
                                <Input type="date" value={form.data.date_of_birth} onChange={(e) => form.setData('date_of_birth', e.target.value)} />
                            </Field>
                            <Field label="الرقم المدني / الهوية" error={err.national_id}>
                                <Input dir="ltr" value={form.data.national_id} onChange={(e) => form.setData('national_id', e.target.value)} />
                            </Field>
                            <Field label="الجنسية (رمز الدولة)" error={err.nationality} hint="مثل OM أو SA">
                                <Input dir="ltr" maxLength={2} value={form.data.nationality} onChange={(e) => form.setData('nationality', e.target.value.toUpperCase())} />
                            </Field>
                            <Field label="العنوان" error={err.address} className="sm:col-span-2">
                                <Input value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                            </Field>
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="الطوارئ والصحة" subtitle="الملاحظات الصحية تُحفظ مشفّرة" />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="اسم جهة اتصال الطوارئ" error={err.emergency_contact_name}>
                                <Input value={form.data.emergency_contact_name} onChange={(e) => form.setData('emergency_contact_name', e.target.value)} />
                            </Field>
                            <Field label="هاتف الطوارئ" error={err.emergency_contact_phone}>
                                <Input dir="ltr" inputMode="tel" value={form.data.emergency_contact_phone} onChange={(e) => form.setData('emergency_contact_phone', e.target.value)} />
                            </Field>
                            <Field label="ملاحظات صحية" error={err.health_notes} className="sm:col-span-2">
                                <Textarea rows={3} value={form.data.health_notes} onChange={(e) => form.setData('health_notes', e.target.value)} placeholder="إصابات، أمراض مزمنة، حساسية…" />
                            </Field>
                            <Field label="الأهداف" error={err.goals} className="sm:col-span-2">
                                <Textarea rows={2} value={form.data.goals} onChange={(e) => form.setData('goals', e.target.value)} placeholder="إنقاص الوزن، لياقة عامة، تقوية…" />
                            </Field>
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="ملاحظات داخلية" />
                        <Field error={err.notes}>
                            <Textarea rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </Field>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card>
                        <CardHeader title="الصورة" />
                        <div className="flex flex-col items-center gap-3">
                            {preview ? (
                                <img src={preview} alt="" className="size-28 rounded-2xl object-cover" />
                            ) : (
                                <span className="grid size-28 place-items-center rounded-2xl bg-brand-50 text-brand"><UserRound className="size-10" /></span>
                            )}
                            <label className="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-line px-3 py-2 text-sm hover:bg-brand-50">
                                <Camera className="size-4" /> اختيار صورة
                                <input
                                    type="file"
                                    accept="image/*"
                                    capture="user"
                                    className="hidden"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] ?? null;
                                        form.setData('photo', file);
                                        if (file) setPreview(URL.createObjectURL(file));
                                    }}
                                />
                            </label>
                            {err.photo && <p className="text-xs text-red-600">{err.photo}</p>}
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="التصنيف" />
                        <div className="space-y-4">
                            <Field label="مصدر التسجيل" error={err.source}>
                                <Select value={form.data.source} onChange={(e) => form.setData('source', e.target.value)}>
                                    {Object.entries(SOURCES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                                </Select>
                            </Field>
                            {branches.length > 1 && (
                                <Field label="الفرع" error={err.branch_id}>
                                    <Select value={form.data.branch_id} onChange={(e) => form.setData('branch_id', e.target.value)} placeholder="—">
                                        {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
                                    </Select>
                                </Field>
                            )}
                            {editing && (
                                <Field label="حالة الحساب" error={err.status} hint="«موقوف» يمنع الدخول والحجز وبيع العضويات">
                                    <Select value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                        <option value="active">نشط</option>
                                        <option value="inactive">غير نشط</option>
                                        <option value="blocked">موقوف</option>
                                    </Select>
                                </Field>
                            )}
                        </div>
                    </Card>

                    <Card>
                        <CardHeader title="الموافقات" subtitle="مطلوبة لإرسال رسائل واتساب" />
                        <div className="space-y-3">
                            <Toggle
                                checked={form.data.whatsapp_opt_in}
                                onChange={(v) => form.setData('whatsapp_opt_in', v)}
                                label="رسائل الخدمة عبر واتساب"
                                hint="الفواتير، التذكير بالانتهاء، تأكيد الحجوزات"
                            />
                            <Toggle
                                checked={form.data.marketing_opt_in}
                                onChange={(v) => form.setData('marketing_opt_in', v)}
                                label="الرسائل التسويقية والعروض"
                                hint="لا تُرسل الحملات إلا لمن وافق صراحةً"
                            />
                        </div>
                    </Card>

                    <div className="flex gap-2">
                        <Button type="submit" className="flex-1" loading={form.processing}>{editing ? 'حفظ التعديلات' : 'تسجيل العضو'}</Button>
                        <Button type="button" variant="outline" href={editing ? route('club.members.show', member.id) : route('club.members.index')}>إلغاء</Button>
                    </div>
                </div>
            </form>
        </ClubLayout>
    );
}
