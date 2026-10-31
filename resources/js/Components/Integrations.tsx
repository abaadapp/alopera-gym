import { useState, type FormEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Check, CircleCheck, CircleDashed, Copy, CreditCard, Fingerprint, MessageCircle, Plug, PowerOff, Wrench, XCircle, type LucideIcon } from 'lucide-react';
import { BackLink, Badge, Button, Field, Input, Select, Toggle } from '@/Components/ui';
import { cn } from '@/lib/cn';

/** The four states of an integration, as the server decides them (App\Modules\Integrations\Services\IntegrationHub). */
export type IntegrationState = 'off' | 'setup' | 'connected' | 'error';
export type IntegrationStatusData = { state: IntegrationState; label: string; detail: string };

/* Each state has an icon and words as well as a colour, so it reads without telling green from red. */
const TONES: Record<IntegrationState, { cls: string; icon: LucideIcon }> = {
    off: { cls: 'bg-[#f2f2f0] text-[#6b7280]', icon: PowerOff },
    setup: { cls: 'bg-amber-50 text-amber-700', icon: Wrench },
    connected: { cls: 'bg-emerald-50 text-emerald-700', icon: Plug },
    error: { cls: 'bg-red-50 text-red-700', icon: XCircle },
};

export function IntegrationStatus({ status, className }: { status: Pick<IntegrationStatusData, 'state' | 'label'>; className?: string }) {
    const tone = TONES[status.state] ?? TONES.off;
    const Icon = tone.icon;
    return (
        <span className={cn('inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-[12px] font-medium', tone.cls, className)}>
            <Icon aria-hidden className="size-3.5 shrink-0" />
            {status.label}
        </span>
    );
}

/* One icon style for the three services: a 48px tile in the service's colour with a white line icon. */
const MARKS: Record<string, { icon: LucideIcon; tint: string }> = {
    paymob: { icon: CreditCard, tint: '#2563eb' },
    devices: { icon: Fingerprint, tint: '#1f2937' },
    whatsapp: { icon: MessageCircle, tint: '#16a34a' },
};

export function IntegrationMark({ service, size = 48 }: { service: string; size?: number }) {
    const mark = MARKS[service] ?? { icon: Plug, tint: '#4b4b4b' };
    const Icon = mark.icon;
    return (
        <span className="grid shrink-0 place-items-center rounded-[12px] text-white" style={{ width: size, height: size, backgroundColor: mark.tint }}>
            <Icon aria-hidden style={{ width: size * 0.46, height: size * 0.46 }} strokeWidth={1.75} />
        </span>
    );
}

/** A connected service on the integrations page: its mark, name and site, what it does, its state, and the way in. */
export function IntegrationCard({ service, name, subtitle, site, line, status, action }: { service: string; name: string; subtitle: string; site: string; line: string; status: IntegrationStatusData; action: ReactNode }) {
    return (
        <div className="card flex h-full flex-col p-5">
            <div className="flex items-start gap-3">
                <IntegrationMark service={service} />
                <div className="min-w-0">
                    <h3 className="truncate font-bold text-[#111]">{name}</h3>
                    <p className="mt-0.5 text-[12px] text-[#9ca3af]">
                        {subtitle} · <span dir="ltr">{site}</span>
                    </p>
                </div>
            </div>
            <p className="mt-4 grow text-[13px] leading-relaxed text-[#6b7280]">{line}</p>
            <p className={cn('mt-3 text-[12px] leading-5', status.state === 'error' ? 'text-[#b91c1c]' : 'text-[#9ca3af]')}>{status.detail}</p>
            <div className="mt-5 flex items-center justify-between gap-3 border-t border-[#e8e8e8] pt-4">
                {action}
                <IntegrationStatus status={status} />
            </div>
        </div>
    );
}

/** The way back to the integrations page, above each service's settings. */
export function IntegrationBack() {
    return (
        <BackLink href={route('club.integrations.index')}>التطبيقات التكاملية</BackLink>
    );
}

/** A service page's header block: its mark, name, state and what the state means. */
export function IntegrationSummary({ service, title, status, children }: { service: string; title: string; status: IntegrationStatusData; children?: ReactNode }) {
    return (
        <div className="card mb-6 flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
            <div className="flex min-w-0 flex-1 items-start gap-3">
                <IntegrationMark service={service} />
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="font-bold text-ink">{title}</h2>
                        <IntegrationStatus status={status} />
                    </div>
                    <p className={cn('mt-1 text-[13px] leading-6', status.state === 'error' ? 'text-red-700' : 'text-muted')}>{status.detail}</p>
                </div>
            </div>
            {children && <div className="flex shrink-0 flex-wrap gap-2">{children}</div>}
        </div>
    );
}

