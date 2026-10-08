import { router } from '@inertiajs/react';
import { useId } from 'react';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BillingService, ReportQuery } from '@/types';
import { serviceLabel } from './invoicing-lib';

/**
 * Servicio del informe de facturación (D-400): varios a la vez, en la URL (?servicio[]=…), en la
 * misma línea que el tipo de venta de «Vendido frente a real». Ninguno marcado = todos.
 */
export function ServiceFilters({
    url,
    query,
    services,
    selected,
}: {
    url: string;
    query: ReportQuery;
    services: BillingService[];
    selected: BillingService[];
}) {
    const id = useId();

    const visit = (next: BillingService[]) => {
        const params: ReportQuery = { ...query, servicio: next };
        if (next.length === 0 || next.length === services.length) {
            delete params.servicio;
        }
        router.get(url, params, { preserveState: true, preserveScroll: true });
    };

    const toggle = (service: BillingService) =>
        visit(
            selected.includes(service)
                ? selected.filter((item) => item !== service)
                : [...selected, service],
        );

    return (
        <div
            role="group"
            aria-labelledby={`${id}-service`}
            className="grid gap-1"
        >
            <span id={`${id}-service`} className="text-sm font-medium">
                {t('billing.invoicing.filters.service')}
            </span>
            <div className="flex flex-wrap gap-1">
                <Button
                    type="button"
                    size="sm"
                    variant={selected.length === 0 ? 'default' : 'outline'}
                    aria-pressed={selected.length === 0}
                    onClick={() => visit([])}
                    className={cn(
                        selected.length > 0 && 'text-muted-foreground',
                    )}
                >
                    {t('billing.invoicing.filters.all_services')}
                </Button>
                {services.map((service) => {
                    const on = selected.includes(service);

                    return (
                        <Button
                            key={service}
                            type="button"
                            size="sm"
                            variant={on ? 'default' : 'outline'}
                            aria-pressed={on}
                            onClick={() => toggle(service)}
                            className={cn(!on && 'text-muted-foreground')}
                        >
                            {serviceLabel(service)}
                        </Button>
                    );
                })}
            </div>
        </div>
    );
}
