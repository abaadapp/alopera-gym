import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

const pages = import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx');

/** Apply the club's brand colours on every navigation (they come with the shared `tenant` prop). */
function applyBrand(props: Record<string, unknown>) {
    const tenant = props.tenant as { primary_color?: string; secondary_color?: string } | null;
    const root = document.documentElement;
    if (tenant?.primary_color) root.style.setProperty('--brand', tenant.primary_color);
    if (tenant?.secondary_color) root.style.setProperty('--brand-soft', tenant.secondary_color);
}

/** After a failed submit, bring the first invalid field into view (long forms on phones would otherwise hide it). */
function revealFirstError() {
    setTimeout(() => {
        const field = document.querySelector<HTMLElement>('[aria-invalid="true"]');
        if (!field) return;
        const r = field.getBoundingClientRect();
        if (r.top < 0 || r.bottom > window.innerHeight) field.scrollIntoView({ block: 'center', behavior: 'smooth' });
        field.focus({ preventScroll: true });
    }, 50);
}

createInertiaApp({
    title: (title) => title || 'Alopera Gym',
    resolve: async (name) => {
        const page = pages[`./Pages/${name}.tsx`];
        if (!page) throw new Error(`Page not found: ${name}`);
        return (await page()).default;
    },
    setup({ el, App, props }) {
        applyBrand(props.initialPage.props as Record<string, unknown>);
        router.on('navigate', (event) => applyBrand(event.detail.page.props as Record<string, unknown>));
        router.on('error', revealFirstError);
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: '#be185d', showSpinner: false },
});
