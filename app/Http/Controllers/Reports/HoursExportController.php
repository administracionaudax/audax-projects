<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Export\TableExporter;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportPeriod;
use App\Domain\Reports\ReportScope;
use App\Domain\Reports\RevenueCalculator;
use App\Domain\Time\RateResolver;
use App\Enums\BillingType;
use App\Enums\TimeEntryStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\BuildsReportScope;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación de entradas de horas a XLSX o CSV (SPEC §10, D-045), una fila por entrada:
 * - /informes/horas/exportar: las del alcance de quien exporta (ReportScope, D-044) con los filtros
 *   globales de la URL,
 * - /proyectos/{project}/horas/exportar: las de la pestaña Horas del proyecto con sus filtros
 *   (persona, desde, hasta, bolsa, estado, facturable); el empleado solo exporta las suyas (D-021).
 *
 * Columnas: fecha, persona, cliente, proyecto, bolsa, tarea, tipo, horas, dentro de bolsa, exceso,
 * facturable, estado y descripción. Con view-financials, además la tarifa, las instantáneas de
 * tarifa y coste y los importes de cada entrada (RevenueCalculator::perEntry, D-043).
 * En streaming y por bloques: hasta TableExporter::MAX_ROWS filas; si hay más, la última avisa.
 */
class HoursExportController extends Controller
{
    use BuildsReportScope;

    /** Entradas por bloque (una consulta de entradas y, con importes, una valoración por bloque). */
    public const int CHUNK = 500;

    /** Límites de fecha de la pestaña Horas sin «desde» o «hasta»: todas sus entradas. */
    public const string OPEN_FROM = '2000-01-01';

    public const string OPEN_TO = '2100-12-31';

    /**
     * @param  int  $maxRows  Filas como máximo, contando el aviso final (los tests lo reducen con una
     *                        vinculación contextual del contenedor).
     * @param  int  $chunkSize  Entradas por bloque.
     */
    public function __construct(
        private readonly TableExporter $exporter,
        private readonly RevenueCalculator $revenue,
        private readonly RateResolver $rates,
        private readonly int $maxRows = TableExporter::MAX_ROWS,
        private readonly int $chunkSize = self::CHUNK,
    ) {}

    /**
     * GET /informes/horas/exportar?formato=xlsx|csv&… (filtros globales).
     */
    public function __invoke(Request $request): StreamedResponse
    {
        Gate::authorize('exportHours', TimeEntry::class);

        $scope = $this->reportScope($request);
        $basename = self::text('reports.r3.hours.filename', [
            'from' => $scope->filters->from->toDateString(),
            'to' => $scope->filters->to->toDateString(),
        ]);

        return $this->download($scope, $scope->entries(), $basename, $this->format($request));
    }

    /**
     * GET /proyectos/{project}/horas/exportar?formato=xlsx|csv&persona=&desde=&hasta=&bolsa=&estado=&facturable=
     * (los mismos filtros que la pestaña Horas, ProjectTimeController).
     */
    public function project(Request $request, Project $project): StreamedResponse
    {
        Gate::authorize('view', $project);

        /** @var User $viewer */
        $viewer = $request->user();
        $viewAll = Gate::forUser($viewer)->allows('viewAllTime', $project);
        $tab = $this->tabFilters($request);

        $filters = new ReportFilters(
            period: ReportPeriod::Range,
            from: CarbonImmutable::parse($tab['desde'] ?? self::OPEN_FROM),
            to: CarbonImmutable::parse($tab['hasta'] ?? self::OPEN_TO),
            // Sin permiso para ver todas las horas del proyecto, solo las suyas (D-021).
            userIds: $viewAll ? ($tab['persona'] !== null ? [$tab['persona']] : []) : [$viewer->id],
            projectIds: [$project->id],
            bankIds: $tab['bolsa'] !== null ? [$tab['bolsa']] : [],
            billable: $tab['facturable'],
        );
        $scope = new ReportScope($viewer, $filters);

        $entries = $scope->entries()
            ->when($tab['estado'] !== null, fn (Builder $query) => $query->where('time_entries.status', $tab['estado']));

        $basename = self::text('reports.r3.hours.project_filename', ['code' => $project->code]);

        return $this->download($scope, $entries, $basename, $this->format($request));
    }

    /**
     * @param  Builder<TimeEntry>  $entries  Entradas ya acotadas por ReportScope::entries().
     */
    private function download(ReportScope $scope, Builder $entries, string $basename, string $format): StreamedResponse
    {
        $financials = $scope->canSeeFinancials();

        return $this->exporter->download($basename, self::headers($financials), $this->rows($entries, $financials), $format);
    }

    /**
     * @return list<string>
     */
    public static function headers(bool $financials): array
    {
        $columns = ['date', 'person', 'client', 'project', 'bank', 'task', 'type', 'hours', 'in_bank', 'overage', 'billable', 'status', 'description'];

        if ($financials) {
            $columns = [...$columns, 'rate', 'rate_snapshot', 'cost_snapshot', 'income', 'cost'];
        }

        return array_map(fn (string $column): string => self::text("reports.r3.hours.columns.{$column}"), $columns);
    }

