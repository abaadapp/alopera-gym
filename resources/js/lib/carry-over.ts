import { useEffect, useRef } from 'react';

/**
 * What a visitor typed in a form of the website, carried over the language switch. Switching loads the same page
 * again in the other language (a plain link, so the page comes back in its own direction), which would empty the
 * form: just before it goes, the switch announces itself and each form keeps its fields in this tab's
 * sessionStorage; the page in the other language puts them back once and forgets them. Passwords and the honeypot
 * are never kept. Nothing is kept on any other navigation.
 */
const EVENT = 'site:language-switch';
const PREFIX = 'site-carry:';

export function announceLanguageSwitch(): void {
    window.dispatchEvent(new Event(EVENT));
}

export function useCarryOver<T extends object>(name: string, data: T, restore: (saved: Partial<T>) => void, omit: string[] = []): void {
    const current = useRef(data);
    current.current = data;
    const skip = ['password', 'password_confirmation', 'current_password', 'website', ...omit];

    useEffect(() => {
        try {
            const raw = sessionStorage.getItem(PREFIX + name);
            if (raw) {
                sessionStorage.removeItem(PREFIX + name);
                restore(JSON.parse(raw) as Partial<T>);
            }
        } catch {
            // No storage (a private window): the form simply starts empty.
        }
        const keep = () => {
            try {
                const copy: Record<string, unknown> = { ...(current.current as Record<string, unknown>) };
                for (const key of skip) delete copy[key];
                sessionStorage.setItem(PREFIX + name, JSON.stringify(copy));
            } catch {
                // Nothing kept: the page in the other language starts with an empty form.
            }
        };
        window.addEventListener(EVENT, keep);
        return () => window.removeEventListener(EVENT, keep);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [name]);
}
