import { FileDown, X } from 'lucide-react';
import { useId } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import { useListFilters } from '@/components/admin/use-list-filters';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t } from '@/lib/i18n';
import { index as auditIndex } from '@/routes/admin/audit';
import type { AuditFiltersValue, AuditPageProps } from '@/types/audit';

/** Valor del filtro «persona» para los cambios sin persona (comandos y tareas programadas). */
export const SYSTEM_PERSON = 'sistema';

/** Props que se recargan al cambiar un filtro (el resto de la página no cambia). */
const RELOAD = ['entries', 'pagination', 'filters', 'exportUrl'];

/**
 * Filtros de la auditoría (D-074) en la URL: entidad, persona, acción y fechas (días de Madrid).
 * Cada cambio se aplica al momento, sin llenar el historial. Incluye la exportación a CSV con los
 * mismos filtros.
 */
export function AuditFilters({
    filters: initial,
    options,
    exportUrl,
}: {
    filters: AuditFiltersValue;
    options: AuditPageProps['options'];
    exportUrl: string;
}) {
    const id = useId();
    const { filters, update, reset } = useListFilters(
        auditIndex.url(),
        { ...initial },
        {},
        RELOAD,
    );
    const filtered = Object.values(filters).some(
        (value) => value !== null && value !== '',
    );
    const basic = options.actions.filter((action) => action.basic);
    const others = options.actions.filter((action) => !action.basic);

    return (
        <div className="grid gap-3">
            <form
                role="search"
                aria-label={t('audit.filters.label')}
                className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[repeat(3,minmax(0,1fr))_repeat(2,minmax(0,11rem))] lg:items-end"
                onSubmit={(event) => event.preventDefault()}
            >
                <div className="grid gap-2">
                    <Label htmlFor={`${id}-entity`}>
                        {t('audit.filters.entity')}
                    </Label>
                    <NativeSelect
                        id={`${id}-entity`}
                        value={filters.entidad ?? ''}
                        onChange={(event) =>
                            update('entidad', event.target.value || null)
                        }
                    >
                        <option value="">
                            {t('audit.filters.all_entities')}
                        </option>
                        {options.entities.map((entity) => (
                            <option key={entity.value} value={entity.value}>
                                {entity.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`${id}-person`}>
                        {t('audit.filters.person')}
                    </Label>
                    <NativeSelect
                        id={`${id}-person`}
                        value={filters.persona ?? ''}
                        onChange={(event) =>
                            update('persona', event.target.value || null)
                        }
                    >
                        <option value="">
                            {t('audit.filters.all_people')}
                        </option>
                        <option value={SYSTEM_PERSON}>
                            {t('audit.filters.system')}
                        </option>
                        {options.people.map((person) => (
                            <option key={person.id} value={String(person.id)}>
                                {person.is_active
                                    ? person.name
                                    : t('audit.filters.inactive_person', {
                                          name: person.name,
                                      })}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`${id}-action`}>
                        {t('audit.filters.action')}
                    </Label>
                    <NativeSelect
                        id={`${id}-action`}
                        value={filters.accion ?? ''}
                        onChange={(event) =>
                            update('accion', event.target.value || null)
                        }
                    >
                        <option value="">
                            {t('audit.filters.all_actions')}
                        </option>
                        {basic.map((action) => (
                            <option key={action.value} value={action.value}>
                                {action.label}
                            </option>
                        ))}
                        {others.length > 0 ? (
                            <optgroup label={t('audit.filters.other_actions')}>
                                {others.map((action) => (
                                    <option
                                        key={action.value}
                                        value={action.value}
                                    >
                                        {action.label}
                                    </option>
                                ))}
                            </optgroup>
                        ) : null}
                    </NativeSelect>
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`${id}-from`}>
                        {t('audit.filters.from')}
                    </Label>
                    <Input
                        id={`${id}-from`}
                        type="date"
                        value={filters.desde ?? ''}
                        max={filters.hasta ?? undefined}
                        onChange={(event) =>
                            update('desde', event.target.value || null)
                        }
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor={`${id}-to`}>{t('audit.filters.to')}</Label>
                    <Input
                        id={`${id}-to`}
                        type="date"
                        value={filters.hasta ?? ''}
                        min={filters.desde ?? undefined}
                        onChange={(event) =>
                            update('hasta', event.target.value || null)
                        }
                    />
                </div>
            </form>

            <div className="flex flex-wrap items-center gap-2">
                {filtered ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={reset}
                    >
                        <X aria-hidden="true" />
                        {t('audit.filters.clear')}
                    </Button>
                ) : null}
                <Button asChild variant="outline" size="sm">
                    {/* Descarga de fichero: enlace normal, no una visita de Inertia. */}
                    <a href={exportUrl} download data-test="audit-export">
                        <FileDown aria-hidden="true" />
                        {t('audit.export')}
                    </a>
                </Button>
            </div>
        </div>
    );
}
