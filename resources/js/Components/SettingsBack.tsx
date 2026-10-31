import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

/** The way back to the settings hub, shown above every settings screen. */
export default function SettingsBack() {
    return (
        <Link href={route('club.settings.index')} className="mb-5 inline-flex items-center gap-1.5 text-sm font-medium text-[#6b7280] transition-colors hover:text-[#111]">
            <ChevronRight className="size-4 ltr:rotate-180" /> كل الإعدادات
        </Link>
    );
}
