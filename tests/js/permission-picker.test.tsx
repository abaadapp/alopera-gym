// The permission picker: search, the sensitive filter, a whole section on and off, the count, and a sensitive box
// fixed for an editor who is not the owner. A box's label carries the «حساسة» mark, so labels match by part.
import { useState } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import PermissionPicker, { type PermissionGroup } from '@/Components/Permissions/PermissionPicker';

const groups: PermissionGroup[] = [
    { key: 'members', label: 'الأعضاء', permissions: [{ key: 'members.view', label: 'عرض الأعضاء' }, { key: 'members.export', label: 'تصدير بيانات الأعضاء' }] },
    { key: 'payroll', label: 'الرواتب', permissions: [{ key: 'payroll.salaries', label: 'عرض الرواتب' }, { key: 'payroll.pay', label: 'صرف الرواتب' }] },
];
const sensitive = ['members.export', 'payroll.salaries', 'payroll.pay'];

function Picker({ decides }: { decides: string[] }) {
    const [value, setValue] = useState<string[]>([]);
    return <PermissionPicker groups={groups} value={value} onChange={setValue} editable mine={(p) => decides.includes(p)} sensitive={sensitive} requires={{ 'payroll.pay': 'payroll.salaries' }} />;
}

afterEach(cleanup);

describe('the permission picker', () => {
    it('chooses a whole section for the owner, with what each step needs, and counts it', () => {
        render(<Picker decides={groups.flatMap((g) => g.permissions.map((p) => p.key))} />);
        fireEvent.click(screen.getAllByText('تحديد الكل')[1]);
        expect((screen.getByLabelText('صرف الرواتب', { exact: false }) as HTMLInputElement).checked).toBe(true);
        expect((screen.getByLabelText('عرض الرواتب', { exact: false }) as HTMLInputElement).checked).toBe(true);
        expect(screen.getByText(/المختارة/, { selector: 'p' }).textContent).toContain('2');
        fireEvent.click(screen.getAllByText('إلغاء التحديد')[1]);
        expect((screen.getByLabelText('صرف الرواتب', { exact: false }) as HTMLInputElement).checked).toBe(false);
    });

    it('keeps a sensitive box fixed for someone else, and marks it', () => {
        render(<Picker decides={['members.view', 'members.export']} />);
        expect((screen.getByLabelText('عرض الأعضاء', { exact: false }) as HTMLInputElement).disabled).toBe(false);
        expect((screen.getByLabelText('صرف الرواتب', { exact: false }) as HTMLInputElement).disabled).toBe(true);
        expect(screen.getAllByText('حساسة')).toHaveLength(3);
    });

    it('finds by name and shows the sensitive only', () => {
        render(<Picker decides={[]} />);
        fireEvent.change(screen.getByPlaceholderText('ابحث عن صلاحية أو قسم'), { target: { value: 'تصدير' } });
        expect(screen.queryByLabelText('عرض الأعضاء', { exact: false })).toBeNull();
        expect(screen.getByLabelText('تصدير بيانات الأعضاء', { exact: false })).toBeTruthy();
        fireEvent.change(screen.getByPlaceholderText('ابحث عن صلاحية أو قسم'), { target: { value: '' } });
        fireEvent.click(screen.getByRole('button', { name: /الحساسة/ }));
        expect(screen.queryByLabelText('عرض الأعضاء', { exact: false })).toBeNull();
        expect(screen.getByLabelText('صرف الرواتب', { exact: false })).toBeTruthy();
    });
});
