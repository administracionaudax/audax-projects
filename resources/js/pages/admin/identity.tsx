import { Head, router, useForm } from '@inertiajs/react';
import { ImageUp, Trash2 } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { toastVisitErrors } from '@/components/admin/visit-errors';
import { AudaxWordmark } from '@/components/app-logo';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { KeywordText } from '@/components/keyword-text';
import type { IdentityPageProps } from '@/components/portal/access/types';
import { CompanyLogo } from '@/components/portal/projects/company-logo';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { index as adminIndex } from '@/routes/admin';
import { edit, update } from '@/routes/admin/identity';
import { destroy as destroyLogo } from '@/routes/admin/identity/logo';

/** Tipos que acepta el servidor (fileinfo). Nunca SVG. */
export const LOGO_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

/** Error del fichero elegido antes de subirlo (el servidor lo vuelve a comprobar). */
export function logoError(file: File, maxKb: number): string | null {
    if (!LOGO_TYPES.includes(file.type)) {
        return t('identity.errors.type');
    }

    if (file.size > maxKb * 1024) {
        return t('identity.errors.size', { max: formatNumber(maxKb / 1024) });
    }

    return null;
}

type IdentityForm = {
    company_name: string;
    logo: File | null;
};

/**
 * Identidad de la empresa (/admin/identidad, SPEC §14, D-067), solo admin: nombre y logo (PNG, JPG
 * o WebP de hasta 1 MB, nunca SVG), que se usan en la cabecera del portal, en los emails y en los
 * PDF. Vista previa de la cabecera del portal y del documento. Los colores no se cambian aquí.
 */