    /**
     * Filas por bloques (sin cargar todas las entradas a la vez), en orden de fecha.
     *
     * @param  Builder<TimeEntry>  $entries
     * @return Generator<int, array<int, string|int|float|bool|null>>
     */
    private function rows(Builder $entries, bool $financials): Generator
    {
        $query = (clone $entries)
            ->select('time_entries.*')
            ->with([
                'user:id,name,default_hourly_rate',
                'project' => fn ($project) => $project->select(['id', 'code', 'name', 'client_id', 'billing_type', 'hourly_rate']),
                'project.client' => fn ($client) => $client->withTrashed()->select(['id', 'name', 'default_hourly_rate']),
                'hourBank' => fn ($bank) => $bank->select(['id', 'name', 'hourly_rate']),
                'task' => fn ($task) => $task->select(['id', 'title', 'task_type_id']),
                'task.type' => fn ($type) => $type->select(['id', 'name']),
            ])
            ->orderBy('time_entries.date')
            ->orderBy('time_entries.id');

        $written = 0;
        $limit = min($this->maxRows, TableExporter::MAX_ROWS) - 1;

        for ($page = 1; ; $page++) {
            $chunk = (clone $query)->forPage($page, $this->chunkSize)->get();

            if ($chunk->isEmpty()) {
                return;
            }

            $amounts = [];
            if ($financials) {
                foreach ($this->revenue->perEntry((clone $entries)->whereIn('time_entries.id', $chunk->modelKeys())) as $id => $amount) {
                    $amounts[(int) $id] = $amount;
                }
            }

            foreach ($chunk as $entry) {
                if ($written === $limit) {
                    // La última fila avisa de que hay más (mejor que cortar en silencio una exportación para facturar).
                    yield [self::text('reports.r3.hours.truncated', ['count' => $limit])];

                    return;
                }

                $written++;

                yield $this->row($entry, $financials, $amounts[$entry->id] ?? null);
            }

            if ($chunk->count() < $this->chunkSize) {
                return;
            }
        }
    }

    /**
     * @param  array{income: string, cost: string, billable_minutes: int}|null  $amounts
     * @return array<int, string|int|float|bool|null>
     */
    private function row(TimeEntry $entry, bool $financials, ?array $amounts): array
    {
        $project = $entry->project;
        $bank = $entry->hourBank;
        $inBank = $bank !== null;

        $row = [
            $entry->date->toDateString(),
            $entry->user->name,
            $project->client !== null ? $project->client->name : null,
            $project->code.' · '.$project->name,
            $bank?->name,
            $entry->task->title,
            $entry->task->type?->name,
            TableExporter::hours($entry->minutes),
            $inBank ? TableExporter::hours($entry->minutes - $entry->overage_minutes) : null,
            $inBank ? TableExporter::hours($entry->overage_minutes) : null,
            $entry->is_billable,
            $entry->status->label(),
            $entry->description,
        ];

        if ($financials) {
            $row[] = TableExporter::money($this->rate($entry));
            $row[] = TableExporter::money($entry->hourly_rate_snapshot);
            $row[] = TableExporter::money($entry->hourly_cost_snapshot);
            $row[] = TableExporter::money($amounts['income'] ?? '0.00');
            $row[] = TableExporter::money($amounts['cost'] ?? '0.00');
        }

        return $row;
    }

    /**
     * Tarifa por hora de la entrada (D-043): la instantánea si está aprobada o bloqueada; si no, la
     * vigente (bolsa > proyecto > cliente > persona). Sin tarifa si no es facturable, si el proyecto es
     * interno o de precio cerrado (su ingreso es el reparto del importe).
     */
    private function rate(TimeEntry $entry): ?string
    {
        $project = $entry->project;

        if (! $entry->is_billable || in_array($project->billing_type, [BillingType::Internal, BillingType::FixedPrice], true)) {
            return null;
        }

        return $entry->hourly_rate_snapshot ?? $this->rates->rate($entry->hourBank, $project, $project->client, $entry->user);
    }

    private function format(Request $request): string
    {
        $format = $request->query('formato');

        return TableExporter::format(is_string($format) ? $format : null);
    }

    /**
     * Filtros de la pestaña Horas del proyecto (los mismos que ProjectTimeController). Lo que no se
     * entiende se ignora.
     *
     * @return array{persona: int|null, desde: string|null, hasta: string|null, bolsa: int|null, estado: string|null, facturable: bool|null}
     */
    private function tabFilters(Request $request): array
    {
        $id = function (string $key) use ($request): ?int {
            $value = $request->query($key);

            return is_string($value) && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
        };

        $date = function (string $key) use ($request): ?string {
            $value = $request->query($key);

            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                && CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d') ? $value : null;
        };

        $status = $request->query('estado');
        $billable = $request->query('facturable');

        return [
            'persona' => $id('persona'),
            'desde' => $date('desde'),
            'hasta' => $date('hasta'),
            'bolsa' => $id('bolsa'),
            'estado' => is_string($status) && in_array($status, TimeEntryStatus::values(), true) ? $status : null,
            'facturable' => match ($billable) {
                'si' => true,
                'no' => false,
                default => null,
            },
        ];
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
