import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { EmptyState } from '@/components/empty-state';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { update } from '@/routes/projects/alerts';
import type { ProjectAlert, ProjectMember } from '@/types';
import { BellOff } from 'lucide-react';

const ALERTS: {
    key: ProjectAlert;
    label: TranslationKey;
    help: TranslationKey;
}[] = [
    {
        key: 'hour_bank_threshold',
        label: 'projects.alerts.hour_bank_threshold',
        help: 'projects.alerts.hour_bank_threshold_help',
    },
    {
        key: 'hour_bank_overage',
        label: 'projects.alerts.hour_bank_overage',
        help: 'projects.alerts.hour_bank_overage_help',
    },
];

/**
 * Alertas de cada gestor del proyecto (D-023): por defecto, todas activadas. Cada gestor cambia
 * las suyas y un admin, las de cualquiera; las demás se ven, pero no se pueden tocar.
 */
export function ProjectAlerts({
    projectId,
    managers,
    editable,
}: {
    projectId: number;
    managers: ProjectMember[];
    /** Ids de los gestores cuyas alertas puede cambiar quien mira. */
    editable: number[];
}) {
    if (managers.length === 0) {
        return <EmptyState icon={BellOff} title={t('projects.alerts.empty')} />;
    }

    return (
        <ul className="grid gap-3">
            {managers.map((manager) => (
                <ManagerAlerts
                    key={manager.id}
                    projectId={projectId}
                    manager={manager}
                    editable={editable.includes(manager.id)}
                />
            ))}
        </ul>
    );
}

function ManagerAlerts({
    projectId,
    manager,
    editable,
}: {
    projectId: number;
    manager: ProjectMember;
    editable: boolean;
}) {
    const id = useId();
    const [processing, setProcessing] = useState(false);

    const change = (key: ProjectAlert, value: boolean) => {
        router.put(
            update.url({ project: projectId, user: manager.id }),
            { alerts: { [key]: value } },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <li
            className="grid gap-3 rounded-md border p-3"
            data-test="manager-alerts"
        >
            <p className="text-sm font-medium">
                {manager.name}
                {!editable ? (
                    <span className="ml-2 text-xs font-normal text-muted-foreground">
                        {t('projects.alerts.read_only')}
                    </span>
                ) : null}
            </p>
            <div className="grid gap-3 sm:grid-cols-2">
                {ALERTS.map((alert) => {
                    const switchId = `${id}-${alert.key}`;

                    return (
                        <div key={alert.key} className="flex items-start gap-3">
                            <Switch
                                id={switchId}
                                checked={manager.alert_preferences[alert.key]}
                                disabled={!editable || processing}
                                aria-describedby={`${switchId}-help`}
                                onCheckedChange={(value) =>
                                    change(alert.key, value)
                                }
                                className="mt-0.5"
                            />
                            <div className="grid gap-0.5">
                                <Label
                                    htmlFor={switchId}
                                    className="font-normal"
                                >
                                    {t(alert.label)}
                                </Label>
                                <p
                                    id={`${switchId}-help`}
                                    className="text-xs text-muted-foreground"
                                >
                                    {t(alert.help)}
                                </p>
                            </div>
                        </div>
                    );
                })}
            </div>
        </li>
    );
}
