import { EmojiPicker } from 'frimousse';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Selector de emojis completo (SPEC §12) con frimousse (MIT): búsqueda en español, categorías,
 * tonos de piel y navegación con teclado (rejilla ARIA). Los datos de Emojibase 16 (MIT) están
 * AUTOALOJADOS en public/emojibase/es (la CSP solo permite 'self': nada de CDN) y frimousse los
 * guarda en localStorage tras la primera carga. Se carga bajo demanda (emoji-popover.tsx).
 */

export const EMOJIBASE_URL = '/emojibase';

export default function ChatEmojiPicker({
    onSelect,
    autoFocus = true,
}: {
    onSelect: (emoji: string) => void;
    autoFocus?: boolean;
}) {
    return (
        <EmojiPicker.Root
            locale="es"
            emojibaseUrl={EMOJIBASE_URL}
            columns={8}
            onEmojiSelect={({ emoji }) => onSelect(emoji)}
            className="isolate flex h-80 w-[min(20rem,calc(100vw-2rem))] flex-col bg-popover"
            aria-label={t('chat.emoji.picker')}
        >
            <div className="flex items-center gap-2 p-2">
                <EmojiPicker.Search
                    autoFocus={autoFocus}
                    aria-label={t('chat.emoji.search')}
                    placeholder={t('chat.emoji.search_placeholder')}
                    className={cn(
                        'h-8 min-w-0 flex-1 rounded-[3px] border border-input bg-background px-2 text-sm text-foreground placeholder:text-muted-foreground',
                        FOCUS_RING,
                    )}
                />
                <EmojiPicker.SkinToneSelector
                    aria-label={t('chat.emoji.skin_tone')}
                    className={cn(
                        'flex size-8 shrink-0 items-center justify-center rounded-[3px] text-lg hover:bg-accent',
                        FOCUS_RING,
                    )}
                />
            </div>
            <EmojiPicker.Viewport className="relative flex-1 outline-none">
                <EmojiPicker.Loading className="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">
                    {t('chat.emoji.loading')}
                </EmojiPicker.Loading>
                <EmojiPicker.Empty className="absolute inset-0 flex items-center justify-center p-4 text-center text-sm text-muted-foreground">
                    {t('chat.emoji.empty')}
                </EmojiPicker.Empty>
                <EmojiPicker.List
                    className="pb-1.5 select-none"
                    components={{
                        CategoryHeader: ({ category, ...props }) => (
                            <div
                                {...props}
                                className="bg-popover px-3 pt-2 pb-1 text-xs text-muted-foreground first-letter:uppercase"
                            >
                                {category.label}
                            </div>
                        ),
                        Row: ({ children, ...props }) => (
                            <div {...props} className="scroll-my-1.5 px-1.5">
                                {children}
                            </div>
                        ),
                        Emoji: ({ emoji, ...props }) => (
                            <button
                                {...props}
                                type="button"
                                className={cn(
                                    'flex size-9 items-center justify-center rounded-[3px] text-xl',
                                    emoji.isActive && 'bg-accent',
                                )}
                            >
                                {emoji.emoji}
                            </button>
                        ),
                    }}
                />
            </EmojiPicker.Viewport>
        </EmojiPicker.Root>
    );
}
