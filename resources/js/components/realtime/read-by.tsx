import { Check, CheckCheck } from 'lucide-react';
import type { ReadParticipant } from '@/hooks/use-realtime-reads';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Nombres para el texto: «Ana», «Ana y Luis». */
function names(readers: ReadParticipant[]): string {
    if (readers.length === 1) {
        return readers[0].name;
    }

    return t('realtime.read.and', {
        first: readers
            .slice(0, -1)
            .map((reader) => reader.name)
            .join(', '),
        second: readers[readers.length - 1].name,
    });
}

/**
 * Texto de «leído por» (SPEC §12): «Enviado» si nadie lo ha leído, «Leído» o «Leído por todos»
 * si lo han leído todos, y si no, los nombres (hasta dos) o «Leído por Ana, Luis y 3 más».
 */
export function readByText(
    readers: ReadParticipant[],
    recipients: number,
    direct = false,
): string {
    if (readers.length === 0) {
        return t('realtime.read.sent');
    }

    if (readers.length >= recipients) {
        return direct ? t('realtime.read.read') : t('realtime.read.all');
    }

    if (readers.length <= 2) {
        return t('realtime.read.some', { names: names(readers) });
    }

    return t('realtime.read.some_more', {
        names: readers
            .slice(0, 2)
            .map((reader) => reader.name)
            .join(', '),
        count: readers.length - 2,
    });
}

/**
 * «Leído por» bajo un mensaje propio (useReadReceipts().readersOf(id, autor) y recipientsOf(autor)).
 * Icono y texto; al pasar por encima (o con el lector de pantalla), la lista completa.
 */
export function ReadBy({
    readers,
    recipients,
    direct = false,
    className,
}: {
    readers: ReadParticipant[];
    recipients: number;
    /** Conversación directa: «Leído» en lugar de «Leído por todos». */
    direct?: boolean;
    className?: string;
}) {
    if (recipients <= 0) {
        return null;
    }

    const read = readers.length > 0;
    const Icon = read ? CheckCheck : Check;
    const text = readByText(readers, recipients, direct);
    const full = read
        ? t('realtime.read.list', {
              names: readers.map((reader) => reader.name).join(', '),
          })
        : text;

    return (
        <span
            title={full}
            data-read={read ? 'true' : 'false'}
            className={cn(
                'inline-flex items-center gap-1 text-xs text-muted-foreground',
                className,
            )}
        >
            <Icon
                aria-hidden="true"
                className={cn('size-3.5 shrink-0', read && 'text-info')}
            />
            <span aria-hidden={full !== text ? true : undefined}>{text}</span>
            {full !== text ? <span className="sr-only">{full}</span> : null}
        </span>
    );
}
