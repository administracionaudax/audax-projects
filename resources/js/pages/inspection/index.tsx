import { Head, Link } from '@inertiajs/react';
import { FileArchive } from 'lucide-react';
import { InspectionShell } from '@/components/inspection/inspection-shell';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { monthLabel, tCount } from '@/lib/people';
import { exportMethod, person as personRoute } from '@/routes/inspection';
import type { InspectionPortalAccess } from '@/types/people-register';

/**
 * Inicio del acceso de la Inspección (D-353): las personas y el periodo de su ámbito, el registro
 * de cada una mes a mes y la exportación completa (ZIP con el registro, la cadena, las correcciones,
 * los cierres, las anclas y las huellas). Cada consulta queda en la auditoría.
 */
export default function InspectionIndexPage({
    access,
    period,
    months,
    people,
}: {
    access: InspectionPortalAccess;
    period: { from: string; to: string };
    months: string[];
    people: { id: number; name: string }[];
}) {
    const last = months[months.length - 1];

    return (
        <InspectionShell access={access}>
            <Head title={t('people.portal.title')} />
            <header className="flex flex-wrap items-start justify-between gap-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-normal tracking-tight">
                        {t('people.portal.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('people.portal.period', {
                            from: formatDate(period.from),
                            to: formatDate(period.to),
                        })}
                    </p>
                </div>
                <Button asChild>
                    <a href={exportMethod.url()} data-test="portal-export">
                        <FileArchive aria-hidden="true" />
                        {t('people.portal.export')}
                    </a>
                </Button>
            </header>
            <section aria-labelledby="portal-people" className="grid gap-3">
                <h2 id="portal-people" className="text-lg">
                    {tCount('people.portal.people', people.length)}
                </h2>
                <ul
                    className="divide-y rounded-md border"
                    data-test="portal-people"
                >
                    {people.map((person) => (
                        <li
                            key={person.id}
                            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                        >
                            <span>{person.name}</span>
                            <span className="flex flex-wrap gap-1.5">
                                {months.map((month) => (
                                    <Link
                                        key={month}
                                        href={personRoute.url(person.id, {
                                            query: { mes: month },
                                        })}
                                        className="rounded-md border px-2 py-0.5 text-xs first-letter:uppercase hover:bg-accent"
                                        aria-label={t(
                                            'people.portal.open_month',
                                            {
                                                name: person.name,
                                                month: monthLabel(month),
                                            },
                                        )}
                                        data-test={
                                            month === last
                                                ? 'portal-person-latest'
                                                : undefined
                                        }
                                    >
                                        {monthLabel(month)}
                                    </Link>
                                ))}
                            </span>
                        </li>
                    ))}
                </ul>
            </section>
        </InspectionShell>
    );
}
