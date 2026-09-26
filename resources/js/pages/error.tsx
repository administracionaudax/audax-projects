import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, House } from 'lucide-react';
import { AudaxWordmark } from '@/components/app-logo';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

type ErrorStatus = 403 | 404 | 419 | 429 | 500 | 503;

const MESSAGES = {
    403: {
        title: t('error_page.403.title'),
        description: t('error_page.403.description'),
    },
    404: {
        title: t('error_page.404.title'),
        description: t('error_page.404.description'),
    },
    419: {
        title: t('error_page.419.title'),
        description: t('error_page.419.description'),
    },
    429: {
        title: t('error_page.429.title'),
        description: t('error_page.429.description'),
    },
    500: {
        title: t('error_page.500.title'),
        description: t('error_page.500.description'),
    },
    503: {
        title: t('error_page.503.title'),
        description: t('error_page.503.description'),
    },
} satisfies Record<ErrorStatus, { title: string; description: string }>;

export function errorMessage(status: number): {
    title: string;
    description: string;
} {
    return status in MESSAGES ? MESSAGES[status as ErrorStatus] : MESSAGES[500];
}

/**
 * Página de error de la app (UX-03, SPEC §19): 403, 404, 419, 429, 500 y 503 en español y con
 * el tema de la app (App\Http\Responses\ErrorPage). Va sin el layout de la app: en un 404 de una
 * ruta que no existe no hay props compartidas (usuario, permisos…).
 */
export default function ErrorPage({ status }: { status: number }) {
    const { title, description } = errorMessage(status);

    return (
        <>
            <Head title={title} />

            <div className="flex min-h-dvh flex-col bg-background text-foreground">
                <header className="px-6 py-5 sm:px-10">
                    <Link
                        href="/"
                        className="inline-flex rounded-[3px]"
                        aria-label={t('brand.home_link')}
                    >
                        <AudaxWordmark className="h-5" />
                    </Link>
                </header>

                <main
                    id="contenido"
                    className="flex flex-1 items-start justify-center px-6 py-10 sm:items-center sm:px-10"
                >
                    <div
                        className="grid w-full max-w-md gap-4"
                        data-test="error-page"
                    >
                        <p className="text-sm text-muted-foreground">
                            {t('error_page.code', { status })}
                        </p>
                        <h1 className="text-2xl font-normal text-balance">
                            {title}
                        </h1>
                        <p className="text-muted-foreground">{description}</p>
                        <div className="flex flex-wrap gap-2 pt-2">
                            <Button asChild>
                                <Link href="/">
                                    <House aria-hidden="true" />
                                    {t('error_page.home')}
                                </Link>
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => window.history.back()}
                            >
                                <ArrowLeft aria-hidden="true" />
                                {t('error_page.back')}
                            </Button>
                        </div>
                    </div>
                </main>
            </div>
        </>
    );
}
