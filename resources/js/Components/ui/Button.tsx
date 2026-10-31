import { Link } from '@inertiajs/react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { LoaderCircle } from 'lucide-react';
import { cn } from '@/lib/cn';
import { useAdmin } from './AdminScope';

const variants = {
    primary: 'bg-brand text-white hover:bg-brand-600 shadow-sm shadow-brand/20',
    secondary: 'bg-brand-50 text-brand-700 hover:bg-brand-100',
    outline: 'border border-line bg-white text-ink hover:border-brand-300 hover:bg-brand-50',
    ghost: 'text-ink hover:bg-brand-50',
    danger: 'bg-red-600 text-white hover:bg-red-700',
    success: 'bg-emerald-600 text-white hover:bg-emerald-700',
};

const sizes = {
    sm: 'h-8 px-3 text-xs gap-1.5 rounded-lg',
    md: 'h-10 px-4 text-sm gap-2 rounded-xl',
    lg: 'h-12 px-6 text-base gap-2 rounded-xl',
    icon: 'size-10 rounded-xl',
};

/* The panel and the point of sale (AdminScope): quiet neutral buttons with a 10px radius, near-black primary. */
const adminVariants: Record<keyof typeof variants, string> = {
    primary: 'bg-brand text-white hover:bg-brand-600',
    secondary: 'bg-[#f2f2f0] text-ink hover:bg-[#e9e9e6]',
    outline: 'border border-[#dcdcdc] bg-white text-ink hover:bg-[#fafafa]',
    ghost: 'text-[#4b4b4b] hover:bg-ink/[0.045] hover:text-ink',
    danger: 'bg-[#dc2626] text-white hover:bg-[#b91c1c]',
    success: 'bg-[#059669] text-white hover:bg-[#047857]',
};

/* The button sets its icon's size, whatever the page passed: 16px small, 18px medium, 20px large. On a touch screen
   small and medium buttons grow to 44px. */
const adminSizes: Record<keyof typeof sizes, string> = {
    sm: 'h-8 px-3 text-[13px] gap-1.5 rounded-[10px] [&_svg]:size-4 touch:h-11 touch:px-4',
    md: 'h-10 px-4 text-sm gap-2 rounded-[10px] [&_svg]:size-[18px] touch:h-11',
    lg: 'h-12 px-6 text-[15px] gap-2 rounded-[10px] [&_svg]:size-5',
    icon: 'size-10 rounded-[10px] [&_svg]:size-[18px] touch:size-11',
};

type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: keyof typeof variants;
    size?: keyof typeof sizes;
    href?: string;
    loading?: boolean;
    icon?: ReactNode;
    external?: boolean;
};

export default function Button({ variant = 'primary', size = 'md', href, loading, icon, className, children, disabled, external, ...rest }: Props) {
    const admin = useAdmin();
    const classes = cn(
        'relative inline-flex shrink-0 items-center justify-center font-medium whitespace-nowrap transition disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#111]/15 [&_svg]:shrink-0',
        admin ? adminVariants[variant] : variants[variant],
        admin ? adminSizes[size] : sizes[size],
        className,
    );
    // Icon-only links still need an accessible name.
    const linkProps = { 'aria-label': rest['aria-label'], title: rest.title };
    // Loading keeps the button's width: its content fades out under a centred spinner.
    const content = loading ? (
        <>
            <span className="contents [&>*]:opacity-0">
                {icon && <span className="inline-flex">{icon}</span>}
                {children !== undefined && <span>{children}</span>}
            </span>
            <LoaderCircle className="absolute inset-0 m-auto size-4 animate-spin" />
        </>
    ) : (
        <>
            {icon}
            {children}
        </>
    );

    if (href && external) {
        return (
            <a href={href} className={classes} target="_blank" rel="noreferrer" {...linkProps}>
                {content}
            </a>
        );
    }
    if (href) {
        return (
            <Link href={href} className={classes} {...linkProps}>
                {content}
            </Link>
        );
    }
    return (
        <button className={classes} disabled={disabled || loading} {...rest}>
            {content}
        </button>
    );
}
