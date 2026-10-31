import type { ReactNode } from 'react';
import { ChevronDown, Save, type LucideIcon } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import SettingsBack from '@/Components/SettingsBack';
import { Button, Card, PageHeader } from '@/Components/ui';
import { cn } from '@/lib/cn';

const widths = { form: 'max-w-3xl', wide: 'max-w-5xl', full: '' };

/**
 * A settings screen: the settings header stays («الإعدادات»), the way back to all settings under it, then the
 * screen's sections — one column of forms (`form`), a wider one (`wide`), or the full width for lists (`full`).
 */
export function SettingsScreen({ title, width = 'form', children }: { title: string; width?: keyof typeof widths; children: ReactNode }) {
    return (
        <ClubLayout title={title}>
            <PageHeader title="الإعدادات" subtitle="هوية النادي والفريق والربط والبيانات — اختر القسم الذي تريد تعديله" />
            <SettingsBack />
            <div className={cn('mx-auto min-w-0 space-y-6', widths[width])}>{children}</div>
        </ClubLayout>
    );
}

/** The icon of a section's header: an 18px glyph in a 36px grey square. */
function SectionIcon({ icon: Icon }: { icon: LucideIcon }) {
    return (
        <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f3f4f6] text-[#111]">
            <Icon className="size-[18px]" />
        </span>
    );
}

/** One card of a settings screen: icon, title and description, optional actions at the far side, then its fields. */
export function SettingsSection({ icon, title, description, actions, divided, className, children }: {
    icon: LucideIcon;
    title: ReactNode;
    description?: ReactNode;
    actions?: ReactNode;
    divided?: boolean;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Card padded={false} className={cn('p-5 sm:p-6', className)}>
            <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
                    <SectionIcon icon={icon} />
                    <div className="min-w-0">
                        <h2 className="font-bold text-[#111]">{title}</h2>
                        {description && <p className="mt-0.5 text-[13px] leading-relaxed text-[#6b7280]">{description}</p>}
                    </div>
                </div>
                {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
            </div>
            <div className={cn(divided && 'divide-y divide-[#e8e8e8]')}>{children}</div>
        </Card>
    );
}

/** A block inside a divided section: its own small heading over its fields. */
export function SettingsGroup({ title, description, children }: { title: ReactNode; description?: ReactNode; children: ReactNode }) {
    return (
        <div className="py-6 first:pt-0 last:pb-0">
            <div className="mb-4">
                <h3 className="font-bold text-[#111]">{title}</h3>
                {description && <p className="mt-0.5 text-[13px] leading-relaxed text-[#6b7280]">{description}</p>}
            </div>
            {children}
        </div>
    );
}

/** The foot of a settings form: an optional note, then the save button at the end. */
export function PageActions({ note, processing, label = 'حفظ التغييرات', className, children }: { note?: ReactNode; processing?: boolean; label?: string; className?: string; children?: ReactNode }) {
    return (
        <div className={cn('mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-end', className)}>
            {note && <p className="text-[12px] leading-relaxed text-[#9ca3af] sm:me-auto">{note}</p>}
            {children}
            <Button type="submit" icon={<Save />} loading={processing}>{label}</Button>
        </div>
    );
}

/** A section folded away until opened: the less-used options. */
export function Advanced({ icon, title, description, children }: { icon: LucideIcon; title: ReactNode; description?: ReactNode; children: ReactNode }) {
    return (
        <Card padded={false} className="overflow-hidden">
            <details className="group">
                <summary className="flex cursor-pointer list-none items-center gap-3 p-5 transition-colors hover:bg-[#fafafa] [&::-webkit-details-marker]:hidden">
                    <SectionIcon icon={icon} />
                    <div className="min-w-0 flex-1">
                        <h2 className="font-bold text-[#111]">{title}</h2>
                        {description && <p className="mt-0.5 text-[13px] leading-relaxed text-[#6b7280]">{description}</p>}
                    </div>
                    <ChevronDown className="size-4 text-[#9ca3af] transition-transform group-open:rotate-180" />
                </summary>
                <div className="border-t border-[#e8e8e8] p-5">{children}</div>
            </details>
        </Card>
    );
}

/** The title card of a settings list (devices, backups, the audit log): its name and description, its actions. */
export function SettingsTitle({ title, description, actions }: { title: ReactNode; description?: ReactNode; actions?: ReactNode }) {
    return (
        <Card padded={false} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <div className="min-w-0">
                <h2 className="text-[17px] font-bold text-[#111]">{title}</h2>
                {description && <p className="mt-0.5 text-[13px] text-[#6b7280]">{description}</p>}
            </div>
            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </Card>
    );
}
