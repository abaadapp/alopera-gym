import { useMemo, useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { FolderTree, Pencil, Plus } from 'lucide-react';
import ClubLayout from '@/Layouts/ClubLayout';
import { Badge, Button, Card, Checkbox, Field, Input, Modal, PageHeader, Select, Toggle } from '@/Components/ui';
import { useCurrency } from '@/hooks/useShared';
import { cn } from '@/lib/cn';
import { money } from '@/lib/format';

type Account = { id: number; parent_id: number | null; code: string; name: string; type: string; normal_side: string; is_group: boolean; is_active: boolean; system: boolean; kind: string | null; balance: string | null; used: boolean };

interface Props {
    accounts: Account[];
    types: Record<string, string>;
    canManage: boolean;
}

/** The tree in code order, each account with its depth; a heading's balance is the sum of everything under it. */
function useTree(accounts: Account[]) {
    return useMemo(() => {
        const children = new Map<number | null, Account[]>();
        accounts.forEach((a) => children.set(a.parent_id, [...(children.get(a.parent_id) ?? []), a]));
        const out: (Account & { depth: number; total: number })[] = [];
        const walk = (parent: number | null, depth: number): number => {
            let sum = 0;
            for (const a of children.get(parent) ?? []) {
                const index = out.length;
                out.push({ ...a, depth, total: 0 });
                const own = a.is_group ? walk(a.id, depth + 1) : Number(a.balance ?? 0);
                // A contra account (normal side opposite its type) reduces its heading.
                const contra = (['asset', 'expense'].includes(a.type) ? 'debit' : 'credit') !== a.normal_side;
                out[index].total = own;
                sum += contra ? -own : own;
            }
            return sum;
        };
        walk(null, 0);
        return out;
    }, [accounts]);
}

export default function FinanceAccounts({ accounts, types, canManage }: Props) {
    const currency = useCurrency();
    const rows = useTree(accounts);
    const [editing, setEditing] = useState<Account | 'new' | null>(null);
    const groups = accounts.filter((a) => a.is_group);

    return (
        <ClubLayout title="شجرة الحسابات">
            <PageHeader
                title="شجرة الحسابات"
                subtitle="حسابات النادي مرتبة برموزها. الحسابات الأساسية ينشئها النظام ويرحّل إليها تلقائيًا؛ ويمكن إضافة حسابات فرعية لها."
                actions={canManage && <Button icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>حساب جديد</Button>}
            />

            <Card padded={false}>
                <div className="overflow-x-auto">
                    <table className="table-base">
                        <thead><tr><th>الرمز</th><th>الحساب</th><th>النوع</th><th className="text-end">الرصيد</th><th /></tr></thead>
                        <tbody>
                            {rows.map((a) => (
                                <tr key={a.id} className={cn(!a.is_active && 'opacity-60')}>
                                    <td className="num text-muted">{a.code}</td>
                                    <td>
                                        <span className="flex items-center gap-2" style={{ paddingInlineStart: `${a.depth * 20}px` }}>
                                            {a.is_group && <FolderTree className="size-4 text-muted" />}
                                            <span className={cn(a.is_group ? 'font-semibold' : '')}>{a.name}</span>
                                            {a.system && !a.is_group && <Badge tone="blue">أساسي</Badge>}
                                            {!a.is_active && <Badge tone="amber">موقوف</Badge>}
                                        </span>
                                    </td>
                                    <td className="text-muted">{types[a.type]}</td>
                                    <td className={cn('text-end num', a.is_group ? 'font-semibold' : 'font-medium', a.total < 0 && 'text-red-700')}>{a.total || a.used ? money(a.total, currency) : '—'}</td>
                                    <td className="text-end">{canManage && <button type="button" onClick={() => setEditing(a)} className="grid size-8 place-items-center rounded-lg text-muted hover:bg-ink/[0.045] hover:text-ink" aria-label="تعديل"><Pencil className="size-4" /></button>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Card>
            <p className="mt-3 text-[12px] text-muted">الأرصدة محسوبة من القيود مباشرة. «خصومات الرواتب» و«المردودات» حسابات معاكسة تُنقص المجموعة التي تحتها.</p>

            {editing && <AccountForm account={editing === 'new' ? null : editing} groups={groups} onClose={() => setEditing(null)} />}
        </ClubLayout>
    );
}

function AccountForm({ account, groups, onClose }: { account: Account | null; groups: Account[]; onClose: () => void }) {
    const form = useForm({ parent_id: '', name: account?.name ?? '', code: '', is_group: false, is_active: account?.is_active ?? true });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        account ? form.put(route('club.finance.accounts.update', account.id), opts) : form.post(route('club.finance.accounts.store'), opts);
    };

    return (
        <Modal open onClose={onClose} title={account ? `تعديل «${account.name}»` : 'حساب جديد'} footer={<><Button variant="outline" onClick={onClose}>إلغاء</Button><Button onClick={submit} loading={form.processing}>حفظ</Button></>}>
            <form onSubmit={submit} className="space-y-4">
                {!account && (
                    <Field label="تحت الحساب الرئيسي" error={form.errors.parent_id} required>
                        <Select value={form.data.parent_id} onChange={(e) => form.setData('parent_id', e.target.value)} placeholder="اختر">
                            {groups.map((g) => <option key={g.id} value={g.id}>{g.code} · {g.name}</option>)}
                        </Select>
                    </Field>
                )}
                <Field label="اسم الحساب" error={form.errors.name} required><Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus /></Field>
                {!account && (
                    <>
                        <Field label="الرمز" error={form.errors.code} hint="اتركه فارغًا ليُختار الرمز التالي تلقائيًا"><Input dir="ltr" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} /></Field>
                        <Checkbox label="حساب رئيسي (عنوان تُضاف تحته حسابات أخرى)" checked={form.data.is_group} onChange={(e) => form.setData('is_group', e.target.checked)} />
                    </>
                )}
                {account && !account.system && <Toggle checked={form.data.is_active} onChange={(v) => form.setData('is_active', v)} label="الحساب مفعّل" hint={form.errors.is_active ?? 'الحساب الموقوف لا يقبل قيودًا جديدة'} />}
                {account?.system && <p className="text-[13px] text-muted">حساب أساسي: يمكن تغيير اسمه فقط، ولا يُوقف لأن النظام يرحّل إليه.</p>}
            </form>
        </Modal>
    );
}
