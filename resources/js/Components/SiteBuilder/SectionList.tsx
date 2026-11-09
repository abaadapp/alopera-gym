import { useState, type ReactNode } from 'react';
import { ChevronDown, ChevronUp, Copy, Eye, EyeOff, GripVertical, Plus, Trash2 } from 'lucide-react';
import { Badge, Button, Modal } from '@/Components/ui';
import { cn } from '@/lib/cn';
import { hasErrorUnder, missingContent, sectionSummary } from '@/lib/site-builder';
import type { SectionDoc } from '@/types/site';
import { sectionLabel, useBuilder } from './context';
import { useDragSort } from './useDragSort';

/**
 * The home page's sections in their order: drag a row (computer) or use the arrows (phone, keyboard) to move it; the
 * eye hides or shows it; copy and delete. The chosen one is edited beside the list.
 */
export default function SectionList({
    sections,
    selected,
    onSelect,
    onMove,
    onToggle,
    onDuplicate,
    onDelete,
    onAdd,
}: {
    sections: SectionDoc[];
    selected: string | null;
    onSelect: (id: string) => void;
    onMove: (from: number, to: number) => void;
    onToggle: (id: string) => void;
    onDuplicate: (id: string) => void;
    onDelete: (id: string) => void;
    onAdd: () => void;
}) {
    const { schemas, errors, waiting } = useBuilder();
    const sort = useDragSort('sections', sections.length, onMove);
    const [removing, setRemoving] = useState<SectionDoc | null>(null);

    return (
        <div className="space-y-3">
            <Button type="button" className="w-full" icon={<Plus />} onClick={onAdd}>إضافة قسم</Button>
            <p className="text-[12px] leading-5 text-muted">اسحب القسم من المقبض لتغيير ترتيبه، أو استخدم الأسهم. الأقسام المخفية لا تظهر للزوار.</p>
            <ol className="space-y-0" aria-label="أقسام الصفحة الرئيسية">
                {sections.map((s, i) => {
                    const schema = schemas[s.type];
                    const summary = sectionSummary(s);
                    const bad = hasErrorUnder(errors, `sections.${s.id}`);
                    const active = selected === s.id;
                    return (
                        <li key={s.id}>
                            {sort.lineAt(i) && <div className="mb-1 h-0.5 rounded bg-ink" />}
                            <div
                                {...sort.itemProps(i)}
                                className={cn(
                                    'group mb-2 flex items-center gap-1 rounded-xl border bg-white px-1.5 py-1.5 transition',
                                    active ? 'border-ink shadow-[0_0_0_2px_rgba(17,17,17,0.08)]' : 'border-line hover:border-[#d1d5db]',
                                    bad && !active && 'border-red-300',
                                    !s.visible && 'bg-[#fafafa]',
                                    sort.dragging === i && 'opacity-50',
                                )}
                            >
                                <GripVertical className="size-4 shrink-0 cursor-grab text-[#9ca3af]" aria-hidden />
                                <button type="button" onClick={() => onSelect(s.id)} aria-current={active ? 'true' : undefined} className="min-w-0 flex-1 py-1 text-start">
                                    <span className={cn('flex flex-wrap items-center gap-1.5 text-[13px] font-semibold', s.visible ? 'text-ink' : 'text-muted')}>
                                        <span className="num text-[11px] font-normal text-muted">{i + 1}</span>
                                        {sectionLabel(s, schemas)}
                                        {!s.visible && <Badge tone="gray">مخفي</Badge>}
                                        {s.visible && waiting[s.id] && <Badge tone="amber">لا يظهر الآن</Badge>}
                                        {s.visible && !waiting[s.id] && missingContent(s) && <Badge tone="amber">فارغ — لا يظهر</Badge>}
                                        {schema?.group === 'system' && <Badge tone="blue">من النظام</Badge>}
                                        {bad && <Badge tone="red">فيه خطأ</Badge>}
                                    </span>
                                    {summary && <span className="mt-0.5 block truncate text-[12px] text-muted">{summary}</span>}
                                </button>
                                <div className="flex shrink-0 items-center">
                                    <IconButton label={`نقل «${sectionLabel(s, schemas)}» للأعلى`} disabled={i === 0} onClick={() => onMove(i, i - 1)}><ChevronUp /></IconButton>
                                    <IconButton label={`نقل «${sectionLabel(s, schemas)}» للأسفل`} disabled={i === sections.length - 1} onClick={() => onMove(i, i + 1)}><ChevronDown /></IconButton>
                                    <IconButton label={s.visible ? 'إخفاء القسم عن الزوار' : 'إظهار القسم للزوار'} onClick={() => onToggle(s.id)}>{s.visible ? <Eye /> : <EyeOff />}</IconButton>
                                    <IconButton label="نسخ القسم" onClick={() => onDuplicate(s.id)}><Copy /></IconButton>
                                    <IconButton label="حذف القسم" danger onClick={() => setRemoving(s)}><Trash2 /></IconButton>
                                </div>
                            </div>
                        </li>
                    );
                })}
                {sort.lineAt(sections.length) && <li className="mb-1 h-0.5 rounded bg-ink" aria-hidden />}
            </ol>
            {sections.length === 0 && <p className="rounded-xl border border-dashed border-line px-3 py-6 text-center text-sm text-muted">لا توجد أقسام. أضف قسمًا لتبدأ.</p>}

            <Modal
                open={removing !== null}
                onClose={() => setRemoving(null)}
                title="حذف القسم؟"
                size="confirm"
                footer={
                    <>
                        <Button variant="outline" onClick={() => setRemoving(null)}>إلغاء</Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                if (removing) onDelete(removing.id);
                                setRemoving(null);
                            }}
                        >
                            حذف القسم
                        </Button>
                    </>
                }
            >
                <p className="text-sm leading-7 text-[#4b4b4b]">
                    يُحذف قسم «{removing ? sectionLabel(removing, schemas) : ''}» من المسودة. لا يتغير الموقع المنشور حتى تضغط «نشر التغييرات»، ويمكنك إخفاء القسم بدل حذفه.
                </p>
            </Modal>
        </div>
    );
}

function IconButton({ label, onClick, disabled, danger, children }: { label: string; onClick: () => void; disabled?: boolean; danger?: boolean; children: ReactNode }) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            disabled={disabled}
            onClick={onClick}
            className={cn('grid size-8 place-items-center rounded-lg transition disabled:opacity-30 [&_svg]:size-4 touch:size-10', danger ? 'text-red-700 hover:bg-red-50' : 'text-[#6b7280] hover:bg-[#f2f2f0] hover:text-ink')}
        >
            {children}
        </button>
    );
}