export default function AdminIdentity({ identity, limits }: IdentityPageProps) {
    const id = useId();
    const fileInput = useRef<HTMLInputElement>(null);
    const form = useForm<IdentityForm>({
        company_name: identity.company_name,
        logo: null,
    });
    const [clientError, setClientError] = useState<string | null>(null);
    const [removing, setRemoving] = useState(false);
    const [confirmRemove, setConfirmRemove] = useState(false);
    const errors = form.errors as Record<string, string | undefined>;
    const logoMessage = clientError ?? errors.logo;

    // Vista previa del fichero elegido: URL local que se libera al cambiarlo o al salir.
    const preview = useMemo(
        () =>
            form.data.logo && clientError === null
                ? URL.createObjectURL(form.data.logo)
                : null,
        [form.data.logo, clientError],
    );

    useEffect(
        () => () => {
            if (preview) {
                URL.revokeObjectURL(preview);
            }
        },
        [preview],
    );

    const company = {
        name: form.data.company_name.trim() || identity.company_name,
        logo: preview
            ? { url: preview, width: 240, height: 60 }
            : identity.logo,
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (clientError) {
            return;
        }

        form.post(update.url(), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.setData('logo', null);

                if (fileInput.current) {
                    fileInput.current.value = '';
                }
            },
        });
    };

    const remove = () => {
        router.delete(destroyLogo.url(), {
            preserveScroll: true,
            onError: toastVisitErrors,
            onStart: () => setRemoving(true),
            onFinish: () => {
                setRemoving(false);
                setConfirmRemove(false);
            },
        });
    };

    return (
        <>
            <Head title={t('identity.title')} />

            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <header className="min-w-0 space-y-1">
                    <h1 className="text-2xl font-normal tracking-tight">
                        <KeywordText text={t('identity.heading')} />
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('identity.description')}
                    </p>
                </header>

                <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                <h2 className="text-base font-medium">
                                    {t('identity.form.title')}
                                </h2>
                            </CardTitle>
                            <CardDescription>
                                {t('identity.form.description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form
                                noValidate
                                onSubmit={submit}
                                className="grid gap-6"
                                data-test="identity-form"
                            >
                                <Field
                                    id={`${id}-name`}
                                    label={t('identity.form.company_name')}
                                    help={t('identity.form.company_name_help')}
                                    error={errors.company_name}
                                    className="max-w-md"
                                >
                                    <Input
                                        id={`${id}-name`}
                                        value={form.data.company_name}
                                        onChange={(event) =>
                                            form.setData(
                                                'company_name',
                                                event.target.value,
                                            )
                                        }
                                        required
                                        maxLength={120}
                                        autoComplete="organization"
                                        aria-invalid={
                                            errors.company_name
                                                ? true
                                                : undefined
                                        }
                                        aria-describedby={describedBy(
                                            `${id}-name`,
                                            {
                                                help: true,
                                                error: errors.company_name,
                                            },
                                        )}
                                    />
                                </Field>

                                <Field
                                    id={`${id}-logo`}
                                    label={t('identity.form.logo')}
                                    help={t('identity.form.logo_help', {
                                        max: formatNumber(limits.max_side, 0),
                                    })}
                                    error={logoMessage}
                                >
                                    <Input
                                        ref={fileInput}
                                        id={`${id}-logo`}
                                        type="file"
                                        accept={LOGO_TYPES.join(',')}
                                        onChange={(event) => {
                                            const file =
                                                event.target.files?.[0] ?? null;
                                            form.clearErrors('logo');
                                            setClientError(
                                                file
                                                    ? logoError(
                                                          file,
                                                          limits.max_kb,
                                                      )
                                                    : null,
                                            );
                                            form.setData('logo', file);
                                        }}
                                        aria-invalid={
                                            logoMessage ? true : undefined
                                        }
                                        aria-describedby={describedBy(
                                            `${id}-logo`,
                                            {
                                                help: true,
                                                error: logoMessage ?? undefined,
                                            },
                                        )}
                                        data-test="identity-logo-input"
                                    />
                                </Field>

                                <div className="flex flex-wrap items-center gap-3">
                                    <Button
                                        type="submit"
                                        disabled={
                                            form.processing ||
                                            clientError !== null
                                        }
                                        data-test="identity-save"
                                    >
                                        {form.processing ? (
                                            <Spinner />
                                        ) : (
                                            <ImageUp aria-hidden="true" />
                                        )}
                                        {t('common.save')}
                                    </Button>
                                    {identity.logo ? (
                                        <ConfirmDialog
                                            open={confirmRemove}
                                            onOpenChange={setConfirmRemove}
                                            trigger={
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    disabled={removing}
                                                >
                                                    <Trash2 aria-hidden="true" />
                                                    {t(
                                                        'identity.remove.button',
                                                    )}
                                                </Button>
                                            }
                                            title={t('identity.remove.title')}
                                            description={t(
                                                'identity.remove.description',
                                            )}
                                            confirmLabel={t(
                                                'identity.remove.button',
                                            )}
                                            processing={removing}
                                            onConfirm={remove}
                                        />
                                    ) : null}
                                </div>
                            </form>
                        </CardContent>
                    </Card>

                    <section
                        aria-labelledby={`${id}-preview`}
                        className="grid content-start gap-4"
                    >
                        <h2
                            id={`${id}-preview`}
                            className="text-lg font-normal"
                        >
                            {t('identity.preview.title')}
                        </h2>

                        <figure className="grid gap-2">
                            <div
                                className="dark flex h-16 items-center gap-3 rounded-md px-4 text-foreground bg-brand-gradient"
                                data-test="identity-preview-portal"
                            >
                                <CompanyLogo company={company} />
                                <span className="text-sm text-on-gradient-muted">
                                    {t('portal.name')}
                                </span>
                            </div>
                            <figcaption className="text-xs text-muted-foreground">
                                {t('identity.preview.portal')}
                            </figcaption>
                        </figure>

                        <figure className="grid gap-2">
                            <div className="flex h-16 items-center justify-between gap-3 rounded-md border bg-white px-4">
                                {company.logo ? (
                                    <img
                                        src={company.logo.url}
                                        alt={company.name}
                                        className="h-full max-h-8 w-auto max-w-40 object-contain"
                                    />
                                ) : (
                                    <AudaxWordmark className="h-3.5 text-brand-navy dark:text-brand-navy" />
                                )}
                                <span className="truncate text-xs text-brand-navy">
                                    {company.name}
                                </span>
                            </div>
                            <figcaption className="text-xs text-muted-foreground">
                                {t('identity.preview.documents')}
                            </figcaption>
                        </figure>
                    </section>
                </div>
            </div>
        </>
    );
}

AdminIdentity.layout = {
    breadcrumbs: [
        { title: t('nav.admin'), href: adminIndex() },
        { title: t('identity.title'), href: edit() },
    ],
};
