<?php

namespace Tests\Feature\Integrations;

use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Delivery\GeneratedReportFile;
use App\Domain\Reports\Delivery\ReportFileGenerator;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Delivery\ReportRequest;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Generador falso para los tests de Google Sheets (D-142): la implementación real es de la entrega
 * 9.2. Escribe un XLSX temporal con contenido reconocible y, para el informe de cliente, aplica el
 * mismo permiso que el real (ClientPolicy::viewReport): 403 si quien lo pide no puede verlo.
 */
final class FakeReportFileGenerator implements ReportFileGenerator
{
    public const string CONTENTS = "PK\x03\x04 xlsx-falso-del-informe";

    /** @var list<string> */
    public array $paths = [];

    /** Contenido del XLSX que se escribe (por defecto, CONTENTS). */
    public ?string $contents = null;

    public function generate(ReportRequest $request, ExportFormat $format, User $as): GeneratedReportFile
    {
        if ($request->kind === ReportKind::Client) {
            Gate::forUser($as)->authorize('viewReport', Client::query()->findOrFail($request->routeParams['client'] ?? 0));
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'audax-report-');
        file_put_contents($path, $this->contents ?? self::CONTENTS);
        $this->paths[] = $path;

        return new GeneratedReportFile($path, 'informe.xlsx', $format, $this->title($request, $as));
    }

    public function title(ReportRequest $request, User $as): string
    {
        return 'Informe de cliente · Montó · septiembre 2026';
    }
}
