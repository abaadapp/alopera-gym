import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { Clock, Mail, MapPin, MessageCircle, Phone, Send } from 'lucide-react';
import SiteLayout, { type SiteInfo } from '@/Layouts/SiteLayout';
import { Button, Field, Input, Textarea } from '@/Components/ui';
import { phone as fmtPhone, whatsappUrl } from '@/lib/format';
import { PageHero } from './Plans';

export default function Contact({ site, seo }: { site: SiteInfo; seo: { title: string } }) {
    const form = useForm({ name: '', phone: '', email: '', subject: '', message: '', website: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('site.contact.send'), { preserveScroll: true, onSuccess: () => form.reset() });
    };

    const embed = site.map_url && /\/maps\/embed|output=embed/.test(site.map_url) ? site.map_url : null;

    return (
        <SiteLayout site={site} seoTitle={seo.title}>
            <PageHero title="تواصل معنا" subtitle="يسعدنا الرد على استفساراتك حول الاشتراكات والحصص والعروض." icon={<MessageCircle className="size-6" />} />

            <section className="mx-auto grid max-w-6xl gap-8 px-4 py-12 lg:grid-cols-5">
                <div className="space-y-4 lg:col-span-2">
                    <div className="rounded-3xl border border-line bg-white p-6 shadow-sm">
                        <h2 className="font-display text-lg font-semibold">بيانات التواصل</h2>
                        <ul className="mt-4 space-y-4 text-sm">
                            {site.contact_phone && (
                                <li className="flex items-center gap-3">
                                    <span className="grid size-10 place-items-center rounded-xl bg-brand-50 text-brand"><Phone className="size-4" /></span>
                                    <a href={`tel:+${site.contact_phone}`} className="num hover:text-brand">{fmtPhone(site.contact_phone)}</a>
                                </li>
                            )}
                            {site.whatsapp_number && (
                                <li className="flex items-center gap-3">
                                    <span className="grid size-10 place-items-center rounded-xl bg-emerald-50 text-emerald-600"><MessageCircle className="size-4" /></span>
                                    <a href={whatsappUrl(site.whatsapp_number)} target="_blank" rel="noreferrer" className="hover:text-brand">راسلنا على واتساب</a>
                                </li>
                            )}
                            {site.contact_email && (
                                <li className="flex items-center gap-3">
                                    <span className="grid size-10 place-items-center rounded-xl bg-brand-50 text-brand"><Mail className="size-4" /></span>
                                    <a href={`mailto:${site.contact_email}`} className="hover:text-brand">{site.contact_email}</a>
                                </li>
                            )}
                            {site.address && (
                                <li className="flex items-center gap-3">
                                    <span className="grid size-10 place-items-center rounded-xl bg-brand-50 text-brand"><MapPin className="size-4" /></span>
                                    <span>{site.address}</span>
                                </li>
                            )}
                        </ul>
                    </div>

                    {site.working_hours?.length > 0 && (
                        <div className="rounded-3xl border border-line bg-white p-6 shadow-sm">
                            <h2 className="flex items-center gap-2 font-display text-lg font-semibold"><Clock className="size-5 text-brand" /> أوقات العمل</h2>
                            <ul className="mt-4 divide-y divide-line text-sm">
                                {site.working_hours.map((w, i) => (
                                    <li key={i} className="flex justify-between gap-4 py-2.5"><span className="text-muted">{w.days}</span><span className="font-medium">{w.hours}</span></li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {site.map_url && (
                        <div className="overflow-hidden rounded-3xl border border-line bg-white shadow-sm">
                            {embed ? (
                                <iframe src={embed} title="الموقع على الخريطة" loading="lazy" className="h-64 w-full border-0" referrerPolicy="no-referrer-when-downgrade" />
                            ) : (
                                <a href={site.map_url} target="_blank" rel="noreferrer" className="flex items-center justify-center gap-2 p-5 text-sm font-medium text-brand hover:bg-brand-50">
                                    <MapPin className="size-4" /> افتح الموقع على الخريطة
                                </a>
                            )}
                        </div>
                    )}
                </div>

                <form onSubmit={submit} className="rounded-3xl border border-line bg-white p-6 shadow-sm sm:p-8 lg:col-span-3">
                    <h2 className="font-display text-xl font-semibold">أرسل رسالة</h2>
                    <p className="mt-1 text-sm text-muted">سنعاود التواصل معك في أقرب وقت.</p>
                    <div className="mt-6 grid gap-4 sm:grid-cols-2">
                        <Field label="الاسم" error={form.errors.name} required className="sm:col-span-2">
                            <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoComplete="name" required />
                        </Field>
                        <Field label="رقم الهاتف" error={form.errors.phone}>
                            <Input type="tel" dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} autoComplete="tel" placeholder="9XXXXXXX" />
                        </Field>
                        <Field label="البريد الإلكتروني" error={form.errors.email}>
                            <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoComplete="email" />
                        </Field>
                        <Field label="الموضوع" error={form.errors.subject} className="sm:col-span-2">
                            <Input value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                        </Field>
                        <Field label="الرسالة" error={form.errors.message} required className="sm:col-span-2">
                            <Textarea rows={5} value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} required />
                        </Field>
                        {/* honeypot: hidden from people, filled by bots */}
                        <div className="hidden" aria-hidden="true">
                            <label>Website<input tabIndex={-1} autoComplete="off" value={form.data.website} onChange={(e) => form.setData('website', e.target.value)} /></label>
                        </div>
                    </div>
                    <Button type="submit" className="mt-6 w-full rounded-full sm:w-auto" size="lg" loading={form.processing} icon={<Send className="size-4" />}>
                        إرسال
                    </Button>
                    {form.recentlySuccessful && <p className="mt-3 text-sm text-emerald-600">وصلتنا رسالتك، شكرًا لك.</p>}
                </form>
            </section>
        </SiteLayout>
    );
}
