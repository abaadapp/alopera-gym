import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Info, Paperclip, Plus, RefreshCw, Save, Search, Send, Settings2, Trash2, UserPlus, UserRound, Users } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import PaperFrame from '@/Components/Papers/PaperFrame';
import { clientKey } from '@/Components/Inventory/parts';
import { BackLink, Button, Card, Field, FileInput, Input, MemberPicker, Modal, PageHeader, Select, Textarea, type MemberHit } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { currencyLabel, money } from '@/lib/format';
import { postJson } from '@/lib/http';
import { cn } from '@/lib/cn';

interface Product {
    id: number;
    name: string;
    sku: string | null;
    barcode: string | null;
    price: string;
    track_stock: boolean;
    stock: number | null;
}

interface Line {
    product_id: string;
    description: string;
    quantity: string;
    unit_price: string;
    discount: string;
}

type Mode = 'member' | 'new' | 'walk_in';
type Method = 'credit' | 'cash' | 'card' | 'bank_transfer';
/** The face of the paper: Arabic first (right to left) or English first (left to right). */
type PaperLang = 'ar' | 'en';

interface Draft {
    id: number;
    customer_mode: Mode;
    member: { id: number; label: string } | null;
    customer_name: string;
    customer_phone: string;
    customer_email: string;
    issue_date: string;
    due_date: string | null;
    payment_terms_days: number | null;
    notes: string;
    internal_notes: string;
    paper_lang: PaperLang | null;
    lines: Line[];
    attachments: { id: number; name: string; size: number; url: string }[];
    updated_at: string | null;
}

interface Props {
    products: Product[];
    catalog_truncated: boolean;
    tax_rate: number;
    due_days: number;
    today: string;
    terms: number[];
    paper_lang: PaperLang;
    methods: Method[];
    may: { issue: boolean; pay: boolean; design: boolean };
    draft?: Draft;
    /** The paper as the editor opens: drawn by the server with the form's first values, nothing on it yet. */
    paper: Paper;
}

type Totals = { subtotal: string; discount_total: string; tax_rate: string; tax_total: string; total: string };
type Paper = { html: string; size: string; totals: Totals };

const CUSTOM = 'custom';
const METHOD_LABEL: Record<Method, string> = { credit: 'آجل — لا يُقبض الآن', cash: 'مدفوع نقدًا', card: 'مدفوع بالبطاقة (جهاز الشبكة)', bank_transfer: 'تحويل بنكي' };
const ACCEPT = '.jpg,.jpeg,.png,.pdf,.webp,.heic';
const N = (v: string) => (Number.isFinite(Number(v)) ? Number(v) : 0);
const blank = (): Line => ({ product_id: '', description: '', quantity: '1', unit_price: '0', discount: '0' });
/** A line's columns where it has a row of its own: number, description, quantity, price, discount, total, delete. */
const LINE_GRID = 'grid gap-x-2 gap-y-2 @[42rem]:grid-cols-[1.25rem_minmax(0,1fr)_5.5rem_7.5rem_7rem_6.5rem_2.25rem]';

