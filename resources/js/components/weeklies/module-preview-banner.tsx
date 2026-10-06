import { usePage } from '@inertiajs/react';
import { FlaskConical } from 'lucide-react';
import { t } from '@/lib/i18n';

/**
 * Modo de prueba (D-239): en las páginas de un módulo apagado que un admin usa solo por la prueba
 * (prop compartida `module_preview`, que marca el middleware `module:…`), un aviso discreto: nadie
 * más lo ve y no se envían avisos.
 */
export function ModulePreviewBanner() {
    if (usePage().props.module_preview !== true) {
        return null;
    }

    return (
        <aside
            aria-label={t('weeklies.preview.label')}
            className="flex items-center gap-2 border-b px-4 py-1.5 text-xs text-muted-foreground md:px-6"
            data-test="module-preview-banner"
        >
            <FlaskConical aria-hidden="true" className="size-3.5 shrink-0" />
            <p className="min-w-0 flex-1">{t('weeklies.preview.banner')}</p>
        </aside>
    );
}
