import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function Section({
    id,
    title,
    description,
    children,
}: {
    id: string;
    title: string;
    description?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section
            id={id}
            aria-labelledby={`${id}-titulo`}
            className="scroll-mt-20 border-t py-10 first:border-t-0 first:pt-0"
        >
            <h2 id={`${id}-titulo`} className="text-2xl">
                {title}
            </h2>
            {description ? (
                <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                    {description}
                </p>
            ) : null}
            <div className="mt-6 grid gap-8">{children}</div>
        </section>
    );
}

export function Specimen({
    title,
    note,
    children,
    className,
}: {
    title: string;
    note?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className="grid min-w-0 gap-3">
            <div>
                <h3 className="text-base font-medium">{title}</h3>
                {note ? (
                    <p className="text-sm text-muted-foreground">{note}</p>
                ) : null}
            </div>
            <div
                className={cn(
                    'min-w-0 rounded-md border p-4 sm:p-6',
                    className,
                )}
            >
                {children}
            </div>
        </div>
    );
}
