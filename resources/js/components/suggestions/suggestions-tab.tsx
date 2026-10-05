import { Link } from '@inertiajs/react';
import { MessagesSquare, Rocket, Settings2 } from 'lucide-react';
import { useState } from 'react';
import { SuggestionComposer } from '@/components/suggestions/suggestion-composer';
import { SuggestionDetail } from '@/components/suggestions/suggestion-detail';
import { SuggestionFeed } from '@/components/suggestions/suggestion-feed';
import { SuggestionRoadmap } from '@/components/suggestions/suggestion-roadmap';
import { SuggestionTaxonomy } from '@/components/suggestions/suggestion-taxonomy';
import { Button } from '@/components/ui/button';
import { FOCUS_RING } from '@/lib/focus-ring';
import { t } from '@/lib/i18n';
import { currentQuery, suggestionParams } from '@/lib/suggestions';
import { cn } from '@/lib/utils';
import { index as helpIndex } from '@/routes/help';
import type { SuggestionsTabProps } from '@/types/weeklies';

/**
 * La pestaña «Sugerencias» de /ayuda (F-159 a F-169): Roadmap y Feedback (en la URL), el detalle de
 * una sugerencia (/ayuda/sugerencias/{id}), el formulario de nueva sugerencia (también en modo
 * «bug», F-149) y, para quien gestiona, los tableros y las categorías.
 */
export function SuggestionsTab({
    data,
    attachmentMaxMb,
}: {
    data: SuggestionsTabProps;
    attachmentMaxMb: number;
}) {
    const query = currentQuery(data);
    const [composer, setComposer] = useState<'default' | 'bug' | null>(
        data.composer,
    );
    const [requested, setRequested] = useState(data.composer);
    const [taxonomy, setTaxonomy] = useState(false);

    // «Reportar un bug» (o ?nueva=1) abre el formulario al llegar.
    if (requested !== data.composer) {
        setRequested(data.composer);
        setComposer(data.composer);
    }

    const views = [
        {
            id: 'roadmap' as const,
            label: t('suggestions.views.roadmap'),
            icon: Rocket,
        },
        {
            id: 'feedback' as const,
            label: t('suggestions.views.feedback'),
            icon: MessagesSquare,
        },
    ];

    return (
        <div className="grid gap-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <nav
                    aria-label={t('suggestions.views.label')}
                    className="flex gap-1 border p-1"
                >
                    {views.map((view) => {
                        const active =
                            data.post === null && data.view === view.id;
                        const Icon = view.icon;

                        return (
                            <Link
                                key={view.id}
                                href={helpIndex.url({
                                    query: suggestionParams({
                                        ...query,
                                        view: view.id,
                                        limit: null,
                                    }),
                                })}
                                only={['suggestions', 'tab']}
                                preserveState
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'inline-flex items-center gap-1.5 px-3 py-1.5 text-sm',
                                    active
                                        ? 'bg-accent font-medium'
                                        : 'text-muted-foreground hover:text-foreground',
                                    FOCUS_RING,
                                )}
                                data-test={`suggestions-view-${view.id}`}
                            >
                                <Icon aria-hidden="true" className="size-4" />
                                {view.label}
                            </Link>
                        );
                    })}
                </nav>
                {data.can.manage_boards ? (
                    <Button
                        variant="secondary"
                        onClick={() => setTaxonomy(true)}
                    >
                        <Settings2 aria-hidden="true" />
                        {t('suggestions.taxonomy.open')}
                    </Button>
                ) : null}
            </div>
            {data.post ? (
                <SuggestionDetail
                    data={data}
                    post={data.post}
                    query={query}
                    attachmentMaxMb={attachmentMaxMb}
                />
            ) : data.view === 'feedback' ? (
                <SuggestionFeed
                    data={data}
                    query={query}
                    onCreate={() => setComposer('default')}
                />
            ) : (
                <SuggestionRoadmap data={data} query={query} />
            )}
            {data.can.create ? (
                <SuggestionComposer
                    data={data}
                    post={null}
                    mode={composer ?? 'default'}
                    open={composer !== null}
                    onOpenChange={(open) =>
                        setComposer(open ? (composer ?? 'default') : null)
                    }
                    attachmentMaxMb={attachmentMaxMb}
                />
            ) : null}
            {data.can.manage_boards ? (
                <SuggestionTaxonomy
                    boards={data.boards}
                    open={taxonomy}
                    onOpenChange={setTaxonomy}
                />
            ) : null}
        </div>
    );
}
