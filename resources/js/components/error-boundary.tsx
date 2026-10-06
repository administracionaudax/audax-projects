import { router } from '@inertiajs/react';
import { CircleAlert, Home, RotateCw } from 'lucide-react';
import { Component } from 'react';
import type { ErrorInfo, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { t } from '@/lib/i18n';

type State = { error: Error | null };

/**
 * Pantalla de error del navegador (F-017, 10.9b; `ws:components/ErrorBoundary.tsx`): si una página
 * de React falla al pintarse, en vez de quedarse en blanco enseña qué ha pasado con «Recargar» e
 * «Ir al inicio». Se rearma al navegar a otra página. Las páginas de error del servidor (403, 404,
 * 500…) siguen en pages/error.tsx.
 */
export class ErrorBoundary extends Component<{ children: ReactNode }, State> {
    state: State = { error: null };

    private offNavigate: (() => void) | null = null;

    static getDerivedStateFromError(error: Error): State {
        return { error };
    }

    componentDidMount(): void {
        this.offNavigate = router.on('navigate', () => {
            if (this.state.error !== null) {
                this.setState({ error: null });
            }
        });
    }

    componentWillUnmount(): void {
        this.offNavigate?.();
    }

    componentDidCatch(error: Error, info: ErrorInfo): void {
        // En la consola del navegador, para quien depura; no se envía a ningún sitio (sin terceros).
        console.error(error, info.componentStack);
    }

    render(): ReactNode {
        if (this.state.error === null) {
            return this.props.children;
        }

        return (
            <main
                className="flex min-h-svh items-center justify-center bg-background p-6"
                data-test="error-boundary"
            >
                <div
                    role="alert"
                    className="grid max-w-md justify-items-center gap-4 border bg-card p-8 text-center"
                >
                    <CircleAlert
                        aria-hidden="true"
                        className="size-10 text-danger"
                        strokeWidth={1.5}
                    />
                    <h1 className="text-xl">{t('error_boundary.title')}</h1>
                    <p className="text-sm text-muted-foreground">
                        {t('error_boundary.description')}
                    </p>
                    <div className="flex flex-wrap justify-center gap-2">
                        <Button
                            type="button"
                            onClick={() => window.location.reload()}
                        >
                            <RotateCw aria-hidden="true" />
                            {t('error_boundary.reload')}
                        </Button>
                        <Button asChild variant="outline">
                            <a href="/">
                                <Home aria-hidden="true" />
                                {t('error_boundary.home')}
                            </a>
                        </Button>
                    </div>
                </div>
            </main>
        );
    }
}