function addDays(from: string, days: number): string {
    const d = new Date(`${from}T00:00:00`);
    if (Number.isNaN(d.getTime())) return from;
    d.setDate(d.getDate() + days);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/**
 * إنشاء فاتورة — the invoice editor: the details on one side and, beside them, the invoice paper as it will be issued,
 * redrawn by the server as the form changes (the same arithmetic, tax and paper). The customer is a member, a new
 * customer typed in, or a walk-in; the lines are typed or taken from the catalogue (a product leaves the shelf when the
 * invoice is issued); the money taken now is recorded with it. «حفظ كمسودة» keeps the work as a real draft — no number,
 * nothing owed, nothing in the books — to reopen from the drafts tab; «إصدار الفاتورة» numbers and issues it. The
 * buttons sit in the header, in sight however long the invoice grows.
 *
 * The paper is there from the first moment, the whole sheet with nothing on it yet, and follows every change. Its face —
 * Arabic or English (left to right) — is chosen beside it and kept with the invoice, so it prints as it was previewed.
 */
export default function InvoiceCreate({ products, catalog_truncated, tax_rate, due_days, today, terms, paper_lang, methods, may, draft, paper: firstPaper }: Props) {
    const currency = useCurrency();
    const symbol = currencyLabel(currency);
    const m = (v: string | number) => money(v, currency);
    const startTerms = draft ? (draft.payment_terms_days !== null && terms.includes(draft.payment_terms_days) ? String(draft.payment_terms_days) : CUSTOM) : terms.includes(due_days) ? String(due_days) : CUSTOM;
    const [lang, setLang] = useState<PaperLang>(draft?.paper_lang ?? paper_lang);
    const [lines, setLines] = useState<Line[]>(draft?.lines.length ? draft.lines : [blank()]);
    const [member, setMember] = useState<{ id: number; label: string; phone?: string; membership?: string | null } | null>(draft?.member ?? null);
    const [internal, setInternal] = useState(Boolean(draft?.internal_notes));
    const [picking, setPicking] = useState(false);
    const [discarding, setDiscarding] = useState(false);
    const [kept, setKept] = useState(draft?.attachments ?? []);
    const dueRef = useRef<HTMLInputElement>(null);

    const form = useForm({
        customer_mode: (draft?.customer_mode ?? 'member') as Mode,
        customer_name: draft?.customer_name ?? '',
        customer_phone: draft?.customer_phone ?? '',
        customer_email: draft?.customer_email ?? '',
        issue_date: draft?.issue_date ?? today,
        due_date: draft?.due_date ?? addDays(today, due_days),
        payment_terms_days: startTerms,
        notes: draft?.notes ?? '',
        internal_notes: draft?.internal_notes ?? '',
        attachments: [] as File[],
        remove_attachments: [] as number[],
        payment_method: 'credit' as Method,
        paid_amount: '',
        payment_date: today,
        payment_reference: '',
        payment_proof: null as File | null,
    });
    const d = form.data;
    const errors = form.errors as Record<string, string | undefined>;
    const credit = d.payment_method === 'credit';

    // ── Payment terms and the due date: the date follows the terms until it is typed by hand. ──
    const setTerms = (value: string) => {
        if (value === CUSTOM) {
            form.setData('payment_terms_days', CUSTOM);
            window.setTimeout(() => dueRef.current?.focus(), 0);
            return;
        }
        form.setData((x) => ({ ...x, payment_terms_days: value, due_date: addDays(x.issue_date || today, N(value)) }));
    };
    const setIssued = (value: string) => form.setData((x) => ({ ...x, issue_date: value, due_date: x.payment_terms_days === CUSTOM ? x.due_date : addDays(value || today, N(x.payment_terms_days)) }));

    // ── The lines ──
    const setLine = (i: number, patch: Partial<Line>) => setLines((prev) => prev.map((l, k) => (k === i ? { ...l, ...patch } : l)));
    // As the server works it: the price in baisa times the quantity, rounded, less the discount (InvoiceService::draft).
    const lineGross = (l: Line) => Math.round(Math.round(N(l.unit_price) * 1000) * N(l.quantity));
    const lineNet = (l: Line) => Math.max(0, lineGross(l) - Math.round(N(l.discount) * 1000)) / 1000;
    const overDiscount = (l: Line) => Math.round(N(l.discount) * 1000) > lineGross(l);
    const pickProduct = (p: Product) =>
        setLines((prev) => {
            const line: Line = { product_id: String(p.id), description: p.name, quantity: '1', unit_price: Number(p.price).toFixed(3), discount: '0' };
            const empty = prev.length === 1 && prev[0].description === '' && prev[0].product_id === '';
            return empty ? [line] : [...prev, line];
        });
    const productOf = (l: Line) => (l.product_id ? products.find((p) => String(p.id) === l.product_id) : undefined);

    // ── What the server is sent: who is billed, as the mode says, and the lines. ──
    const payload = useMemo(() => ({
        customer_mode: d.customer_mode,
        member_id: d.customer_mode === 'member' ? (member?.id ?? null) : null,
        customer_name: d.customer_mode === 'member' ? null : d.customer_name || null,
        customer_phone: d.customer_mode === 'new' ? d.customer_phone || null : null,
        customer_email: d.customer_mode === 'new' ? d.customer_email || null : null,
        issue_date: d.issue_date,
        due_date: d.due_date || null,
        payment_terms_days: d.payment_terms_days === CUSTOM ? null : Number(d.payment_terms_days),
        notes: d.notes || null,
        internal_notes: internal ? d.internal_notes || null : null,
        paper_lang: lang,
        lines: lines.map((l) => ({ product_id: l.product_id || null, description: l.description, quantity: l.quantity || 0, unit_price: l.unit_price || 0, discount: l.discount || 0 })),
    }), [d.customer_mode, member, d.customer_name, d.customer_phone, d.customer_email, d.issue_date, d.due_date, d.payment_terms_days, d.notes, d.internal_notes, internal, lang, lines]);

    // ── The live paper and its totals: the server's first one, then redrawn a moment after typing stops (the same form
    // is not drawn twice; an answer to an older request is dropped). ──
    const [paper, setPaper] = useState<{ html: string; size: string }>({ html: firstPaper.html, size: firstPaper.size });
    const [totals, setTotals] = useState<Totals>(firstPaper.totals);
    const [drawing, setDrawing] = useState(false);
    const ticket = useRef(0);
    const drawn = useRef(JSON.stringify(payload));
    const draw = useCallback(async () => {
        const sent = JSON.stringify(payload);
        if (sent === drawn.current) return;
        const mine = ++ticket.current;
        setDrawing(true);
        try {
            const res = await postJson(route('club.invoices.preview'), { ...payload, internal_notes: null });
            const body = await res.json();
            if (mine !== ticket.current || !res.ok) return;
            drawn.current = sent;
            setPaper({ html: body.html, size: body.size });
            setTotals(body.totals);
        } catch {
            // offline or refused: the form still saves; the last paper stays until the next change
        } finally {
            if (mine === ticket.current) setDrawing(false);
        }
    }, [payload]);
    useEffect(() => {
        const id = window.setTimeout(draw, 400);
        return () => window.clearTimeout(id);
    }, [draw]);

    const total = Number(totals?.total ?? 0);
    const paidNow = credit ? 0 : d.paid_amount === '' ? total : Math.min(N(d.paid_amount), total);

    // One key per submission: a double click or a retry saves or issues once (SubmittedOnce); a new one after success.
    const key = useRef(clientKey());
    const submit = (issue: boolean) => {
        form.transform((x) => ({
            ...payload,
            client_key: key.current,
            issue: issue ? 1 : 0,
            attachments: x.attachments,
            remove_attachments: x.remove_attachments,
            ...(issue && !credit ? { payment_method: x.payment_method, paid_amount: x.paid_amount, payment_date: x.payment_date, payment_reference: x.payment_reference, payment_proof: x.payment_proof } : { payment_method: 'credit' }),
        }));
        const files = d.attachments.length > 0 || (issue && !credit && d.payment_proof !== null);
        form.post(draft ? route('club.invoices.drafts.update', draft.id) : route('club.invoices.store'), { preserveScroll: true, forceFormData: files, onSuccess: () => (key.current = clientKey()) });
    };

    const addFiles = (list: FileList | null) => {
        if (!list) return;
        form.setData('attachments', [...d.attachments, ...Array.from(list)].slice(0, Math.max(0, 6 - kept.length)));
    };
    const lineError = (i: number) => ['description', 'quantity', 'unit_price', 'discount', 'product_id'].map((f) => errors[`lines.${i}.${f}`]).find(Boolean);

    return (
        <ClubLayout title={draft ? 'مسودة فاتورة' : 'إنشاء فاتورة'}>
            <PageHeader
                back={<BackLink href={route('club.invoices.index', draft ? { state: 'draft' } : {})}>فواتير العملاء</BackLink>}
                title={draft ? 'مسودة فاتورة عميل' : 'إنشاء فاتورة عميل جديدة'}
                subtitle={draft?.updated_at ? <>آخر حفظ <span className="num">{draft.updated_at}</span> — تُرقَّم عند الإصدار.</> : 'إصدار فاتورة لعضو أو عميل مقابل منتجات أو خدمات.'}
                actions={<>
                    <span className="rounded-full bg-[#eff6ff] px-3 py-1 text-[12px] font-medium text-[#1d4ed8]">مسودة</span>
                    <Button variant="outline" disabled={form.processing} onClick={() => router.visit(route('club.invoices.index', draft ? { state: 'draft' } : {}))}>إلغاء</Button>
                    {draft && <Button variant="outline" className="text-[#b91c1c]" disabled={form.processing} icon={<Trash2 />} onClick={() => setDiscarding(true)}>حذف المسودة</Button>}
                    {/* A draft carries no money: with a payment chosen, only issuing records it. */}
                    {(credit || !may.issue) && <Button variant="outline" disabled={form.processing} icon={<Save />} onClick={() => submit(false)}>حفظ كمسودة</Button>}
                    {may.issue && <Button loading={form.processing} icon={<Send />} onClick={() => submit(true)}>إصدار الفاتورة</Button>}
                </>}
            />

            {Object.keys(errors).length > 0 && (
                <p className="mb-4 rounded-[10px] border border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]" role="alert">
                    راجع الحقول المعلَّمة: {Object.values(errors).filter(Boolean).slice(0, 2).join(' · ')}
                </p>
            )}

            <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                <div className="min-w-0 space-y-4">
                    {/* ── معلومات الفاتورة ── */}
                    <Card className="p-5">
                        <h2 className="mb-4 text-[15px] font-bold text-ink">معلومات الفاتورة</h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <Field label="العميل" required error={errors.member_id ?? errors.customer_name ?? errors.customer_mode}>
                                {d.customer_mode === 'member' && member ? (
                                    <div className="flex h-10 items-center rounded-[10px] border border-line bg-[#fafafa] px-3"><span className="truncate text-[13px] font-medium text-ink">{member.label}</span></div>
                                ) : d.customer_mode === 'member' ? (
                                    <MemberPicker onSelect={(hit: MemberHit) => setMember({ id: hit.id, label: `${hit.full_name} · ${hit.member_no}`, phone: hit.phone_display, membership: hit.membership ? `${hit.membership.plan} حتى ${hit.membership.ends_on}` : null })} placeholder="ابحث عن عضو بالاسم أو الهاتف أو الرقم" />
                                ) : (
                                    <div className="flex h-10 items-center gap-2 rounded-[10px] border border-line bg-[#fafafa] px-3 text-[13px] font-medium text-ink">
                                        {d.customer_mode === 'new' ? <UserPlus className="size-4 text-[#6b7280]" /> : <Users className="size-4 text-[#6b7280]" />}
                                        {d.customer_mode === 'new' ? 'عميل جديد' : 'عميل زائر'}
                                    </div>
                                )}
                                <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                    {d.customer_mode !== 'member' && <ModeLink icon={<UserRound />} onClick={() => form.setData('customer_mode', 'member')}>اختيار عضو</ModeLink>}
                                    {d.customer_mode !== 'new' && <ModeLink icon={<UserPlus />} onClick={() => form.setData('customer_mode', 'new')}>عميل جديد</ModeLink>}
                                    {d.customer_mode !== 'walk_in' && <ModeLink icon={<Users />} onClick={() => form.setData('customer_mode', 'walk_in')}>عميل زائر</ModeLink>}
                                </div>
                            </Field>
                            <Field label="تاريخ الإصدار" required error={errors.issue_date}>
                                <Input type="date" value={d.issue_date} max={today} onChange={(e) => setIssued(e.target.value)} />
                            </Field>
                            <Field label={<span className="flex items-center gap-1.5">رقم الفاتورة <Info className="size-3.5 text-[#9ca3af]" /></span>}>
                                <div className="flex h-10 items-center rounded-[10px] border border-dashed border-line bg-[#fafafa] px-3 text-[13px] text-[#9ca3af]">يُنشأ رقم الفاتورة عند الإصدار</div>
                            </Field>
                            <Field label="تاريخ الاستحقاق" required error={errors.due_date} hint={d.payment_terms_days === CUSTOM ? 'مكتوب يدويًا' : 'محسوب من شروط الدفع'}>
                                <Input ref={dueRef} type="date" value={d.due_date} min={d.issue_date} onChange={(e) => form.setData((x) => ({ ...x, due_date: e.target.value, payment_terms_days: CUSTOM }))} />
                            </Field>
                            <Field label="شروط الدفع" error={errors.payment_terms_days}>
                                <Select value={d.payment_terms_days} onChange={(e) => setTerms(e.target.value)}>
                                    {terms.map((t) => <option key={t} value={String(t)}>{t === 0 ? 'مستحق فورًا' : `${t} ${t <= 10 ? 'أيام' : 'يومًا'}`}</option>)}
                                    <option value={CUSTOM}>تاريخ مخصص</option>
                                </Select>
                            </Field>
                            <Field label="العملة">
                                <div className="flex h-10 items-center rounded-[10px] border border-line bg-[#fafafa] px-3 text-[13px] text-[#4b4b4b]">{symbol} — {({ OMR: 'ريال عُماني', SAR: 'ريال سعودي', AED: 'درهم إماراتي', KWD: 'دينار كويتي', QAR: 'ريال قطري', BHD: 'دينار بحريني' } as Record<string, string>)[currency] ?? currency}</div>
                            </Field>
                        </div>

                        {/* The customer as it will be billed. */}
                        {d.customer_mode === 'member' && member && (
                            <div className="mt-3 rounded-[12px] border border-line bg-[#fafafa] p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-[15px] font-bold text-ink">{member.label}</p>
                                        <span className="mt-1 inline-block rounded-full bg-[#eff6ff] px-2 py-0.5 text-[11px] text-[#1d4ed8]">عضو</span>
                                    </div>
                                    <Button variant="outline" size="sm" onClick={() => setMember(null)}>تغيير</Button>
                                </div>
                                {(member.phone || member.membership) && (
                                    <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-[#6b7280]">
                                        {member.phone && <li className="num" dir="ltr">{member.phone}</li>}
                                        {member.membership && <li>{member.membership}</li>}
                                    </ul>
                                )}
                            </div>
                        )}
                        {d.customer_mode === 'new' && (
                            <div className="mt-3 grid grid-cols-1 gap-3 rounded-[12px] border border-line bg-[#fafafa] p-4 sm:grid-cols-3">
                                <Field label="اسم العميل" required error={errors.customer_name}><Input value={d.customer_name} onChange={(e) => form.setData('customer_name', e.target.value)} maxLength={160} /></Field>
                                <Field label="الهاتف" error={errors.customer_phone}><Input dir="ltr" inputMode="tel" value={d.customer_phone} onChange={(e) => form.setData('customer_phone', e.target.value)} maxLength={32} /></Field>
                                <Field label="البريد" error={errors.customer_email}><Input dir="ltr" type="email" value={d.customer_email} onChange={(e) => form.setData('customer_email', e.target.value)} maxLength={160} /></Field>
                            </div>
                        )}
                        {d.customer_mode === 'walk_in' && (
                            <div className="mt-3 rounded-[12px] border border-line bg-[#fafafa] p-4">
                                <Field label="الاسم على الفاتورة" hint="اختياري — يُكتب «عميل نقدي» إن تُرك فارغًا." error={errors.customer_name}><Input value={d.customer_name} onChange={(e) => form.setData('customer_name', e.target.value)} placeholder="عميل نقدي" maxLength={160} /></Field>
                            </div>
                        )}
                    </Card>

                    {/* ── الأصناف ── */}
                    <Card className="p-5">
                        <div className="mb-4 flex flex-wrap items-center gap-2">
                            <h2 className="me-auto text-[15px] font-bold text-ink">الأصناف</h2>
                            {products.length > 0 && <Button variant="outline" size="sm" icon={<Search />} onClick={() => setPicking(true)}>منتج من الكتالوج</Button>}
                            <Button size="sm" icon={<Plus />} onClick={() => setLines((p) => [...p, blank()])} disabled={lines.length >= 50}>إضافة صنف</Button>
                            <Button variant="outline" size="sm" className="text-[#b91c1c]" title="تفريغ كل البنود" aria-label="تفريغ كل البنود" icon={<Trash2 />} onClick={() => setLines([blank()])} />
                        </div>
                        {/* The lines fit the card at any width: one row each where there is room (a header over them), else
                            the description on one row and the quantity, price, discount and total under it. */}
                        <div className="@container text-[13px]">
                            <div className={cn(LINE_GRID, 'hidden border-b border-line pb-2 text-[12px] text-[#71717a] @[42rem]:grid')} aria-hidden>
                                <span>#</span>
                                <span>الوصف</span>
                                <span>الكمية</span>
                                <span>سعر الوحدة ({symbol})</span>
                                <span>الخصم ({symbol})</span>
                                <span>الإجمالي ({symbol})</span>
                                <span />
                            </div>
                            {lines.map((line, i) => {
                                const product = productOf(line);
                                const short = product?.track_stock && product.stock !== null && N(line.quantity) > product.stock;
                                return (
                                    <div key={i} className={cn(LINE_GRID, 'grid-cols-[1.25rem_minmax(0,1fr)_2.25rem] items-start border-b border-line py-3 last:border-b-0')}>
                                        <span className="pt-2.5 text-[#9ca3af] num">{i + 1}</span>
                                        <div className="min-w-0">
                                            <Input value={line.description} onChange={(e) => setLine(i, { description: e.target.value })} placeholder="الوصف" maxLength={250} aria-label={`وصف البند ${i + 1}`} />
                                            {product && (
                                                <p className={cn('mt-1 text-[11px]', short ? 'text-[#b91c1c]' : 'text-[#9ca3af]')}>
                                                    من الكتالوج{product.track_stock ? <> · المتوفر <span className="num">{product.stock}</span>{short && ' — أقل من الكمية'}</> : ' · خدمة بلا مخزون'}
                                                </p>
                                            )}
                                            {lineError(i) ? <p className="mt-1 text-[12px] text-[#b91c1c]">{lineError(i)}</p> : overDiscount(line) && <p className="mt-1 text-[12px] text-[#b91c1c]">الخصم أكبر من قيمة البند.</p>}
                                        </div>
                                        <div className="col-start-3 row-start-1 @[42rem]:col-start-7">
                                            <Button variant="outline" size="sm" className="h-10 w-9 px-0 text-[#b91c1c]" title="حذف البند" aria-label={`حذف البند ${i + 1}`} icon={<Trash2 />} disabled={lines.length === 1} onClick={() => setLines((p) => p.filter((_, k) => k !== i))} />
                                        </div>
                                        <div className="col-[2/-1] grid grid-cols-2 gap-2 @[26rem]:grid-cols-4 @[42rem]:contents">
                                            <LineField label="الكمية"><Input dir="ltr" inputMode="decimal" value={line.quantity} onChange={(e) => setLine(i, { quantity: e.target.value })} aria-label={`كمية البند ${i + 1}`} /></LineField>
                                            <LineField label={`سعر الوحدة (${symbol})`}><Input dir="ltr" inputMode="decimal" value={line.unit_price} onChange={(e) => setLine(i, { unit_price: e.target.value })} aria-label={`سعر البند ${i + 1}`} /></LineField>
                                            <LineField label={`الخصم (${symbol})`}><Input dir="ltr" inputMode="decimal" value={line.discount} onChange={(e) => setLine(i, { discount: e.target.value })} aria-label={`خصم البند ${i + 1}`} /></LineField>
                                            <LineField label={`الإجمالي (${symbol})`}><span className="flex h-10 items-center font-semibold tabular-nums text-ink" aria-label={`إجمالي البند ${i + 1}`}>{lineNet(line).toFixed(3)}</span></LineField>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                        <Button variant="outline" size="sm" className="mt-3" icon={<Plus />} onClick={() => setLines((p) => [...p, blank()])} disabled={lines.length >= 50}>إضافة بند</Button>
                        {errors.lines && <p className="mt-2 text-[12px] text-[#b91c1c]">{errors.lines}</p>}
                        {tax_rate > 0 && <p className="mt-3 text-[12px] text-[#9ca3af]">ضريبة القيمة المضافة <span className="num">{tax_rate}%</span> على الفاتورة كلها بعد الخصم، من إعدادات النادي.</p>}
                    </Card>

                    {/* ── ملخص الفاتورة ── */}
                    <Card className="p-5">
                        <h2 className="mb-4 text-[15px] font-bold text-ink">ملخص الفاتورة</h2>
                        <dl className="space-y-2 text-[13px]">
                            <SumRow label="قيمة الأصناف" value={m(totals?.subtotal ?? 0)} />
                            <SumRow label="الخصم" value={m(totals?.discount_total ?? 0)} />
                            <SumRow label={`الضريبة${Number(totals?.tax_rate ?? tax_rate) > 0 ? ` (${Number(totals?.tax_rate ?? tax_rate)}%)` : ''}`} value={m(totals?.tax_total ?? 0)} />
                        </dl>
                        <div className="mt-3 flex items-center justify-between rounded-[10px] bg-[#f5f3ff] px-3 py-2.5">
                            <span className="text-[14px] font-bold text-ink">الإجمالي</span>
                            <span className="text-[16px] font-bold tabular-nums text-[#6d28d9]">{m(total)}</span>
                        </div>
                        <dl className="mt-3 space-y-2 text-[13px]">
                            <SumRow label="المدفوع عند الإصدار" value={m(paidNow)} />
                            <SumRow label="المتبقي على العميل" value={m(Math.max(0, total - paidNow))} strong />
                        </dl>
                    </Card>

                    {/* ── معلومات الدفع ── */}
                    <Card className="p-5">
                        <h2 className="mb-4 text-[15px] font-bold text-ink">معلومات الدفع</h2>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <span className="block text-[13px] text-[#4b4b4b]">طريقة الدفع <span className="text-[#b91c1c]">*</span></span>
                                <div className="space-y-2.5 pt-1" role="radiogroup" aria-label="طريقة الدفع">
                                    {methods.map((value) => (
                                        <label key={value} className="flex items-center gap-2 text-[13px]">
                                            <input type="radio" name="payment_method" className="size-4 accent-[#111]" checked={d.payment_method === value} onChange={() => form.setData('payment_method', value)} />
                                            {METHOD_LABEL[value]}
                                        </label>
                                    ))}
                                </div>
                                {!may.pay && <p className="pt-1 text-[12px] text-[#9ca3af]">تسجيل الدفعات صلاحية لا تملكها — تصدر الفاتورة آجلة ويُحصَّل المبلغ لاحقًا.</p>}
                            </div>
                            {!credit && (
                                <div className="space-y-3">
                                    <Field label={`المبلغ المدفوع (${symbol})`} error={errors.paid_amount} hint="اتركه فارغًا لتسجيل المبلغ كاملًا. وما دونه يترك الباقي ذمّةً على العميل.">
                                        <Input dir="ltr" inputMode="decimal" value={d.paid_amount} onChange={(e) => form.setData('paid_amount', e.target.value)} placeholder={total.toFixed(3)} />
                                    </Field>
                                    <Field label="تاريخ الدفع" error={errors.payment_date}><Input type="date" max={today} value={d.payment_date} onChange={(e) => form.setData('payment_date', e.target.value)} /></Field>
                                    {d.payment_method !== 'cash' && (
                                        <Field label="رقم المرجع" error={errors.payment_reference}><Input dir="ltr" value={d.payment_reference} onChange={(e) => form.setData('payment_reference', e.target.value)} placeholder={d.payment_method === 'card' ? 'رقم العملية من جهاز الشبكة' : 'رقم التحويل'} maxLength={120} /></Field>
                                    )}
                                    <Field label="إثبات الدفع" error={errors.payment_proof} hint="يُحفظ مع الدفعة (صورة أو PDF).">
                                        <FileInput accept={ACCEPT} file={d.payment_proof} onChange={(f) => form.setData('payment_proof', f)} />
                                    </Field>
                                </div>
                            )}
                        </div>
                        <p className="mt-4 rounded-[10px] bg-[#f5f3ff] p-3 text-[12px] leading-relaxed text-[#5b21b6]">
                            {credit
                                ? 'يُسجَّل المبلغ المستحق ذمّةً على العميل بعد إصدار الفاتورة.'
                                : d.payment_method === 'bank_transfer'
                                    ? 'يُسجَّل التحويل عند الإصدار بانتظار تأكيد المالية بعد ظهوره في كشف الحساب — ولا يُسجَّل على مسودة.'
                                    : 'يُسجَّل إيصال تحصيل بالمبلغ المدخل عند إصدار الفاتورة — ولا يُسجَّل على مسودة.'}
                        </p>
                        {errors.payment_method && <p className="mt-2 text-[12px] text-[#b91c1c]">{errors.payment_method}</p>}
                    </Card>

                    {/* ── الملاحظات ── */}
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <Card className="p-5">
                            <h2 className="mb-1 text-[15px] font-bold text-ink">ملاحظة للعميل</h2>
                            <p className="mb-3 text-[12px] text-[#9ca3af]">تظهر في الفاتورة المطبوعة.</p>
                            <Textarea value={d.notes} maxLength={500} rows={4} onChange={(e) => form.setData('notes', e.target.value)} placeholder="ستظهر هذه الملاحظة في الفاتورة…" aria-label="ملاحظة للعميل" />
                            <p className="mt-1 text-[12px] text-[#9ca3af]" dir="ltr">{d.notes.length}/500</p>
                            {errors.notes && <p className="text-[12px] text-[#b91c1c]">{errors.notes}</p>}
                        </Card>
                        <Card className="p-5">
                            <div className="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-[15px] font-bold text-ink">ملاحظات إضافية</h2>
                                    <p className="mt-1 text-[12px] text-[#9ca3af]">لا تظهر للعميل ولا تُطبع.</p>
                                </div>
                                <label className="flex shrink-0 cursor-pointer items-center gap-2 text-[12px] text-[#4b4b4b]">
                                    <input type="checkbox" className="size-4 accent-[#111]" checked={internal} onChange={(e) => { setInternal(e.target.checked); if (!e.target.checked) form.setData('internal_notes', ''); }} />
                                    إضافة ملاحظات داخلية
                                </label>
                            </div>
                            {internal ? (
                                <>
                                    <Textarea value={d.internal_notes} maxLength={500} rows={4} onChange={(e) => form.setData('internal_notes', e.target.value)} placeholder="لفريقك وحده…" aria-label="ملاحظات داخلية" />
                                    <p className="mt-1 text-[12px] text-[#9ca3af]" dir="ltr">{d.internal_notes.length}/500</p>
                                </>
                            ) : (
                                <p className="rounded-[10px] border border-dashed border-line bg-[#fafafa] p-4 text-[12px] leading-relaxed text-[#9ca3af]">فعّل الخيار لكتابة ملاحظة لفريقك وحده — لا تصل العميل ولا تُطبع على فاتورته.</p>
                            )}
                        </Card>
                    </div>

                    {/* ── المرفقات ── */}
                    <Card className="p-5">
                        <h2 className="mb-1 text-[15px] font-bold text-ink">مرفقات الفاتورة</h2>
                        <p className="mb-3 text-[12px] text-[#9ca3af]">عرض سعر، عقد، أو أي مستندات داعمة — للفريق، لا تُطبع ولا تُرسل للعميل.</p>
                        <label className="flex cursor-pointer flex-col items-center gap-1.5 rounded-[12px] border border-dashed border-line bg-[#fafafa] px-4 py-6 text-center transition-colors hover:bg-[#f4f4f5]">
                            <Paperclip className="size-5 text-ink" />
                            <span className="text-[13px] font-medium text-ink">اختيار ملفات</span>
                            <span className="text-[11px] text-[#9ca3af]">JPG · PNG · PDF · WEBP · HEIC</span>
                            <span className="text-[11px] text-[#9ca3af]">حتى ٦ ملفات، ١٠ ميجابايت لكلٍّ</span>
                            <input type="file" multiple className="hidden" accept={ACCEPT} onChange={(e) => { addFiles(e.target.files); e.target.value = ''; }} />
                        </label>
                        {(kept.length > 0 || d.attachments.length > 0) && (
                            <ul className="mt-3 space-y-1.5 text-[13px]">
                                {kept.map((f) => (
                                    <li key={`kept-${f.id}`} className="flex items-center gap-2">
                                        <a href={f.url} className="min-w-0 flex-1 truncate text-[#1d4ed8] hover:underline">{f.name}</a>
                                        <button type="button" aria-label={`إزالة ${f.name}`} className="text-[#b91c1c]" onClick={() => { setKept((k) => k.filter((x) => x.id !== f.id)); form.setData('remove_attachments', [...d.remove_attachments, f.id]); }}><Trash2 className="size-4" /></button>
                                    </li>
                                ))}
                                {d.attachments.map((f, i) => (
                                    <li key={`${f.name}-${i}`} className="flex items-center gap-2">
                                        <span className="min-w-0 flex-1 truncate">{f.name}</span>
                                        <button type="button" aria-label={`إزالة ${f.name}`} className="text-[#b91c1c]" onClick={() => form.setData('attachments', d.attachments.filter((_, k) => k !== i))}><Trash2 className="size-4" /></button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {(errors.attachments || Object.entries(errors).find(([k]) => k.startsWith('attachments.'))?.[1]) && <p className="mt-2 text-[12px] text-[#b91c1c]">{errors.attachments ?? Object.entries(errors).find(([k]) => k.startsWith('attachments.'))?.[1]}</p>}
                    </Card>
                </div>

                {/* ── معاينة الفاتورة: the paper as it will be issued, kept in sight while the form is filled. ── */}
                <div className="min-w-0 space-y-2 xl:sticky xl:top-4 xl:self-start">
                    <div className="flex flex-wrap items-center gap-2 px-1">
                        <h2 className="me-auto text-[15px] font-bold text-ink">
                            معاينة الفاتورة
                            {drawing && <RefreshCw className="ms-2 inline size-3.5 animate-spin text-[#d1d5db]" />}
                        </h2>
                        {/* The paper's language, not the panel's: an English invoice for a company, in an Arabic panel. */}
                        <div role="group" aria-label="لغة الفاتورة" className="flex overflow-hidden rounded-[10px] border border-line">
                            {(['ar', 'en'] as const).map((code) => (
                                <button
                                    key={code}
                                    type="button"
                                    aria-pressed={lang === code}
                                    onClick={() => setLang(code)}
                                    className={cn('px-2.5 py-1 text-[12px] font-medium transition-colors', lang === code ? 'bg-[#111] text-white' : 'bg-white text-[#4b4b4b] hover:bg-[#fafafa]')}
                                >
                                    {code === 'ar' ? 'العربية' : 'English'}
                                </button>
                            ))}
                        </div>
                        {may.design && <Button variant="outline" size="sm" icon={<Settings2 />} href={route('club.settings.papers.edit', 'invoice')} external>تخصيص التصميم</Button>}
                    </div>
                    {/* As tall as the sheet, up to most of the screen: a phone shows the whole page without a grey well under it. */}
                    <PaperFrame html={paper.html} paper={paper.size} height="auto" title="معاينة الفاتورة" className="max-h-[min(72svh,1000px)]" />
                    <p className="px-1 text-[12px] leading-relaxed text-[#9ca3af]">الملاحظات الداخلية والمرفقات لا تظهر في الورقة المطبوعة. والرقم يظهر بعد الإصدار.</p>
                </div>
            </div>

            <ProductDialog open={picking} onClose={() => setPicking(false)} products={products} truncated={catalog_truncated} picked={lines.map((l) => Number(l.product_id)).filter((n) => n > 0)} onPick={pickProduct} />

            {draft && (
                <Modal
                    open={discarding}
                    onClose={() => setDiscarding(false)}
                    size="xs"
                    title="حذف المسودة"
                    description="تُحذف المسودة وبنودها ومرفقاتها. لم تصدر فاتورة، فلا قيد ولا رقم يتأثر."
                    footer={<><Button variant="outline" onClick={() => setDiscarding(false)}>إلغاء</Button><Button variant="danger" onClick={() => router.delete(route('club.invoices.drafts.discard', draft.id))}>حذف المسودة</Button></>}
                >
                    <span />
                </Modal>
            )}
        </ClubLayout>
    );
}

function ModeLink({ icon, onClick, children }: { icon: ReactNode; onClick: () => void; children: ReactNode }) {
    return (
        <button type="button" onClick={onClick} className="flex items-center gap-1 text-[12px] font-medium text-[#6d28d9] hover:underline [&_svg]:size-3.5">
            {icon}
            {children}
        </button>
    );
}

/** A line's figure: named above it when the line wraps, under the header row when it does not. */
function LineField({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0">
            <span className="mb-1 block text-[11px] text-[#9ca3af] @[42rem]:sr-only">{label}</span>
            {children}
        </div>
    );
}

function SumRow({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className="flex items-center justify-between">
            <dt className="text-[#6b7280]">{label}</dt>
            <dd className={cn('tabular-nums', strong ? 'font-semibold text-ink' : 'text-ink')}>{value}</dd>
        </div>
    );
}

/** منتج من الكتالوج: search by name, code or barcode; arrows and Enter pick; it stays open to add several lines. */
function ProductDialog({ open, onClose, products, truncated, picked, onPick }: { open: boolean; onClose: () => void; products: Product[]; truncated: boolean; picked: number[]; onPick: (p: Product) => void }) {
    const currency = useCurrency();
    const [q, setQ] = useState('');
    const [active, setActive] = useState(0);
    const [added, setAdded] = useState(0);
    const list = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (open) {
            setQ('');
            setActive(0);
            setAdded(0);
        }
    }, [open]);

    const needle = q.trim().toLowerCase();
    const matched = needle === '' ? products : products.filter((p) => [p.name, p.sku ?? '', p.barcode ?? ''].some((v) => v.toLowerCase().includes(needle)));
    const shown = matched.slice(0, 40);
    useEffect(() => setActive(0), [q]);
    useEffect(() => {
        list.current?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
    }, [active]);

    const take = (p: Product) => {
        onPick(p);
        setAdded((n) => n + 1);
        setQ('');
    };
    const onKey = (e: KeyboardEvent) => {
        if (shown.length === 0) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => (i + 1) % shown.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => (i - 1 + shown.length) % shown.length);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            take(shown[active] ?? shown[0]);
        }
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            size="wide"
            title="منتج من الكتالوج"
            description="ابحث بالاسم أو رمز الصنف أو الباركود — والنافذة تبقى مفتوحة لتضيف أكثر من بند."
            footer={<>{added > 0 && <span className="me-auto text-[12px] text-[#047857]">أُضيف <span className="num">{added}</span> بندًا</span>}<Button variant="outline" onClick={onClose}>تمّ</Button></>}
        >
            <div className="relative">
                <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[#9ca3af]" />
                <Input value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={onKey} placeholder="اسم الصنف أو رمزه أو الباركود" className="ps-9" aria-label="بحث في الكتالوج" />
            </div>
            <div ref={list} className="mt-2 max-h-80 overflow-y-auto" role="listbox" aria-label="المنتجات">
                {shown.map((p, i) => (
                    <button
                        key={p.id}
                        type="button"
                        role="option"
                        aria-selected={i === active}
                        data-active={i === active}
                        onMouseEnter={() => setActive(i)}
                        onClick={() => take(p)}
                        className={cn('flex w-full items-center gap-3 rounded-[8px] px-3 py-2 text-start text-[13px]', i === active ? 'bg-[#f1f1ef]' : 'hover:bg-[#f7f7f5]')}
                    >
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-ink">{p.name}</span>
                            <span className="block truncate text-[11px] text-[#9ca3af]">
                                {[p.sku, p.barcode].filter(Boolean).join(' · ') || (p.track_stock ? '' : 'خدمة')}
                                {p.track_stock && <> {p.sku || p.barcode ? '·' : ''} المتوفر <span className="num">{p.stock ?? 0}</span></>}
                            </span>
                        </span>
                        {picked.includes(p.id) && <span className="shrink-0 rounded-full bg-[#eff6ff] px-2 py-0.5 text-[11px] text-[#1d4ed8]">في الفاتورة</span>}
                        <span className="shrink-0 tabular-nums text-[#6b7280]">{money(p.price, currency)}</span>
                    </button>
                ))}
                {shown.length === 0 && <p className="p-4 text-center text-[12px] text-[#9ca3af]">{products.length === 0 ? 'لا أصناف في الكتالوج بعد — تُضاف من صفحة المنتجات.' : 'لا صنف بهذا الاسم أو الرمز'}</p>}
                {matched.length > shown.length && <p className="p-3 text-center text-[11px] text-[#9ca3af]">عُرض <span className="num">{shown.length}</span> من <span className="num">{matched.length}</span> — ضيّق البحث</p>}
            </div>
            {truncated && needle === '' && <p className="mt-1 text-[11px] text-[#b45309]">الكتالوج أكبر ممّا يُحمَّل هنا — ابحث بالاسم أو الرمز للوصول إلى الباقي.</p>}
        </Modal>
    );
}
