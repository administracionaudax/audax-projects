<?php

namespace App\Domain\Reports\Delivery\Testing;

use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Delivery\GeneratedReportFile;
use App\Domain\Reports\Delivery\ReportFileGenerator;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Generador de pega para los tests (D-141): escribe un fichero temporal del tamaño pedido y anota
 * cada llamada. Con deny() responde como si quien lo pide ya no pudiera ver el informe.
 *
 *   $fake = FakeReportFileGenerator::install();
 *   $fake->sizes[ExportFormat::Pdf->value] = 11 * 1024 * 1024;
 */
final class FakeReportFileGenerator implements ReportFileGenerator
{
    /** @var array<string, int> bytes por formato */
    public array $sizes = [];

    public bool $denied = false;

    /** @var list<array{request: ReportRequest, format: ExportFormat, user_id: int}> */
    public array $generated = [];

    /** @var list<string> */
    public array $paths = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(ReportFileGenerator::class, $fake);

        return $fake;
    }

    public function deny(): self
    {
        $this->denied = true;

        return $this;
    }

    public function generate(ReportRequest $request, ExportFormat $format, User $as): GeneratedReportFile
    {
        if ($this->denied) {
            throw new AuthorizationException;
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'informe-');
        $handle = fopen($path, 'wb');

        if ($handle !== false) {
            $size = $this->sizes[$format->value] ?? 1024;
            $chunk = str_repeat('x', 65536);

            for ($written = 0; $written < $size; $written += 65536) {
                fwrite($handle, substr($chunk, 0, min(65536, $size - $written)));
            }

            fclose($handle);
        }

        $this->generated[] = ['request' => $request, 'format' => $format, 'user_id' => $as->id];
        $this->paths[] = $path;

        return new GeneratedReportFile($path, 'informe.'.$format->extension(), $format, $this->title($request, $as));
    }

    public function title(ReportRequest $request, User $as): string
    {
        if ($this->denied) {
            throw new AuthorizationException;
        }

        return 'Informe de prueba · '.$request->kind->value;
    }
}
