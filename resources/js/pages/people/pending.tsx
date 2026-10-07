import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Inbox } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { CorrectionCard } from '@/components/people/correction-card';
import { PeopleFrame } from '@/components/people/people-ui';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { tCount } from '@/lib/people';
import { index as teamAbsencesIndex } from '@/routes/absences/team';
import { acceptMany } from '@/routes/people/corrections';
import { index as pendingIndex } from '@/routes/people/pending';
import type { PendingPageProps } from '@/types/people';

/**
 * «Pendientes» (`/personas/pendientes`, PLAN-FASE-11 §6.2.2; D-341; W-081): las correcciones del
 * registro que esperan la conformidad de quien mira como responsable o RR. HH., una a una o en
 * bloque, como el panel de Woffu. Las ausencias por aprobar siguen en «Ausencias del equipo».
 */
export default function PendingPage({ corrections }: PendingPageProps) {
    const { auth } = usePage().props;
    const [selected, setSelected] = useState<number[]>([]);
    const [processing, setProcessing] = useState(false);
    const decidable = corrections.filter((correction) => correction.can.decide);
    const allSelected =
        decidable.length > 0 && selected.length === decidable.length;

    const toggle = (id: number, checked: boolean) =>
        setSelected((current) =>
            checked
                ? [...new Set([...current, id])]
                : current.filter((candidate) => candidate !== id),
        );

    return (
        <>
            <Head title={t('people.pending.title')} />
            <PeopleFrame
                section="pending"
                title={t('people.pending.title')}
                description={t('people.pending.description')}
                actions={
                    auth.can.viewTeamAbsences ? (
                        <Button asChild variant="outline">
                            <Link href={teamAbsencesIndex.url()}>
                                {t('people.pending.absences_link')}
                            </Link>
                        </Button>
                    ) : null
                }
            >
                {corrections.length === 0 ? (
                    <EmptyState
                        icon={Inbox}
                        title={t('people.pending.empty')}
                        description={t('people.pending.empty_description')}
                    />
                ) : (
                    <section className="grid gap-3" data-test="pending-list">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={allSelected}
                                    onCheckedChange={(checked) =>
                                        setSelected(
                                            checked === true
                                                ? decidable.map(
                                                      (correction) =>
                                                          correction.id,
                                                  )
                                                : [],
                                        )
                                    }
                                    data-test="pending-select-all"
                                />
                                {t('people.pending.select_all')}
                            </label>
                            <Button
                                type="button"
                                disabled={selected.length === 0 || processing}
                                onClick={() =>
                                    router.post(
                                        acceptMany.url(),
                                        { ids: selected },
                                        {
                                            preserveScroll: true,
                                            onStart: () => setProcessing(true),
                                            onFinish: () =>
                                                setProcessing(false),
                                            onSuccess: () => setSelected([]),
                                        },
                                    )
                                }
                                data-test="pending-accept-selected"
                            >
                                {processing ? (
                                    <Spinner />
                                ) : (
                                    <Check aria-hidden="true" />
                                )}
                                {tCount(
                                    'people.pending.accept_selected',
                                    Math.max(selected.length, 1),
                                )}
                            </Button>
                        </div>
                        {corrections.map((correction) => (
                            <CorrectionCard
                                key={correction.id}
                                correction={correction}
                                showPerson
                                selectable={
                                    correction.can.decide ? (
                                        <Checkbox
                                            className="mt-0.5"
                                            checked={selected.includes(
                                                correction.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                toggle(
                                                    correction.id,
                                                    checked === true,
                                                )
                                            }
                                            aria-label={t(
                                                'people.pending.select',
                                                {
                                                    name: correction.user.name,
                                                    date: formatDate(
                                                        correction.date,
                                                    ),
                                                },
                                            )}
                                            data-test="pending-select"
                                        />
                                    ) : null
                                }
                            />
                        ))}
                    </section>
                )}
            </PeopleFrame>
        </>
    );
}

PendingPage.layout = {
    breadcrumbs: [{ title: t('people.pending.title'), href: pendingIndex() }],
};
