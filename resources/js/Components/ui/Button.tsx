import { Link } from '@inertiajs/react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/cn';

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
    const classes = cn(
        'inline-flex shrink-0 items-center justify-center font-medium whitespace-nowrap transition disabled:cursor-not-allowed disabled:opacity-60',
        variants[variant],
        sizes[size],
        className,
    );
    // Icon-only links still need an accessible name.
    const linkProps = { 'aria-label': rest['aria-label'], title: rest.title };
    const content = (
        <>
            {loading ? <Loader2 className="size-4 animate-spin" /> : icon}
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
