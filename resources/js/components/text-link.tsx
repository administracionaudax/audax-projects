import { Link } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { FOCUS_RING } from '@/lib/focus-ring';
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
                'rounded-xs text-primary-text underline decoration-primary-text/40 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!',
                FOCUS_RING,
                className,
            )}
            {...props}
        >
            {children}
        </Link>
    );
}
