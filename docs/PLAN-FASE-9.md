# Plan de la Fase 9: exportar, imprimir, enviar y programar informes

Pedida por el propietario el 03/10/2026. Hoy los informes solo se exportan a Excel y CSV, y el PDF de bolsa sale con FPDF en Helvetica. Se piden además:
- **PDF con el estilo de Audax** (la hoja de documentos A4 del kit, `audax-doc.css`),
- **Google Sheets**,
- **Imprimir**,
- **Enviar por correo** y **programar el envío** en una fecha o de forma periódica, como el «informe de inicio de mes» o uno semanal.

Rama `fase-9`, que sale de `fase-8`.

## Contrato (entrega 9.1, hecha)
`app/Domain/Reports/Delivery/`:
- `ExportFormat` (xlsx, csv, pdf),
- `ReportKind` (los 10 informes exportables y su ruta),
- `RelativePeriod` (fijo, en curso, anterior),
- `ReportRequest` (informe, parámetros de ruta y filtros; `resolvedFor()` resuelve el periodo relativo el día del envío),
- `GeneratedReportFile`,
- la interfaz **`ReportFileGenerator`**, que genera un fichero con los permisos de quien lo pide y su título.

**Interfaz compartida.** El menú **«Exportar ▾»** de cada informe lo hace la 9.2 en `resources/js/components/reports/export-menu.tsx`, con estas entradas: Excel, CSV, PDF, Google Sheets, Imprimir, «Enviar por correo…» y «Programar envío…». Las de las entregas 9.3 y 9.4 importan estos componentes, que la 9.2 crea como esqueletos con estas props y las otras entregas sustituyen:
- `resources/js/components/reports/delivery/send-report-dialog.tsx` → `SendReportDialog({ open, onOpenChange, request: ReportRequestData, title })`,
- `resources/js/components/reports/delivery/schedule-report-dialog.tsx` → `ScheduleReportDialog({ open, onOpenChange, request: ReportRequestData, title })`,
- `resources/js/components/reports/delivery/sheets-export-item.tsx` → `SheetsExportItem({ request: ReportRequestData })` (un `DropdownMenuItem`),
- tipo TS `ReportRequestData = { kind: string; route_params: Record<string, number | string>; query: Record<string, unknown> }` en `resources/js/types/reports.ts`.

## Entregas
| Entrega | Contenido | Quién |
|---|---|---|
| 9.2 PDF, imprimir y menú | Implementación de `ReportFileGenerator` para los 10 informes (Excel y CSV reutilizando lo que ya hay; PDF nuevo). PDF en HTML con `audax-doc.css` y DM Sans incrustada, convertido con **Gotenberg** (Docker, MIT). «Imprimir» con el mismo HTML. Menú «Exportar ▾» | Agente A |
| 9.3 Correo y envíos programados | «Enviar por correo» (personas de la app y correos externos), envíos programados (`/informes/envios`) con su planificador, historial y «enviar ahora» | Agente B |
| 9.4 Google Sheets | Conectar la cuenta de Google de cada persona (OAuth, Workspace interno, alcance `drive.file`) y subir el XLSX convertido a hoja de cálculo | Agente C |
| 9.5 Cierre | Integración, revisión de seguridad, contenedor Gotenberg en el servidor (copia, batería V y webs), credenciales de Google (las pone el propietario), despliegue | Yo |

## Verificación
- **Local:** Pest, PHPStan nivel 7, Vitest, `tsc`, `vp check`, compilación y E2E de cada entrega.
- **Servidor:** `desplegar-dev.sh --tests`, Gotenberg con límites (memoria y núcleos 6-7), batería V y webs. Un envío real de prueba al propietario.
