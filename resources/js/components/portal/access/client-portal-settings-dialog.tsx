import { useForm } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { SwitchField } from '@/components/portal/access/switch-field';
import type {
    ClientPortalAccess,
    ClientPortalSettings,
} from '@/components/portal/access/types';
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
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { update } from '@/routes/clients/portal/settings';
import type {
    PortalEntryVisibility,
    PortalPersonDisplay,
} from '@/types/portal';

type SettingsForm = {
    portal_person_display: PortalPersonDisplay;
    portal_entry_visibility: PortalEntryVisibility;
    portal_notify_thresholds: boolean;
};

function initial(settings: ClientPortalSettings): SettingsForm {
    return {
        portal_person_display: settings.person_display,
        portal_entry_visibility: settings.entry_visibility,
        portal_notify_thresholds: settings.notify_thresholds,
    };
}

/**
 * Ajustes del portal de un cliente (D-064, D-065) en un diálogo: cómo se nombra a las personas,
 * qué horas ve (nunca borradores) y si recibe los avisos de sus bolsas al 90 % y al 100 % por email.
 */
export function ClientPortalSettingsDialog({
    clientId,
    settings,
    options,
}: {
    clientId: number;
    settings: ClientPortalSettings;
    options: ClientPortalAccess['options'];
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const form = useForm<SettingsForm>(initial(settings));

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (next) {
                    form.setData(initial(settings));
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline" data-test="portal-settings">
                    <SlidersHorizontal aria-hidden="true" />
                    {t('portal_access.settings.button')}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form
                    noValidate
                    className="grid gap-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(update(clientId), {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {t('portal_access.settings.title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('portal_access.settings.description')}
                        </DialogDescription>
                    </DialogHeader>

                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm font-medium">
                            {t('portal_access.settings.person_display')}
                        </legend>
                        <RadioGroup
                            value={form.data.portal_person_display}
                            onValueChange={(value) =>
                                form.setData(
                                    'portal_person_display',
                                    value as PortalPersonDisplay,
                                )
                            }
                            className="grid gap-2"
                        >
                            {options.person_display.map((option) => (
                                <label
                                    key={option}
                                    className="flex items-start gap-2 text-sm"
                                >
                                    <RadioGroupItem
                                        value={option}
                                        className="mt-0.5"
                                    />
                                    <span className="grid gap-0.5">
                                        <span>
                                            {t(
                                                `portal_access.person_display.${option}`,
                                            )}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {t(
                                                `portal_access.person_display.${option}_help`,
                                            )}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </RadioGroup>
                        <InputError
                            message={form.errors.portal_person_display}
                        />
                    </fieldset>

                    <fieldset className="grid gap-2">
                        <legend className="mb-2 text-sm font-medium">
                            {t('portal_access.settings.entry_visibility')}
                        </legend>
                        <RadioGroup
                            value={form.data.portal_entry_visibility}
                            onValueChange={(value) =>
                                form.setData(
                                    'portal_entry_visibility',
                                    value as PortalEntryVisibility,
                                )
                            }
                            className="grid gap-2"
                        >
                            {options.entry_visibility.map((option) => (
                                <label
                                    key={option}
                                    className="flex items-start gap-2 text-sm"
                                >
                                    <RadioGroupItem
                                        value={option}
                                        className="mt-0.5"
                                    />
                                    <span className="grid gap-0.5">
                                        <span>
                                            {t(
                                                `portal_access.entry_visibility.${option}`,
                                            )}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {t(
                                                `portal_access.entry_visibility.${option}_help`,
                                            )}
                                        </span>
                                    </span>
                                </label>
                            ))}
                        </RadioGroup>
                        <InputError
                            message={form.errors.portal_entry_visibility}
                        />
                    </fieldset>

                    <SwitchField
                        id={`${id}-notify`}
                        label={t('portal_access.settings.notify')}
                        help={t('portal_access.settings.notify_help')}
                        checked={form.data.portal_notify_thresholds}
                        onChange={(checked) =>
                            form.setData('portal_notify_thresholds', checked)
                        }
                        error={form.errors.portal_notify_thresholds}
                    />

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
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? <Spinner /> : null}
                            {t('common.save')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
