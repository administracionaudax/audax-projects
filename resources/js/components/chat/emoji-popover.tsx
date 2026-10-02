import { lazy, Suspense, useState } from 'react';
import type { ReactNode, RefObject } from 'react';
import { returnFocusTo } from '@/components/chat/return-focus';
import {
    Popover,
    PopoverAnchor,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Spinner } from '@/components/ui/spinner';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** El selector completo (frimousse y sus datos) solo se descarga al abrirlo por primera vez. */
const LazyEmojiPicker = lazy(() => import('@/components/chat/emoji-picker'));

/** Reacciones de un toque, antes del selector completo. */
export const QUICK_REACTIONS = ['👍', '❤️', '😂', '🎉', '👀', '🙏', '✅'];

/**
 * Popover con el selector de emojis (y, para reaccionar, las reacciones rápidas). Al elegir, se
 * cierra y devuelve el foco al botón que lo abrió (Radix). Con `anchor` en lugar de `trigger` se
 * abre desde fuera (p. ej. «Reaccionar» en el menú de acciones del mensaje): entonces el foco
 * vuelve a `returnFocus` (el botón de ese menú), porque la opción que lo abrió ya no existe.
 */
export function EmojiPopover({
    trigger,
    anchor,
    open: controlledOpen,
    onOpenChange,
    onSelect,
    quick = false,
    align = 'start',
    side = 'top',
    returnFocus,
}: {
    trigger?: ReactNode;
    anchor?: ReactNode;
    returnFocus?: RefObject<HTMLElement | null>;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    onSelect: (emoji: string) => void;
    quick?: boolean;
    align?: 'start' | 'center' | 'end';
    side?: 'top' | 'bottom';
}) {
    const [uncontrolledOpen, setUncontrolledOpen] = useState(false);
    const open = controlledOpen ?? uncontrolledOpen;
    const setOpen = (next: boolean) => {
        setUncontrolledOpen(next);
        onOpenChange?.(next);
    };
    const choose = (emoji: string) => {
        setOpen(false);
        onSelect(emoji);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            {trigger ? (
                <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            ) : (
                <PopoverAnchor asChild>{anchor}</PopoverAnchor>
            )}
            <PopoverContent
                align={align}
                side={side}
                className="w-auto p-0"
                onCloseAutoFocus={
                    returnFocus ? returnFocusTo(returnFocus) : undefined
                }
            >
                {quick ? (
                    <div
                        role="group"
                        aria-label={t('chat.reactions.quick')}
                        className="flex flex-wrap gap-0.5 border-b p-1.5"
                    >
                        {QUICK_REACTIONS.map((emoji) => (
                            <button
                                key={emoji}
                                type="button"
                                onClick={() => choose(emoji)}
                                aria-label={t('chat.reactions.react_with', {
                                    emoji,
                                })}
                                className={cn(
                                    'flex size-9 items-center justify-center rounded-[3px] text-xl hover:bg-accent',
                                    FOCUS_RING,
                                )}
                            >
                                {emoji}
                            </button>
                        ))}
                    </div>
                ) : null}
                {open ? (
                    <Suspense
                        fallback={
                            <div className="flex h-80 w-[min(20rem,calc(100vw-2rem))] items-center justify-center gap-2 text-sm text-muted-foreground">
                                <Spinner />
                                {t('chat.emoji.loading')}
                            </div>
                        }
                    >
                        <LazyEmojiPicker onSelect={choose} autoFocus={!quick} />
                    </Suspense>
                ) : null}
            </PopoverContent>
        </Popover>
    );
}