/** Ticked steps (keys entered, accepted by the provider, a signed callback received). */
export function CheckList({ items }: { items: { label: string; ok: boolean; at?: string | null }[] }) {
    return (
        <ul className="divide-y divide-line">
            {items.map((item) => (
                <li key={item.label} className="flex items-center gap-3 py-2.5 text-sm">
                    {item.ok ? <CircleCheck className="size-[18px] shrink-0 text-emerald-600" /> : <CircleDashed className="size-[18px] shrink-0 text-[#9ca3af]" />}
                    <span className={cn('min-w-0 flex-1', item.ok ? 'text-ink' : 'text-muted')}>{item.label}</span>
                    {item.at && <span className="num shrink-0 text-xs text-muted">{item.at}</span>}
                </li>
            ))}
        </ul>
    );
}

export function CopyRow({ label, value }: { label: string; value: string }) {
    const [copied, setCopied] = useState(false);
    return (
        <div>
            <p className="mb-1 text-xs text-muted">{label}</p>
            <div className="flex items-center gap-2 rounded-xl border border-line bg-canvas px-3 py-2">
                <code className="min-w-0 flex-1 truncate text-xs" dir="ltr">{value}</code>
                <button
                    type="button"
                    className="grid size-7 shrink-0 place-items-center rounded-lg hover:bg-white"
                    onClick={() => navigator.clipboard?.writeText(value).then(() => {
                        setCopied(true);
                        setTimeout(() => setCopied(false), 1500);
                    })}
                    aria-label="نسخ"
                >
                    {copied ? <Check className="size-3.5 text-emerald-600" /> : <Copy className="size-3.5" />}
                </button>
            </div>
        </div>
    );
}

/** The stored keys of a provider, as the screen may know them: which are filled, never their values. */
export interface ProviderForm {
    is_enabled: boolean;
    mode: string;
    present: Record<string, boolean>;
    public: Record<string, string | null>;
    last_error: string | null;
    last_error_at: string | null;
    last_checked_at: string | null;
}

export type KeyField = { key: string; label: string; hint?: string; secret?: boolean };

/**
 * The keys form of Paymob and WhatsApp. Secrets are write-only: a saved field shows «محفوظ» and an empty input;
 * typing replaces it, leaving it empty keeps it. `extra` adds provider fields (WhatsApp's display number).
 */
export function KeysForm({ provider, integration, fields, modeHint, extra }: {
    provider: 'paymob' | 'whatsapp';
    integration: ProviderForm;
    fields: KeyField[];
    modeHint?: ReactNode;
    extra?: (value: string, set: (v: string) => void, error?: string) => ReactNode;
}) {
    const blank = () => Object.fromEntries(fields.map((f) => [f.key, ''])) as Record<string, string>;
    const form = useForm({
        is_enabled: integration.is_enabled,
        mode: integration.mode,
        credentials: blank(),
        display_phone: integration.public?.display_phone ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(route('club.integrations.update', provider), { preserveScroll: true, onSuccess: () => form.setData('credentials', blank()) });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <Toggle checked={form.data.is_enabled} onChange={(v) => form.setData('is_enabled', v)} label="تفعيل" hint="المتوقف لا يُستخدم في أي عملية، وتبقى مفاتيحه محفوظة" />
                <Field label="الوضع" error={errors.mode} hint={modeHint}>
                    <Select value={form.data.mode} onChange={(e) => form.setData('mode', e.target.value)}>
                        <option value="test">اختبار</option>
                        <option value="live">إنتاج (حقيقي)</option>
                    </Select>
                </Field>
            </div>

            {fields.map((f) => {
                const saved = integration.present?.[f.key];
                return (
                    <Field
                        key={f.key}
                        label={
                            <span className="flex w-full items-center justify-between gap-2">
                                <span dir="ltr">{f.label}</span>
                                {saved ? <Badge tone="green"><Check className="size-3.5" /> محفوظ</Badge> : <Badge>غير مُدخل</Badge>}
                            </span>
                        }
                        hint={f.hint}
                        error={errors[`credentials.${f.key}`]}
                    >
                        <Input
                            dir="ltr"
                            type={f.secret ? 'password' : 'text'}
                            autoComplete="off"
                            value={form.data.credentials[f.key] ?? ''}
                            onChange={(e) => form.setData('credentials', { ...form.data.credentials, [f.key]: e.target.value })}
                            placeholder={saved ? '•••••••• (اتركه فارغًا للإبقاء على المحفوظ)' : ''}
                        />
                    </Field>
                );
            })}

            {extra?.(form.data.display_phone, (v) => form.setData('display_phone', v), errors.display_phone)}

            <div className="flex flex-wrap items-center gap-2 pt-1">
                <Button type="submit" loading={form.processing}>حفظ</Button>
                <span className="text-xs text-muted">لا تظهر المفاتيح بعد حفظها لأي مستخدم، ولا تُكتب في السجلات.</span>
            </div>
        </form>
    );
}

/** Ask the provider whether the stored keys work. */
export function VerifyButton({ provider, disabled }: { provider: 'paymob' | 'whatsapp'; disabled?: boolean }) {
    const [busy, setBusy] = useState(false);
    return (
        <Button
            variant="outline"
            icon={<Plug className="size-4" />}
            loading={busy}
            disabled={disabled}
            onClick={() => router.post(route('club.integrations.verify', provider), {}, { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) })}
        >
            اختبار الاتصال
        </Button>
    );
}
