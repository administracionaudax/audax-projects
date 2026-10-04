<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Delivery\Documents\ProjectHoursDocument;
use App\Domain\Reports\Delivery\ExportFormat;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Export\EntryRows;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\ExportsReports;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exportación de entradas de horas (SPEC §10, D-045), una fila por entrada (EntryRows):
 * - /informes/horas/exportar: las del alcance de quien exporta (ReportScope, D-044) con los filtros
 *   globales de la URL (HoursDocument),
 * - /proyectos/{project}/horas/exportar: las de la pestaña Horas del proyecto con sus filtros
 *   (persona, desde, hasta, bolsa, estado, facturable); el empleado solo exporta las suyas (D-021,
 *   ProjectHoursDocument).
 * ?formato=xlsx (por defecto)|csv|pdf genera el fichero con ReportFileGenerator y ?formato=imprimir
 * abre la versión para imprimir (Fase 9, D-139 y D-140).
 */
class HoursExportController extends Controller
{
    use ExportsReports;

    /** Entradas por bloque (EntryRows). */
    public const int CHUNK = EntryRows::CHUNK;

    /** Límites de fecha de la pestaña Horas sin «desde» o «hasta»: todas sus entradas. */
    public const string OPEN_FROM = ProjectHoursDocument::OPEN_FROM;

    public const string OPEN_TO = ProjectHoursDocument::OPEN_TO;

    /**
     * GET /informes/horas/exportar?formato=xlsx|csv|pdf|imprimir&… (filtros globales).
     */
    public function __invoke(Request $request): Response
    {
        Gate::authorize('exportHours', TimeEntry::class);

        return $this->exportResponse($request, ReportKind::Hours, [], ExportFormat::Xlsx) ?? abort(404);
    }

    /**
     * GET /proyectos/{project}/horas/exportar?formato=…&persona=&desde=&hasta=&bolsa=&estado=&facturable=
     * (los mismos filtros que la pestaña Horas, ProjectTimeController).
     */
    public function project(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        return $this->exportResponse($request, ReportKind::ProjectHours, ['project' => $project->id], ExportFormat::Xlsx) ?? abort(404);
    }
}
