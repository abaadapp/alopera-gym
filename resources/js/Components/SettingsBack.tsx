import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

/** The way back to the settings hub, shown above every settings screen. */
export default function SettingsBack() {
    return (
        <Link href={route('club.settings.index')} className="mb-2 inline-flex items-center gap-1 text-sm text-muted hover:text-ink">
            <ArrowRight className="size-4" /> الإعدادات
        </Link>
    );
}
