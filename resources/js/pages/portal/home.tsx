import { Head } from '@inertiajs/react';
import { FolderOpen, Wallet } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { KeywordText } from '@/components/keyword-text';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { firstName, useRequiredUser } from '@/hooks/use-auth';
import { t } from '@/lib/i18n';

/** Portal de cliente (SPEC §11). En la Fase 0 solo existe la estructura. */
export default function PortalHome() {
    const user = useRequiredUser();

    return (
        <>
            <Head title={t('portal.title')} />

            <div className="space-y-8">
                <header className="space-y-1">
                    <h1 className="text-3xl font-normal tracking-tight">
                        <KeywordText
                            text={t('portal.greeting', {
                                name: firstName(user),
                            })}
                        />
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('portal.subtitle')}
                    </p>
                </header>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card className="gap-4">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Wallet
                                    aria-hidden="true"
                                    className="size-4 text-muted-foreground"
                                    strokeWidth={1.5}
                                />
                                <h2>{t('portal.cards.hour_banks.title')}</h2>
                            </CardTitle>
                            <CardDescription>
                                {t('portal.cards.hour_banks.description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <EmptyState
                                title={t('portal.cards.hour_banks.empty')}
                                phase={5}
                            />
                        </CardContent>
                    </Card>
                    <Card className="gap-4">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FolderOpen
                                    aria-hidden="true"
                                    className="size-4 text-muted-foreground"
                                    strokeWidth={1.5}
                                />
                                <h2>{t('portal.cards.projects.title')}</h2>
                            </CardTitle>
                            <CardDescription>
                                {t('portal.cards.projects.description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <EmptyState
                                title={t('portal.cards.projects.empty')}
                                phase={5}
                            />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
