import { SmilePlus } from 'lucide-react';
import { EmojiPopover } from '@/components/chat/emoji-popover';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ChatReaction } from '@/types/chat';

/**
 * Reacciones de un mensaje (SPEC §12): agrupadas por emoji con su recuento; quién ha reaccionado,
 * en el tooltip y en el nombre accesible. Pulsar una reacción la pone o la quita.
 */
export function Reactions({
    reactions,
    canReact,
    onToggle,
}: {
    reactions: ChatReaction[];
    canReact: boolean;
    onToggle: (emoji: string) => void;
}) {
    if (reactions.length === 0) {
        return null;
    }

    return (
        <ul
            className="flex flex-wrap items-center gap-1"
            aria-label={t('chat.reactions.label')}
        >
            {reactions.map((reaction) => {
                const people = reaction.users.join(', ');
                const label = t(
                    reaction.reacted
                        ? 'chat.reactions.remove'
                        : 'chat.reactions.add_same',
                    { emoji: reaction.emoji, count: reaction.count, people },
                );

                return (
                    <li key={reaction.emoji}>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <button
                                    type="button"
                                    onClick={() => onToggle(reaction.emoji)}
                                    disabled={!canReact}
                                    aria-pressed={reaction.reacted}
                                    aria-label={label}
                                    className={cn(
                                        'inline-flex h-6 items-center gap-1 rounded-md border px-1.5 text-xs text-foreground disabled:cursor-default',
                                        reaction.reacted
                                            ? 'border-primary bg-accent'
                                            : 'bg-background enabled:hover:bg-accent',
                                        FOCUS_RING,
                                    )}
                                    data-test="chat-reaction"
                                >
                                    <span aria-hidden="true">
                                        {reaction.emoji}
                                    </span>
                                    <span
                                        aria-hidden="true"
                                        className="tabular"
                                    >
                                        {reaction.count}
                                    </span>
                                </button>
                            </TooltipTrigger>
                            <TooltipContent>{people}</TooltipContent>
                        </Tooltip>
                    </li>
                );
            })}
            {canReact ? (
                <li>
                    <EmojiPopover
                        quick
                        onSelect={onToggle}
                        trigger={
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-6"
                                aria-label={t('chat.reactions.add')}
                            >
                                <SmilePlus aria-hidden="true" />
                            </Button>
                        }
                    />
                </li>
            ) : null}
        </ul>
    );
}
