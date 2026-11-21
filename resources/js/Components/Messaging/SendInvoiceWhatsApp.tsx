import { useEffect, useRef, useState } from 'react';
import { MessageCircle } from 'lucide-react';
import { toast } from 'sonner';
import { Button, Field, MemberPicker, Modal, Notice, Select, type MemberHit } from '@/Components/ui';
import { postJson } from '@/lib/http';
import { money } from '@/lib/format';

interface InvoiceOption {
    id: number;
    number: string;
    issue_date: string;
    balance: string;
    currency: string;
    /** A membership waits on it: the message says it starts once paid in full. */
    membership: boolean;
}

interface Preview {
    ok: boolean;
    error: string | null;
    template_key: string | null;
    language: string | null;
    fallback: string | null;
    body: string | null;
}

type Language = 'ar' | 'en';
const LANGUAGES: Record<Language, string> = { ar: 'العربية', en: 'English' };

/**
 * «إرسال الفاتورة عبر واتساب» by hand: the member (given, or chosen), one of her open invoices and the language, the
 * message read as it will leave — the template, the fallback to the approved Arabic one said, its text with that
 * invoice's own values — then sent. The server checks it all again (InvoiceMessage).
 */
export default function SendInvoiceWhatsApp({ open, onClose, member: given, invoiceId }: { open: boolean; onClose: () => void; member?: { id: number; label: string } | null; invoiceId?: number }) {
    const [member, setMember] = useState<{ id: number; label: string } | null>(given ?? null);
    const [invoices, setInvoices] = useState<InvoiceOption[] | null>(null);
    const [invoice, setInvoice] = useState<string>(invoiceId ? String(invoiceId) : '');
    const [language, setLanguage] = useState<Language>('ar');
    const [optIn, setOptIn] = useState(true);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [loading, setLoading] = useState(false);
    const [sending, setSending] = useState(false);
    const ticket = useRef(0);
    // The member's preferred language is the starting choice, never over one the staff made.
    const chose = useRef(false);

    useEffect(() => {
        if (open) {
            setMember(given ?? null);
            setInvoice(invoiceId ? String(invoiceId) : '');
            setPreview(null);
            chose.current = false;
        }
    }, [open]); // eslint-disable-line react-hooks/exhaustive-deps

    // The member's open invoices, and the language she prefers.
    useEffect(() => {
        if (!open || !member) return setInvoices(null);
        let gone = false;
        fetch(route('club.whatsapp.invoice-messages.invoices', { member_id: member.id }), { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d) => {
                if (gone) return;
                setInvoices(d.invoices ?? []);
                if (!chose.current) setLanguage(d.member?.language === 'en' ? 'en' : 'ar');
                setOptIn(Boolean(d.member?.opt_in));
                setInvoice((current) => (current && (d.invoices ?? []).some((i: InvoiceOption) => String(i.id) === current) ? current : d.invoices?.[0] ? String(d.invoices[0].id) : ''));
            })
            .catch(() => !gone && setInvoices([]));
        return () => {
            gone = true;
        };
    }, [open, member]);

    // The message as it will leave, drawn again as the choice changes (an older answer is dropped).
    useEffect(() => {
        if (!open || !member || !invoice) return setPreview(null);
        const mine = ++ticket.current;
        setLoading(true);
        postJson(route('club.whatsapp.invoice-messages.preview'), { member_id: member.id, invoice_id: Number(invoice), language })
            .then(async (r) => {
                const d = await r.json();
                if (mine !== ticket.current) return;
                setPreview(r.ok ? d : { ok: false, error: Object.values(d.errors ?? {}).flat()[0] as string ?? d.message ?? 'تعذّرت المعاينة', template_key: null, language: null, fallback: null, body: null });
            })
            .catch(() => mine === ticket.current && setPreview({ ok: false, error: 'تعذّرت المعاينة', template_key: null, language: null, fallback: null, body: null }))
            .finally(() => mine === ticket.current && setLoading(false));
    }, [open, member, invoice, language]);

    const send = async () => {
        if (!member || !invoice) return;
        setSending(true);
        try {
            const r = await postJson(route('club.whatsapp.invoice-messages.send'), { member_id: member.id, invoice_id: Number(invoice), language });
            const d = await r.json();
            if (r.ok && d.ok) {
                toast.success('أُضيفت الفاتورة إلى قائمة الإرسال عبر واتساب.' + (d.fallback ? ` ${d.fallback}` : ''));
                onClose();
            } else {
                toast.error(d.error ?? (Object.values(d.errors ?? {}).flat()[0] as string) ?? 'لم تُرسل الفاتورة');
            }
        } catch {
            toast.error('لم تُرسل الفاتورة');
        } finally {
            setSending(false);
        }
    };

    const chosen = invoices?.find((i) => String(i.id) === invoice);

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="إرسال الفاتورة عبر واتساب"
            description="العميل والفاتورة واللغة، والرسالة كما ستصل قبل إرسالها. تُعبّأ من الفاتورة المختارة نفسها."
            footer={<>
                <Button variant="outline" onClick={onClose}>إلغاء</Button>
                <Button loading={sending} disabled={!preview?.ok || loading} icon={<MessageCircle />} onClick={send}>إرسال</Button>
            </>}
        >
            <div className="space-y-4">
                <Field label="العميل (عضو)" required>
                    {member ? (
                        <div className="flex h-10 items-center justify-between gap-2 rounded-[10px] border border-line bg-[#fafafa] px-3 text-[13px] font-medium text-ink">
                            <span className="truncate">{member.label}</span>
                            <button type="button" className="text-[12px] text-[#1d4ed8] hover:underline" onClick={() => { setMember(null); setInvoice(''); }}>تغيير</button>
                        </div>
                    ) : (
                        <MemberPicker onSelect={(hit: MemberHit) => { setMember({ id: hit.id, label: `${hit.full_name} · ${hit.member_no}` }); setInvoice(''); }} placeholder="ابحث عن عضو بالاسم أو الهاتف أو الرقم" />
                    )}
                </Field>
                {member && (
                    <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_10rem]">
                        <Field label="الفاتورة" required>
                            <Select value={invoice} onChange={(e) => setInvoice(e.target.value)} disabled={invoices === null}>
                                {invoices === null && <option value="">…</option>}
                                {invoices?.length === 0 && <option value="">لا فواتير مستحقة لهذا العضو</option>}
                                {invoices?.map((i) => <option key={i.id} value={String(i.id)}>{i.number} · {i.issue_date} · المتبقي {money(i.balance, i.currency)}{i.membership ? ' · فيها اشتراك' : ''}</option>)}
                            </Select>
                        </Field>
                        <Field label="اللغة" required>
                            <Select value={language} onChange={(e) => { chose.current = true; setLanguage(e.target.value as Language); }}>
                                {(Object.keys(LANGUAGES) as Language[]).map((l) => <option key={l} value={l}>{LANGUAGES[l]}</option>)}
                            </Select>
                        </Field>
                    </div>
                )}
                {member && !optIn && <Notice tone="amber">العضو لم يوافق على رسائل الخدمة عبر واتساب: لا تُرسل.</Notice>}
                {preview?.fallback && <Notice tone="amber">{preview.fallback}</Notice>}
                {preview && !preview.ok && preview.error && <Notice tone="red">{preview.error}</Notice>}
                {preview?.body && (
                    <div>
                        <p className="mb-1.5 text-[12px] text-[#6b7280]">
                            معاينة الرسالة · القالب <bdi dir="ltr" className="num">{preview.template_key}</bdi> · {preview.language === 'en' ? 'English' : 'العربية'}
                            {chosen && <> · الفاتورة <bdi dir="ltr" className="num">{chosen.number}</bdi></>}
                        </p>
                        <p dir={preview.language === 'en' ? 'ltr' : 'rtl'} className="whitespace-pre-line rounded-2xl rounded-se-sm bg-[#dcf8c6] px-4 py-3 text-[13px] leading-6 text-ink [overflow-wrap:anywhere]" data-testid="invoice-message-preview">{preview.body}</p>
                    </div>
                )}
                {loading && !preview && <p className="text-[12px] text-[#9ca3af]">تُجهَّز المعاينة…</p>}
            </div>
        </Modal>
    );
}
