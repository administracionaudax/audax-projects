import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useState } from 'react';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

/**
 * Botones «subir» y «bajar» de un elemento de un catálogo ordenado. Accesibles por teclado (son
 * botones normales, con nombre que incluye el elemento) y sin arrastrar. El foco se queda en el
 * botón pulsado: la fila conserva su clave y solo cambia de sitio; en los extremos el botón se
 * marca con aria-disabled (no con disabled, que le quitaría el foco).
 */
export function ReorderButtons({
    name,
    url,
    isFirst,
    isLast,
}: {
    name: string;
    /** URL de POST …/mover (Wayfinder). */
    url: string;
    isFirst: boolean;
    isLast: boolean;
}) {
    const [processing, setProcessing] = useState(false);

    const move = (direction: 'up' | 'down') => {
        if (processing || (direction === 'up' ? isFirst : isLast)) {
            return;
        }

        router.post(
            url,
            { direction },
            {
                preserveScroll: true,
                preserveState: true,
                onError: toastVisitErrors,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div className="flex gap-1">
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-8 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                aria-disabled={isFirst || undefined}
                aria-label={t('admin.reorder.up', { name })}
                onClick={() => move('up')}
            >
                <ArrowUp aria-hidden="true" />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-8 aria-disabled:cursor-not-allowed aria-disabled:opacity-50"
                aria-disabled={isLast || undefined}
                aria-label={t('admin.reorder.down', { name })}
                onClick={() => move('down')}
            >
                <ArrowDown aria-hidden="true" />
            </Button>
        </div>
    );
}
