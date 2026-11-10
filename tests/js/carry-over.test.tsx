import { act, render } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { announceLanguageSwitch, useCarryOver } from '@/lib/carry-over';

type Data = { full_name: string; phone: string; password: string; website: string };

function JoinForm({ start, onValue }: { start: Data; onValue?: (d: Data) => void }) {
    const [data, setData] = useState(start);
    useCarryOver('join', data, (saved) => setData((d) => ({ ...d, ...saved })));
    onValue?.(data);
    return null;
}

describe('typed fields across the language switch', () => {
    afterEach(() => sessionStorage.clear());

    it('keeps what was typed, never the password, and puts it back once on the page in the other language', () => {
        const first = render(<JoinForm start={{ full_name: 'Sara', phone: '96890001122', password: 'secret-pass', website: '' }} />);
        act(() => announceLanguageSwitch());
        first.unmount();
        const stored = JSON.parse(sessionStorage.getItem('site-carry:join') ?? '{}');
        expect(stored).toEqual({ full_name: 'Sara', phone: '96890001122' });

        let seen: Data | null = null;
        render(<JoinForm start={{ full_name: '', phone: '', password: '', website: '' }} onValue={(d) => (seen = d)} />);
        expect(seen).toEqual({ full_name: 'Sara', phone: '96890001122', password: '', website: '' });
        expect(sessionStorage.getItem('site-carry:join')).toBeNull();
    });

    it('keeps nothing on any other way of leaving the page', () => {
        const form = render(<JoinForm start={{ full_name: 'Sara', phone: '', password: '', website: '' }} />);
        form.unmount();
        expect(sessionStorage.getItem('site-carry:join')).toBeNull();
    });
});
