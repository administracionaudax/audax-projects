<?php

namespace App\Domain\People\Reports;

use App\Domain\People\RegisterHasher;
use App\Domain\Reports\Delivery\Documents\ExportSheet;
use App\Domain\Reports\Delivery\Documents\ReportPdf;
use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\Pdf\PdfEngine;
use App\Domain\Reports\Pdf\ReportHtml;
use App\Models\InspectionAccess;
use App\Models\PeopleExport;
use App\Models\User;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Escribe los ficheros del registro (R2; D-351) y deja constancia de cada uno:
 *
 * - **PDF** con la hoja de documentos de Audax (ReportHtml y el motor de PDF de los informes:
 *   Gotenberg en el servidor, el HTML en local y en los tests); **Excel** y **CSV** con
 *   TableExporter (los textos nunca como fórmulas).
 * - **Huella del contenido** (SHA-256 de la cabecera y las filas en JSON canónico): va dentro del
 *   fichero (al pie del PDF y en las últimas filas del Excel y el CSV) y es la misma en los tres
 *   formatos del mismo informe.
 * - **Huella del fichero** (SHA-256 de sus bytes): no puede ir dentro del propio fichero, así que se
 *   guarda en `people_exports` (con quién lo pidió y para qué) y en la auditoría
 *   (`people-exports`). Con ella se comprueba después que un fichero no se ha tocado.
 */
final class RegisterFiles
{
    public const string LOG = 'people-exports';

    public function __construct(
        private readonly ReportHtml $html,
        private readonly PdfEngine $engine,
        private readonly TableExporter $exporter,
    ) {}

    /**
     * Genera el fichero en un temporal (quien lo pide lo borra al enviarlo) y lo anota.
     *
     * @param  array<string, mixed>  $params
     */
    public function write(RegisterDocument $document, ExportFormat $format, array $params, ?User $by, ?InspectionAccess $access = null, ?CarbonImmutable $now = null): RegisterFile
    {
        $now ??= CarbonImmutable::now();
        $contentHash = RegisterHasher::contentHash($document->content());
        $path = self::temporaryPath();

        try {
            if ($format === ExportFormat::Pdf) {
                $bytes = $this->pdf($document, $contentHash, $now, $by->name ?? $access?->name);

                if (file_put_contents($path, $bytes) === false) {
                    throw new RuntimeException("No se puede escribir el fichero del registro en {$path}");
                }

                $filename = Str::slug($document->basename, '-', 'es').'-'.LocalTime::todayString().'.'.$this->engine->extension();
                $mime = $this->engine->mime();
            } else {
                $footer = self::footerRows($contentHash, $now, $by->name ?? $access?->name);

                if ($format === ExportFormat::Xlsx && $document->sheets !== []) {
                    // La huella va al pie de la primera hoja (la principal).
                    $sheets = $document->sheets;
                    $first = array_shift($sheets);
                    $this->exporter->writeWorkbook($path, [new ExportSheet($first->name, $first->headers, [...self::iterate($first->rows), ...$footer]), ...$sheets]);
                } else {
                    $this->exporter->write($path, $document->headers, [...$document->rows, ...$footer], $format->value);
                }

                $filename = TableExporter::filename($document->basename, $format->value);
                $mime = $format === ExportFormat::Csv ? 'text/csv; charset=UTF-8' : $format->mime();
            }
        } catch (Throwable $e) {
            @unlink($path);

            throw $e;
        }

        $sha = (string) hash_file('sha256', $path);
        $this->record($document->kind, $format->value, $params, $filename, $sha, $contentHash, (int) filesize($path), $by, $access, $document->title);

        return new RegisterFile($path, $filename, $mime, $format, $sha, $contentHash, $document->title);
    }

    /**
     * Los bytes del PDF (o del HTML con el motor html) de un documento, con la huella al pie.
     */
    public function pdf(RegisterDocument $document, string $contentHash, CarbonImmutable $now, ?string $by): string
    {
        $pdf = new ReportPdf('people.pdf.document', $document->title, $document->basename, [
            'cover' => $document->cover,
            'kpis' => $document->kpis,
            'sections' => $document->sections,
            'notes' => $document->notes,
            'content_hash' => $contentHash,
            'generated_at' => PeopleFormat::dateTime($now),
            'generated_by' => $by,
        ], $document->landscape);

        return $this->engine->render($this->html->render($pdf));
    }

    public function extension(): string
    {
        return $this->engine->extension();
    }

    /**
     * Anota un fichero que sale de la app: `people_exports` y la auditoría.
     *
     * @param  array<string, mixed>  $params
     */
    public function record(string $kind, string $format, array $params, string $filename, string $sha, ?string $contentHash, int $size, ?User $by, ?InspectionAccess $access = null, ?string $title = null): PeopleExport
    {
        $export = new PeopleExport;
        $export->forceFill([
            'user_id' => $by?->id,
            'inspection_access_id' => $access?->id,
            'kind' => $kind,
            'format' => $format,
            'params' => $params,
            'filename' => $filename,
            'sha256' => $sha,
            'content_hash' => $contentHash,
            'size' => $size,
            'created_at' => CarbonImmutable::now(),
        ])->save();

        $log = activity(self::LOG)
            ->event('generated')
            ->withProperties([
                'kind' => $kind,
                'format' => $format,
                'params' => $params,
                'filename' => $filename,
                'title' => $title,
                'sha256' => $sha,
                'content_hash' => $contentHash,
                'inspection_access_id' => $access?->id,
            ])
            ->performedOn($export);

        if ($by !== null) {
            $log->causedBy($by);
        }

        $log->log('people.export.generated');

        return $export;
    }

    /**
     * Las últimas filas del Excel y el CSV: la huella del contenido, cuándo y quién.
     *
     * @return list<list<string>>
     */
    private static function footerRows(string $contentHash, CarbonImmutable $now, ?string $by): array
    {
        return [
            [],
            [(string) __('people.reports.footer.content_hash'), $contentHash],
            [(string) __('people.reports.footer.generated'), PeopleFormat::dateTime($now).($by !== null ? ' · '.$by : '')],
        ];
    }

    /**
     * @param  iterable<array<int, string|int|float|bool|null>>  $rows
     * @return list<array<int, string|int|float|bool|null>>
     */
    private static function iterate(iterable $rows): array
    {
        $list = [];

        foreach ($rows as $row) {
            $list[] = $row;
        }

        return $list;
    }

    private static function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'audax-register-'.getmypid().'-');

        if ($path === false) {
            throw new RuntimeException('No se puede crear el fichero temporal del registro.');
        }

        return $path;
    }
}
