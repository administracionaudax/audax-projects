import { useImperativeHandle, useState } from 'react';
import type { Ref } from 'react';
import { UserAvatar } from '@/components/tasks/task-fields';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export type MentionItem = { id: number; name: string; avatar: string | null };

export type MentionListHandle = {
    onKeyDown: (event: KeyboardEvent) => boolean;
};

export type MentionListProps = {
    items: MentionItem[];
    command: (item: { id: string; label: string }) => void;
    ref?: Ref<MentionListHandle>;
};

/**
 * Sugerencias de @menciones del editor (personas internas activas). Se maneja con el teclado:
 * flechas para elegir, Intro o Tab para mencionar y Escape para cerrar.
 */
export function MentionList({ items, command, ref }: MentionListProps) {
    const [selected, setSelected] = useState(0);
    const [lastItems, setLastItems] = useState(items);

    if (items !== lastItems) {
        setLastItems(items);
        setSelected(0);
    }

    const choose = (index: number) => {
        const item = items[index];

        if (item) {
            command({ id: String(item.id), label: item.name });
        }
    };

    useImperativeHandle(ref, () => ({
        onKeyDown: (event: KeyboardEvent) => {
            if (items.length === 0) {
                return false;
            }

            if (event.key === 'ArrowDown') {
                setSelected((index) => (index + 1) % items.length);

                return true;
            }

            if (event.key === 'ArrowUp') {
                setSelected(
                    (index) => (index + items.length - 1) % items.length,
                );

                return true;
            }

            if (event.key === 'Enter' || event.key === 'Tab') {
                choose(selected);

                return true;
            }

            return false;
        },
    }));

    return (
        <div
            className="w-64 overflow-hidden rounded-[3px] border bg-popover text-popover-foreground shadow-md"
            data-mention-popup
        >
            {items.length === 0 ? (
                <p className="px-3 py-2 text-sm text-muted-foreground">
                    {t('rich_text.no_people')}
                </p>
            ) : (
                <ul
                    role="listbox"
                    aria-label={t('rich_text.mention_list')}
                    className="max-h-60 overflow-y-auto p-1"
                >
                    {items.map((item, index) => (
                        <li
                            key={item.id}
                            role="option"
                            aria-selected={index === selected}
                            className={cn(
                                'flex cursor-pointer items-center gap-2 rounded-[3px] px-2 py-1.5 text-sm',
                                index === selected &&
                                    'bg-accent text-accent-foreground',
                            )}
                            onMouseDown={(event) => {
                                // Que el editor no pierda el foco antes de elegir.
                                event.preventDefault();
                                choose(index);
                            }}
                            onMouseEnter={() => setSelected(index)}
                        >
                            <UserAvatar user={item} />
                            <span className="truncate">{item.name}</span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
