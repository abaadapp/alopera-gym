import { Database, PenLine } from 'lucide-react';
import { Modal } from '@/Components/ui';
import type { SectionTypeSchema } from '@/types/site';

/** The templates a section can be made from, in two groups: what the owner writes, and what the system fills. */
export default function AddSectionModal({ open, onClose, schema, onPick }: { open: boolean; onClose: () => void; schema: SectionTypeSchema[]; onPick: (type: SectionTypeSchema) => void }) {
    const groups: { key: SectionTypeSchema['group']; title: string; hint: string; icon: typeof PenLine }[] = [
        { key: 'content', title: 'أقسام تكتبها أنت', hint: 'نصوص وصور وأزرار تكتبها وتختارها بنفسك.', icon: PenLine },
        { key: 'system', title: 'أقسام من بيانات النظام', hint: 'تتحدث تلقائيًا من بيانات الجيم، دون إدخالها مرتين.', icon: Database },
    ];
    return (
        <Modal open={open} onClose={onClose} title="إضافة قسم" description="اختر قالبًا. يُضاف بعد القسم المختار، ويمكنك نقله وتعديله بعد ذلك." size="wide">
            <div className="space-y-6 pb-2">
                {groups.map((g) => {
                    const list = schema.filter((s) => s.group === g.key);
                    if (list.length === 0) return null;
                    return (
                        <section key={g.key}>
                            <h3 className="flex items-center gap-2 text-[15px] font-bold text-ink"><g.icon className="size-4" />{g.title}</h3>
                            <p className="mt-0.5 mb-3 text-[12px] text-muted">{g.hint}</p>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {list.map((s) => (
                                    <button
                                        key={s.type}
                                        type="button"
                                        onClick={() => onPick(s)}
                                        className="flex flex-col items-start gap-1 rounded-xl border border-line bg-white p-3 text-start transition hover:border-ink hover:shadow-[0_2px_10px_rgba(0,0,0,0.06)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ink/20"
                                    >
                                        <span className="text-sm font-semibold text-ink">{s.label}</span>
                                        <span className="text-[12px] leading-5 text-[#4b4b4b]">{s.description}</span>
                                        {s.source && <span className="mt-1 text-[11px] leading-5 text-[#2563eb]">{s.source}</span>}
                                    </button>
                                ))}
                            </div>
                        </section>
                    );
                })}
            </div>
        </Modal>
    );
}
