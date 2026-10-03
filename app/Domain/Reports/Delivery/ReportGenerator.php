<?php

namespace App\Domain\Reports\Delivery;

use App\Domain\Reports\Delivery\Documents\ReportDocuments;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Pdf\PdfEngine;
use App\Domain\Reports\Pdf\ReportHtml;
use App\Models\User;
use RuntimeException;
use Throwable;

/**
 * Implementación de ReportFileGenerator (entrega 9.2, D-139 y D-140): el documento de cada informe
 * (ReportDocuments) comprueba los permisos de $as y da la tabla (Excel y CSV con TableExporter,
 * los mismos ficheros que la descarga de siempre) o el documento del PDF (HTML con la hoja de
 * Audax, ReportHtml, convertido por el motor de PDF: Gotenberg, o el HTML con el motor html).
 * El fichero queda en el directorio temporal del sistema; quien lo pide lo borra. Cada fichero
 * generado queda en la auditoría (ReportDeliveryLog).
 */
final class ReportGenerator implements ReportFileGenerator
{
    public function __construct(
        private readonly ReportDocuments $documents,
        private readonly ReportHtml $html,
        private readonly PdfEngine $engine,
        private readonly TableExporter $exporter,
        private readonly ReportDeliveryLog $log,
    ) {}

    public function generate(ReportRequest $request, ExportFormat $format, User $as): GeneratedReportFile
    {
        $document = $this->documents->for($request->kind);
        $path = self::temporaryPath();

        try {
            if ($format === ExportFormat::Pdf) {
                $pdf = $document->pdf($request, $as);
                if (file_put_contents($path, $this->engine->render($this->html->render($pdf))) === false) {
                    throw new RuntimeException("No se puede escribir el informe en {$path}");
                }
                $file = new GeneratedReportFile($path, $pdf->filename.'.'.$this->engine->extension(), $format, $pdf->title);
            } else {
                $table = $document->table($request, $as);
                $this->exporter->write($path, $table->headers, $table->rows, $format->value);
                $file = new GeneratedReportFile($path, TableExporter::filename($table->basename, $format->value), $format, $table->title);
            }
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }

        $this->log->generated($as, $request, $format, $file);

        return $file;
    }

    public function title(ReportRequest $request, User $as): string
    {
        return $this->documents->for($request->kind)->title($request, $as);
    }

    /**
     * El HTML del PDF para la pestaña de imprimir (D-140): el mismo documento, con el diálogo de
     * impresión al cargar. Queda en la auditoría.
     *
     * @return array{title: string, html: string}
     */
    public function printable(ReportRequest $request, User $as): array
    {
        $pdf = $this->documents->for($request->kind)->pdf($request, $as);
        $html = $this->html->render($pdf, print: true);
        $this->log->printed($as, $request, $pdf->title);

        return ['title' => $pdf->title, 'html' => $html];
    }

    private static function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'audax-report-');
        if ($path === false) {
            throw new RuntimeException('No se puede crear el fichero temporal del informe.');
        }

        return $path;
    }
}
