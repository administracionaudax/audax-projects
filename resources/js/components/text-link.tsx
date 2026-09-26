import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';

type Props = ComponentProps<typeof Link>;

export default function TextLink({
    className = '',
    children,
    ...props
}: Props) {
    return (
        <Link
            className={cn(
                'rounded-xs text-primary-text underline decoration-primary-text/40 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
