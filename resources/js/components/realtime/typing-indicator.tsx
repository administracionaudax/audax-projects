import { PenLine } from 'lucide-react';
import type { TypingUser } from '@/hooks/use-realtime-typing';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** «Ana está escribiendo…», «Ana y Luis están escribiendo…» o «Varias personas…». */
export function typingText(typers: TypingUser[]): string {
    if (typers.length === 0) {
        return '';
    }

    if (typers.length === 1) {
        return t('realtime.typing.one', { name: typers[0].name });
    }

    if (typers.length === 2) {
        return t('realtime.typing.two', {
            first: typers[0].name,
            second: typers[1].name,
        });
    }

    return t('realtime.typing.many');
}

/**
 * Indicador de «escribiendo…» (useTyping). La región existe siempre (vacía si nadie escribe) para
 * que los lectores de pantalla anuncien los cambios con cortesía (aria-live="polite").
 */
export function TypingIndicator({
    typers,
    className,
}: {
    typers: TypingUser[];
    className?: string;
}) {
    const text = typingText(typers);

    return (
        <p
            aria-live="polite"
            aria-atomic="true"
            className={cn(
                'flex min-h-5 items-center gap-1.5 text-xs text-muted-foreground',
                className,
            )}
        >
            {text !== '' ? (
                <>
                    <PenLine
                        aria-hidden="true"
                        className="size-3.5 shrink-0 motion-safe:animate-pulse"
                    />
                    <span className="truncate">{text}</span>
                </>
            ) : null}
        </p>
    );
}
