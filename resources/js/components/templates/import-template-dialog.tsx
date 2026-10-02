import { useForm } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useId, useState } from 'react';
import { describedBy, Field } from '@/components/admin/field';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { importMethod } from '@/routes/templates';

/**
 * Importar una plantilla desde un fichero JSON exportado (D-058). El servidor la valida como el
 * editor y, si es válida, la crea y abre su editor para revisarla.
 */
export function ImportTemplateDialog() {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm<{ file: File | null }>({ file: null });

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline">
                    <Upload aria-hidden="true" />
                    {t('templates.import.button')}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <form
                    noValidate
                    className="grid gap-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(importMethod.url(), {
                            forceFormData: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>{t('templates.import.title')}</DialogTitle>
                        <DialogDescription>
                            {t('templates.import.description')}
                        </DialogDescription>
                    </DialogHeader>

                    <Field
                        id={`${id}-file`}
                        label={t('templates.import.file')}
                        help={t('templates.import.help')}
                        error={form.errors.file}
                    >
                        <Input
                            id={`${id}-file`}
                            type="file"
                            accept="application/json,.json"
                            aria-invalid={form.errors.file ? true : undefined}
                            aria-describedby={describedBy(`${id}-file`, {
                                help: true,
                                error: form.errors.file,
                            })}
                            onChange={(event) =>
                                form.setData(
                                    'file',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                        />
                    </Field>

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                            >
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            disabled={
                                form.processing || form.data.file === null
                            }
                        >
                            {form.processing ? <Spinner /> : null}
                            {t('templates.import.submit')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
