import { router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Monitor, Moon, Sun } from 'lucide-react';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { Appearance } from '@/hooks/use-appearance';
import { isAppearance, useAppearance } from '@/hooks/use-appearance';
import { t } from '@/lib/i18n';
import type { TranslationKey } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import { update } from '@/routes/appearance';

const OPTIONS: {
    value: Appearance;
    icon: LucideIcon;
    label: TranslationKey;
}[] = [
    { value: 'light', icon: Sun, label: 'appearance.light' },
    { value: 'dark', icon: Moon, label: 'appearance.dark' },
    { value: 'system', icon: Monitor, label: 'appearance.system' },
];

/**
 * Selector de tema. Aplica el tema al instante en el navegador y lo guarda en el usuario
 * (PATCH /ajustes/apariencia con { theme }), para que le siga en cualquier dispositivo.
 */
export default function AppearanceToggleTab({
    className = '',
}: {
    className?: string;
}) {
    const { appearance, updateAppearance } = useAppearance();

    const change = (value: string) => {
        if (!isAppearance(value) || value === appearance) {
            return;
        }

        updateAppearance(value);
        router.patch(
            update.url(),
            { theme: value },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <ToggleGroup
            type="single"
            value={appearance}
            onValueChange={change}
            aria-label={t('appearance.label')}
            className={cn(
                'inline-flex gap-1 rounded-md border bg-muted p-1',
                className,
            )}
        >
            {OPTIONS.map(({ value, icon: Icon, label }) => (
                <ToggleGroupItem
                    key={value}
                    value={value}
                    className="h-8 rounded-sm px-3 text-muted-foreground first:rounded-sm last:rounded-sm hover:bg-background hover:text-foreground data-[state=on]:bg-background data-[state=on]:text-foreground"
                >
                    <Icon aria-hidden="true" className="size-4" />
                    <span className="text-sm">{t(label)}</span>
                </ToggleGroupItem>
            ))}
        </ToggleGroup>
    );
}
