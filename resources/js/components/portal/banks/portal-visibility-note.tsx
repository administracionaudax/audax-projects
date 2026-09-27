import { Info } from 'lucide-react';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PortalEntryVisibility } from '@/types/portal';

/**
 * Nota breve de qué horas ve el cliente (D-064): las aprobadas o, si su ajuste lo permite, también
 * las enviadas. Por dentro la bolsa puede ir más avanzada; esta nota lo explica. Con icono y texto.
 */
export function PortalVisibilityNote({
    visibility,
    className,
}: {
    visibility: PortalEntryVisibility;
    className?: string;
}) {
    return (
        <p
            className={cn(
                'flex items-start gap-2 rounded-[3px] bg-info-soft px-3 py-2 text-sm text-foreground',
                className,
            )}
            data-test="portal-visibility-note"
        >
            <Info
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-info"
            />
            {t(`portal_banks.note.${visibility}`)}
        </p>
    );
}
