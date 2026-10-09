import { router } from '@inertiajs/react';
import { SignalHigh, SignalLow, SignalMedium, Undo2 } from 'lucide-react';
import type { SearchableGroup } from '@/components/domain/searchable-select';
import { StatusBadge } from '@/components/styleguide/status-badges';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import type {
    ReviewConfidence,
    ReviewContactReason,
    ReviewLinkTarget,
} from '@/types';

/**
 * Confianza de una propuesta (I5, D-413): icono de cobertura y texto, nunca solo color. Alta en
 * verde (se puede aceptar en bloque), media en azul y baja en gris.
 */
export function ConfidenceBadge({
    confidence,
}: {
    confidence: ReviewConfidence;
}) {
    const meta = {
        alta: { tone: 'success', icon: SignalHigh },
        media: { tone: 'info', icon: SignalMedium },
        baja: { tone: 'neutral', icon: SignalLow },
    } as const;

    return (
        <StatusBadge tone={meta[confidence].tone} icon={meta[confidence].icon}>
            {t(`billing.review.confidence.${confidence}`)}
        </StatusBadge>
    );
}

/** Motivo de la propuesta de un contacto (NIF, nombre, nombre parecido, código F…). */
export function contactReason(reason: ReviewContactReason): string {
    return t(`billing.review.reason.${reason}`);
}

/** «Deshacer» de la última acción (D-414): una banda discreta con su texto y el botón. */
export function UndoBar({
    undo,
}: {
    undo: { message: string; count: number } | null;
}) {
    if (undo === null) {
        return null;
    }

    return (
        <div
            role="status"
            className="flex flex-wrap items-center justify-between gap-2 rounded-md border bg-muted px-3 py-2 text-sm"
            data-test="review-undo"
        >
            <span className="min-w-0">
                <span className="text-muted-foreground">
                    {t('billing.review.last_action')}
                </span>{' '}
                {undo.message}
            </span>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() =>
                    router.post(
                        '/facturacion/por-revisar/deshacer',
                        {},
                        { preserveScroll: true },
                    )
                }
                data-test="review-undo-button"
            >
                <Undo2 aria-hidden="true" />
                {t('billing.review.undo')}
            </Button>
        </div>
    );
}

/**
 * Opciones para enlazar una factura a mano (D-408 y D-413): primero los proyectos de su cliente y
 * después el resto. Un proyecto de bolsas ofrece cada bolsa (la más reciente primero); los demás,
 * el proyecto. El valor es «proyecto» o «proyecto:bolsa».
 */
export function targetGroups(
    targets: ReadonlyArray<ReviewLinkTarget>,
    clientId: number | null,
): SearchableGroup[] {
    const options = (target: ReviewLinkTarget) =>
        target.uses_banks && target.banks.length > 0
            ? target.banks.map((bank) => ({
                  value: `${target.id}:${bank.id}`,
                  label: `${target.code} · ${bank.name}`,
                  hint: target.client,
                  keywords: `${target.name} ${target.client ?? ''}`,
              }))
            : [
                  {
                      value: String(target.id),
                      label: `${target.code} · ${target.name}`,
                      hint: target.client,
                      keywords: target.client ?? '',
                  },
              ];

    const own = targets.filter(
        (target) => clientId !== null && target.client_id === clientId,
    );
    const rest = targets.filter(
        (target) => clientId === null || target.client_id !== clientId,
    );

    return [
        ...(own.length > 0
            ? [
                  {
                      label: t('billing.review.own_client'),
                      options: own.flatMap(options),
                  },
              ]
            : []),
        {
            label: own.length > 0 ? t('billing.review.other_projects') : null,
            options: rest.flatMap(options),
        },
    ];
}

/** «12:3» → { project_id: 12, hour_bank_id: 3 }; «12» → sin bolsa. */
export function parseTarget(value: string): {
    project_id: number;
    hour_bank_id: number | null;
} {
    const [project, bank] = value.split(':');

    return {
        project_id: Number(project),
        hour_bank_id: bank === undefined ? null : Number(bank),
    };
}
