import { useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import { Camera, Check, HeartPulse, MessageCircle, StickyNote, Tags, UserRound } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { BackLink, Button, Card, Field, FormSection, Input, PageHeader, Select, Textarea, Toggle } from '@/Components/ui';
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
                back={<BackLink href={editing ? route('club.members.show', member.id) : route('club.members.index')}>{editing ? 'ملف العضو' : 'الأعضاء'}</BackLink>}
                title={editing ? `تعديل: ${member.full_name}` : 'تسجيل عضو جديد'}
                subtitle={editing ? 'تحديث البيانات الأساسية والتواصل والموافقات' : 'يُنشأ رقم العضوية تلقائيًا، ثم تُباع العضوية من ملفه أو من نقطة البيع'}
            />

            <form onSubmit={submit} className="mx-auto min-w-0 max-w-3xl space-y-5">
                <FormSection icon={UserRound} title="البيانات الأساسية" hint="الاسم ورقم الواتساب مطلوبان؛ البقية تساعد في التواصل والتقارير">
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
                </FormSection>

                <FormSection icon={Camera} title="الصورة" hint="تظهر في ملف العضو وعند الاستقبال">
                    <div className="flex flex-wrap items-start gap-5">
                        <div className="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-[14px] border border-[#e8e8e8] bg-[#fafafa]">
                            {preview ? <img src={preview} alt="" className="size-full object-cover" /> : <UserRound className="size-8 text-[#d1d5db]" />}
                        </div>
                        <div className="min-w-0 flex-1">
                            <Field error={err.photo} hint="صورة واضحة للوجه، JPG أو PNG">
                                <input
                                    type="file"
                                    accept="image/*"
                                    capture="user"
                                    className="input h-auto py-2 file:me-3 file:rounded-lg file:border-0 file:bg-[#111] file:px-4 file:py-2 file:text-white"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0] ?? null;
                                        form.setData('photo', file);
                                        if (file) setPreview(URL.createObjectURL(file));
                                    }}
                                />
                            </Field>
                        </div>
                    </div>
                </FormSection>

                <FormSection icon={HeartPulse} title="الطوارئ والصحة" hint="الملاحظات الصحية تُحفظ مشفّرة">
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
                </FormSection>

                <FormSection icon={Tags} title="التصنيف" hint="من أين جاء العضو، وفرعه، وحالة حسابه">
                    <div className="grid gap-4 sm:grid-cols-2">
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
                </FormSection>

                <FormSection icon={MessageCircle} title="الموافقات" hint="مطلوبة لإرسال رسائل واتساب">
                    <div className="divide-y divide-line">
                        <Toggle checked={form.data.whatsapp_opt_in} onChange={(v) => form.setData('whatsapp_opt_in', v)} label="رسائل الخدمة عبر واتساب" hint="الفواتير، التذكير بالانتهاء، تأكيد الحجوزات" />
                        <Toggle checked={form.data.marketing_opt_in} onChange={(v) => form.setData('marketing_opt_in', v)} label="الرسائل التسويقية والعروض" hint="لا تُرسل الحملات إلا لمن وافق صراحةً" />
                    </div>
                </FormSection>

                <FormSection icon={StickyNote} title="ملاحظات داخلية" hint="للموظفين فقط، لا تظهر للعضو">
                    <Field error={err.notes}>
                        <Textarea rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </Field>
                </FormSection>

                <Card padded={false} className="mt-6 flex flex-col gap-3 p-4 sm:flex-row sm:justify-end">
                    <Button type="button" variant="outline" className="sm:w-32" href={editing ? route('club.members.show', member.id) : route('club.members.index')}>إلغاء</Button>
                    <Button type="submit" icon={<Check />} className="sm:w-40" loading={form.processing}>{editing ? 'حفظ التغييرات' : 'تسجيل العضو'}</Button>
                </Card>
            </form>
        </ClubLayout>
    );
}
