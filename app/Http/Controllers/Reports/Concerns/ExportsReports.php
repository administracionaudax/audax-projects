<?php

namespace App\Http\Controllers\Reports\Concerns;

use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Delivery\GeneratedReportFile;
use App\Domain\Reports\Delivery\ReportFileGenerator;
use App\Domain\Reports\Delivery\ReportGenerator;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Pdf\PdfConversionFailed;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Descargas e impresión de un informe desde su propia URL (Fase 9, D-139 y D-140):
 * - ?formato=xlsx|csv|pdf genera el fichero con ReportFileGenerator (los permisos de quien lo
 *   pide, y queda en la auditoría) y lo descarga: Excel y CSV en streaming, como siempre,
 * - ?formato=imprimir sirve el HTML del PDF en la pestaña, sin la app, y abre el diálogo de
 *   impresión al cargar,
 * - cualquier otro valor muestra la página (o, en las rutas que solo exportan, su formato por
 *   defecto).
 * `report_request` es la prop que recibe cada página para el menú «Exportar ▾».
 */
trait ExportsReports
{
    /** Valor de ?formato= de la versión para imprimir. */
    public const string PRINT_FORMAT = 'imprimir';

    /**
     * La respuesta de exportación si la URL la pide; null para mostrar la página.
     *
     * @param  array<string, int|string>|null  $routeParams  por defecto, los de la ruta
     */
    protected function exportResponse(Request $request, ReportKind $kind, ?array $routeParams = null, ?ExportFormat $default = null): ?Response
    {
        $value = $request->query('formato');
        $value = is_string($value) ? $value : null;

        if ($value === null && $default === null) {
            return null;
        }

        /** @var User $user */
        $user = $request->user();
        $report = $this->reportRequestFrom($request, $kind, $routeParams);

        if ($value === self::PRINT_FORMAT) {
            /** @var ReportGenerator $generator */
            $generator = app(ReportGenerator::class);

            return response($generator->printable($report, $user)['html'], 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $format = ($value !== null ? ExportFormat::tryFrom($value) : null) ?? $default;
        if ($format === null) {
            return null;
        }

        try {
            $file = app(ReportFileGenerator::class)->generate($report, $format, $user);
        } catch (PdfConversionFailed $e) {
            // Gotenberg caído o lento: queda en el registro y la persona recibe un 503 claro.
            report($e);
            abort(503, __('report_pdf.errors.pdf_unavailable'));
        }

        return self::download($file);
    }

    /**
     * El informe de la URL: sus parámetros de ruta (los modelos, por su id) y la query sin `formato`.
     *
     * @param  array<string, int|string>|null  $routeParams
     */
    protected function reportRequestFrom(Request $request, ReportKind $kind, ?array $routeParams = null): ReportRequest
    {
        if ($routeParams === null) {
            $routeParams = [];
            foreach ($request->route()?->parameters() ?? [] as $name => $value) {
                $key = $value instanceof Model ? $value->getKey() : $value;
                if (is_int($key) || is_string($key)) {
                    $routeParams[(string) $name] = $key;
                }
            }
        }

        $query = $request->query();
        unset($query['formato']);

        return new ReportRequest($kind, $routeParams, $query);
    }

    /**
     * Prop `report_request` de la página (ReportRequestData en resources/js/types/reports.ts).
     *
     * @param  array<string, int|string>  $routeParams
     * @param  array<string, mixed>  $query
     * @return array{kind: string, route_params: array<string, int|string>, query: array<string, mixed>}
     */
    protected function reportRequestProp(ReportKind $kind, array $routeParams, array $query): array
    {
        return (new ReportRequest($kind, $routeParams, $query))->toArray();
    }

    /**
     * Descarga del fichero generado, que se borra al enviarlo. Excel y CSV, en streaming (como la
     * descarga de siempre); el PDF, entero.
     */
    protected static function download(GeneratedReportFile $file): Response
    {
        $headers = [
            'Content-Type' => $file->mime(),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($file->format === ExportFormat::Pdf) {
            $content = (string) file_get_contents($file->path);
            @unlink($file->path);

            return response($content, 200, $headers + [
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file->filename),
                'Content-Length' => (string) strlen($content),
            ]);
        }

        return response()->streamDownload(function () use ($file): void {
            readfile($file->path);
            @unlink($file->path);
        }, $file->filename, $headers);
    }
}
