import { Link, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { t } from '@/lib/i18n';
import { xsrfToken } from '@/lib/xsrf';
import { edit as editIntegrations } from '@/routes/integrations';
import { store as storeSheet } from '@/routes/reports/sheets';
import type { ReportRequestData } from '@/types/reports';

type SheetResult =
    | { ok: true; url: string }
    | { ok: false; status: number; message: string };

/** POST /informes/sheets: el servidor genera el XLSX, lo sube a Drive y devuelve `{url}`. */
export async function createSheet(
    request: ReportRequestData,
): Promise<SheetResult> {
    const token = xsrfToken();

    try {
        const response = await fetch(storeSheet.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-XSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify(request),
        });
        const data = (await response.json().catch(() => ({}))) as {
            url?: unknown;
            message?: unknown;
        };

        if (response.ok && typeof data.url === 'string') {
            return { ok: true, url: data.url };
        }

        return {
            ok: false,
            status: response.status,
            message:
                typeof data.message === 'string' && response.status !== 500
                    ? data.message
                    : t('integrations.sheets.error'),
        };
    } catch {
        return {
            ok: false,
            status: 0,
            message: t('integrations.sheets.error'),
        };
    }
}

/**
 * Ventana para la hoja, abierta en el mismo clic (antes de la petición) para que el navegador no
 * la bloquee; se le asigna la URL al terminar. Sin `opener`: la página de Google no puede tocar
 * esta pestaña. null si el navegador la bloquea igualmente.
 */
function openPendingWindow(): Window | null {
    const popup = window.open('', '_blank');

    if (!popup) {
        return null;
    }

    try {
        popup.opener = null;
        popup.document.title = t('integrations.sheets.creating');
        popup.document.body.textContent = t('integrations.sheets.creating');
    } catch {
        // Si el navegador no deja escribir en la ventana nueva, se queda en blanco.
    }

    return popup;
}

/**
 * Hojas que se están creando, por informe y filtros. Vive fuera del menú (que se desmonta al elegir
 * la opción): al reabrirlo, la opción sigue desactivada y no se crea una segunda hoja (D-310).
 */
const creating = new Set<string>();

/**
 * «Google Sheets» del menú «Exportar ▾» (Fase 9, D-142). Sin credenciales en el servidor, o para un
 * colaborador externo, no aparece (prop compartida `integrations.google_sheets`). Sin la cuenta
 * conectada, abre un diálogo que lleva a Ajustes → Integraciones; con ella, crea la hoja y la abre
 * en una pestaña nueva.
 */
export function SheetsExportItem({ request }: { request: ReportRequestData }) {
    const integrations = usePage().props.integrations;
    const [dialogOpen, setDialogOpen] = useState(false);
    const key = JSON.stringify(request);
    const [pending, setPending] = useState(() => creating.has(key));

    if (!integrations?.google_sheets) {
        return null;
    }

    const goToSettings = () => router.visit(editIntegrations.url());

    const exportToSheets = async () => {
        if (creating.has(key)) {
            return;
        }

        creating.add(key);
        const popup = openPendingWindow();
        const toastId = toast.loading(t('integrations.sheets.creating'));
        setPending(true);

        const result = await createSheet(request).finally(() =>
            creating.delete(key),
        );

        setPending(false);

        if (result.ok) {
            if (popup && !popup.closed) {
                popup.location.href = result.url;
                toast.success(t('integrations.sheets.created'), {
                    id: toastId,
                });
            } else {
                // Ventana bloqueada o cerrada: el enlace queda en el aviso.
                toast.success(t('integrations.sheets.created'), {
                    id: toastId,
                    action: {
                        label: t('integrations.sheets.open'),
                        onClick: () =>
                            window.open(result.url, '_blank', 'noopener'),
                    },
                });
            }

            return;
        }

        popup?.close();
        toast.error(result.message, {
            id: toastId,
            // Sin conexión (o Google la ha retirado): a Ajustes → Integraciones.
            ...(result.status === 409
                ? {
                      action: {
                          label: t('integrations.sheets.go_to_settings'),
                          onClick: goToSettings,
                      },
                  }
                : {}),
        });
    };

    return (
        <>
            <DropdownMenuItem
                disabled={pending}
                data-test="export-google-sheets"
                onSelect={(event) => {
                    if (!integrations.google_connected) {
                        // El menú sigue montado para que el diálogo (dentro de él) no se cierre.
                        event.preventDefault();
                        setDialogOpen(true);

                        return;
                    }

                    void exportToSheets();
                }}
            >
                <FileSpreadsheet aria-hidden="true" />
                {pending
                    ? t('integrations.sheets.creating')
                    : t('integrations.sheets.item')}
            </DropdownMenuItem>

            <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
                <DialogContent>
                    <DialogTitle>
                        {t('integrations.sheets.connect_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('integrations.sheets.connect_description')}
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="secondary">
                                {t('common.cancel')}
                            </Button>
                        </DialogClose>
                        <Button asChild>
                            <Link href={editIntegrations()}>
                                {t('integrations.sheets.go_to_settings')}
                            </Link>
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
