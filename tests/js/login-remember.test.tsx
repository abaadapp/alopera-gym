import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';

// The sign-in page with a stand-in for Inertia's form: what it would post, and nothing else.
const { posted } = vi.hoisted(() => ({ posted: vi.fn() }));
vi.mock('@/hooks/useShared', () => ({ useShared: () => ({ flash: null, errors: {} }) }));
vi.mock('sonner', () => ({ Toaster: () => null, toast: { error: vi.fn(), success: vi.fn() } }));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        Head: () => null,
        router: { on: () => () => {} },
        usePage: () => ({ props: { logo: '/brand/logo.png' } }),
        useForm: <T extends Record<string, unknown>>(initial: T) => {
            const [data, setState] = React.useState(initial);

            return {
                data,
                errors: {},
                processing: false,
                setData: (key: keyof T, value: unknown) => setState((d) => ({ ...d, [key]: value })),
                reset: () => {},
                post: (url: string) => posted(url, data),
            };
        },
    };
});

import Login from '@/Pages/Auth/Login';

beforeEach(() => {
    (globalThis as unknown as { route: (name: string) => string }).route = (name: string) => `/${name}`;
    localStorage.clear();
    sessionStorage.clear();
});

afterEach(() => {
    cleanup();
    posted.mockReset();
});

function signIn(container: HTMLElement) {
    fireEvent.change(container.querySelector('input[type="email"]')!, { target: { value: 'owner@example.com' } });
    fireEvent.change(container.querySelector('input[type="password"]')!, { target: { value: 'secret-pass' } });
    fireEvent.submit(container.querySelector('form')!);
}

describe('«تذكرني» on the sign-in page', () => {
    it('is a real checkbox, off by default, under the password and above «دخول»', () => {
        const { container } = render(<Login />);
        const box = screen.getByRole('checkbox', { name: 'تذكرني' }) as HTMLInputElement;

        expect(box.checked).toBe(false);
        expect(box.disabled).toBe(false);
        expect(box.tabIndex).not.toBe(-1); // reachable with Tab, ticked with Space like any checkbox
        const password = container.querySelector('input[type="password"]')!;
        const submit = screen.getByRole('button', { name: 'دخول' });
        expect(password.compareDocumentPosition(box) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(box.compareDocumentPosition(submit) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it('sends remember: false when left alone', () => {
        const { container } = render(<Login />);
        signIn(container);

        expect(posted).toHaveBeenCalledWith('/login.store', { email: 'owner@example.com', password: 'secret-pass', remember: false });
    });

    it('sends remember: true once ticked, by a click on its words too, and false again once unticked', () => {
        const { container } = render(<Login />);
        fireEvent.click(screen.getByText('تذكرني'));
        expect((screen.getByRole('checkbox', { name: 'تذكرني' }) as HTMLInputElement).checked).toBe(true);
        signIn(container);
        expect(posted).toHaveBeenLastCalledWith('/login.store', expect.objectContaining({ remember: true }));

        fireEvent.click(screen.getByRole('checkbox', { name: 'تذكرني' }));
        signIn(container);
        expect(posted).toHaveBeenLastCalledWith('/login.store', expect.objectContaining({ remember: false }));
    });

    it('keeps neither the e-mail nor the password in the browser', () => {
        const { container } = render(<Login />);
        fireEvent.click(screen.getByText('تذكرني'));
        signIn(container);

        expect(localStorage.length).toBe(0);
        expect(sessionStorage.length).toBe(0);
        expect(document.cookie).toBe('');
    });
});
