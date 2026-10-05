import { useForm } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useId, useState } from 'react';
import { NativeSelect } from '@/components/admin/native-select';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import {
    channelLabel,
    REMINDER_CHANNELS,
} from '@/components/weeklies/reminders/reminder-rules-editor';
import { t } from '@/lib/i18n';
import { send } from '@/routes/weeklies/reminders';
import type { UserSummary } from '@/types';
import type { WeeklyReminderChannel } from '@/types/weeklies';

type SendForm = {
    recipients: 'pending' | 'users';
    user_ids: number[];
    template: 'automatic' | 'manual';
    channels: WeeklyReminderChannel[];
};

/**
 * Envío manual (F-109): a todas las personas pendientes de la semana activa o solo a algunas, con
 * el texto automático o el manual y por los canales elegidos (de los que cada persona tenga
 * activados). El servidor solo avisa a quien sigue pendiente.
 */
export function SendRemindersDialog({
    cycleLabel,
    pending,
}: {
    cycleLabel: string;
    pending: UserSummary[];
}) {
    const id = useId();
    const [open, setOpen] = useState(false);
    const initial: SendForm = {
        recipients: 'pending',
        user_ids: [],
        template: 'manual',
        channels: ['app', 'email'],
    };
    const form = useForm<SendForm>(initial);

    const toggle = <T,>(list: T[], value: T, checked: boolean): T[] =>
        checked
            ? [...list.filter((item) => item !== value), value]
            : list.filter((item) => item !== value);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (next) {
                    form.setData(initial);
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button
                    type="button"
                    disabled={pending.length === 0}
                    data-test="reminders-send-open"
                >
                    <Send aria-hidden="true" />
                    {t('weekly_reminders.send.open')}
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <form
                    className="grid gap-5"
                    noValidate
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(send.url(), {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {t('weekly_reminders.send.dialog_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('weekly_reminders.send.dialog_description', {
                                label: cycleLabel,
                            })}
                        </DialogDescription>
                    </DialogHeader>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            {t('weekly_reminders.send.recipients')}
                        </legend>
                        <RadioGroup
                            value={form.data.recipients}
                            onValueChange={(value) =>
                                form.setData(
                                    'recipients',
                                    value === 'users' ? 'users' : 'pending',
                                )
                            }
                            className="grid gap-2"
                        >
                            <label className="flex items-center gap-2 text-sm">
                                <RadioGroupItem value="pending" />
                                {t('weekly_reminders.send.all_pending', {
                                    count: pending.length,
                                })}
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <RadioGroupItem value="users" />
                                {t('weekly_reminders.send.some')}
                            </label>
                        </RadioGroup>
                    </fieldset>

                    {form.data.recipients === 'users' ? (
                        <fieldset className="grid gap-2">
                            <legend className="mb-1 text-sm font-medium">
                                {t('weekly_reminders.send.people')}
                            </legend>
                            <ul
                                className="grid max-h-56 gap-2 overflow-y-auto"
                                data-test="reminders-send-people"
                            >
                                {pending.map((person) => {
                                    const checkbox = `${id}-person-${person.id}`;

                                    return (
                                        <li
                                            key={person.id}
                                            className="flex items-center gap-2"
                                        >
                                            <Checkbox
                                                id={checkbox}
                                                checked={form.data.user_ids.includes(
                                                    person.id,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        'user_ids',
                                                        toggle(
                                                            form.data.user_ids,
                                                            person.id,
                                                            checked === true,
                                                        ),
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor={checkbox}
                                                className="font-normal"
                                            >
                                                {person.name}
                                            </Label>
                                        </li>
                                    );
                                })}
                            </ul>
                            <InputError
                                message={
                                    form.errors.user_ids ??
                                    form.errors.recipients
                                }
                            />
                        </fieldset>
                    ) : (
                        <InputError message={form.errors.recipients} />
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor={`${id}-template`}>
                            {t('weekly_reminders.send.template')}
                        </Label>
                        <NativeSelect
                            id={`${id}-template`}
                            value={form.data.template}
                            onChange={(event) =>
                                form.setData(
                                    'template',
                                    event.target.value === 'automatic'
                                        ? 'automatic'
                                        : 'manual',
                                )
                            }
                        >
                            <option value="manual">
                                {t('weekly_reminders.templates_names.manual')}
                            </option>
                            <option value="automatic">
                                {t(
                                    'weekly_reminders.templates_names.automatic',
                                )}
                            </option>
                        </NativeSelect>
                    </div>

                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            {t('weekly_reminders.send.channels')}
                        </legend>
                        <div className="flex flex-wrap gap-4">
                            {REMINDER_CHANNELS.map((channel) => {
                                const checkbox = `${id}-channel-${channel}`;

                                return (
                                    <div
                                        key={channel}
                                        className="flex items-center gap-2"
                                    >
                                        <Checkbox
                                            id={checkbox}
                                            checked={form.data.channels.includes(
                                                channel,
                                            )}
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'channels',
                                                    toggle(
                                                        form.data.channels,
                                                        channel,
                                                        checked === true,
                                                    ),
                                                )
                                            }
                                        />
                                        <Label
                                            htmlFor={checkbox}
                                            className="font-normal"
                                        >
                                            {channelLabel(channel)}
                                        </Label>
                                    </div>
                                );
                            })}
                        </div>
                        <InputError message={form.errors.channels} />
                    </fieldset>

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
                            disabled={form.processing}
                            data-test="reminders-send-confirm"
                        >
                            {form.processing && <Spinner />}
                            {t('weekly_reminders.send.confirm')}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
