import { router } from '@inertiajs/react';
import { useId } from 'react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { t } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ReportQuery, SaleKind } from '@/types';
import { SALE_KINDS } from './sold-vs-actual-lib';

const ALL = '__all__';

/**
 * Tipo de venta (varios a la vez) y responsable de «Vendido frente a real» (D-390), en la misma fila
 * que la barra de filtros de los informes y en la URL (?venta[]=…&responsable=…).
 */
export function SaleFilters({
    url,
    query,
    kinds,
    manager,
    managers,
}: {
    url: string;
    query: ReportQuery;
    kinds: SaleKind[];
    manager: number | null;
    managers: { id: number; name: string }[];
}) {
    const id = useId();

    const visit = (patch: Partial<ReportQuery>) => {
        const next: ReportQuery = { ...query, ...patch };
        if (!next.venta || next.venta.length === 0) {
            delete next.venta;
        }
        if (!next.responsable) {
            delete next.responsable;
        }
        router.get(url, next, { preserveState: true, preserveScroll: true });
    };

    const toggle = (kind: SaleKind) => {
        const next = kinds.includes(kind)
            ? kinds.filter((item) => item !== kind)
            : [...kinds, kind];
        visit({ venta: next.length === SALE_KINDS.length ? [] : next });
    };

    return (
        <div className="flex flex-wrap items-end gap-x-6 gap-y-3">
            <div
                role="group"
                aria-labelledby={`${id}-kind`}
                className="grid gap-1"
            >
                <span id={`${id}-kind`} className="text-sm font-medium">
                    {t('billing.filters.kind')}
                </span>
                <div className="flex flex-wrap gap-1">
                    {SALE_KINDS.map((kind) => {
                        const on = kinds.includes(kind);

                        return (
                            <Button
                                key={kind}
                                type="button"
                                size="sm"
                                variant={on ? 'default' : 'outline'}
                                aria-pressed={on}
                                onClick={() => toggle(kind)}
                                className={cn(!on && 'text-muted-foreground')}
                            >
                                {t(`billing.kind.${kind}`)}
                            </Button>
                        );
                    })}
                </div>
            </div>

            {managers.length > 1 ? (
                <div className="grid w-full gap-1 sm:w-64">
                    <Label htmlFor={`${id}-manager`}>
                        {t('billing.filters.manager')}
                    </Label>
                    <Select
                        value={manager ? String(manager) : ALL}
                        onValueChange={(value) =>
                            visit({
                                responsable:
                                    value === ALL ? undefined : Number(value),
                            })
                        }
                    >
                        <SelectTrigger id={`${id}-manager`} className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>
                                {t('billing.filters.all_managers')}
                            </SelectItem>
                            {managers.map((option) => (
                                <SelectItem
                                    key={option.id}
                                    value={String(option.id)}
                                >
                                    {option.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            ) : null}
        </div>
    );
}
