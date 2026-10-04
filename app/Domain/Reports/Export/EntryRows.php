<?php

namespace App\Domain\Reports\Export;

use App\Domain\Reports\Dimension;
use App\Domain\Reports\EntryValuation;
use App\Domain\Reports\RunningCents;
use App\Domain\Reports\Valuation;
use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use stdClass;

/**
 * Filas de la exportación de entradas de horas (SPEC §10, D-045), una por entrada (antes en
 * HoursExportController; Fase 9, D-139): fecha, persona, cliente, proyecto, bolsa, tarea, tipo,
 * horas, dentro de bolsa, exceso, facturable, estado y descripción. Con view-financials, además la
 * tarifa, las instantáneas de tarifa y coste y los importes de cada entrada (EntryValuation, D-043):
 * su parte exacta del total con los céntimos repartidos en orden (RunningCents), así que la suma
 * de la columna es el ingreso (y el coste) del informe con los mismos filtros (INT-04).
 * Filas planas (los nombres por LEFT JOIN, sin hidratar modelos) y por bloques con paginación por
 * clave (KeysetPages): hasta TableExporter::MAX_ROWS filas; si hay más, la última avisa (PERF-03,
 * PERF-06).
 */
final class EntryRows
{
    /** Entradas por bloque (una consulta por bloque; la valoración se prepara una vez). */
    public const int CHUNK = 1000;

    /**
     * @param  int  $maxRows  Filas como máximo, contando el aviso final (los tests lo reducen con una
     *                        vinculación contextual del contenedor).
     * @param  int  $chunkSize  Entradas por bloque.
     */
    public function __construct(
        private readonly int $maxRows = TableExporter::MAX_ROWS,
        private readonly int $chunkSize = self::CHUNK,
    ) {}

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
     * Filas por bloques (sin cargar todas las entradas a la vez), en orden de fecha e id.
     *
     * @param  Builder<TimeEntry>  $entries
     * @return Generator<int, array<int, string|int|float|bool|null>>
     */
    public function rows(Builder $entries, bool $financials): Generator
    {
        $valuation = $financials ? EntryValuation::for($entries) : null;
        $income = new RunningCents;
        $cost = new RunningCents;
        /** @var array<string, string> $statuses textos de estado, traducidos una vez */
        $statuses = [];
        $written = 0;
        $limit = min($this->maxRows, TableExporter::MAX_ROWS) - 1;

        foreach (KeysetPages::byDateAndId(self::flat($entries), $this->chunkSize) as $row) {
            if ($written === $limit) {
                // La última fila avisa de que hay más (mejor que cortar en silencio una exportación para facturar).
                yield [self::text('reports.r3.hours.truncated', ['count' => $limit])];

                return;
            }

            $written++;
            $status = (string) $row->status;

            yield $this->row($row, $statuses[$status] ??= TimeEntryStatus::from($status)->label(), $valuation, $income, $cost);
        }
    }

    /**
     * Las columnas de cada entrada y los nombres de su persona, cliente, proyecto, bolsa, tarea y
     * tipo por LEFT JOIN (también los borrados: sus horas siguen), sin hidratar modelos.
     *
     * @param  Builder<TimeEntry>  $entries
     */
    public static function flat(Builder $entries): QueryBuilder
    {
        $query = clone $entries;
        foreach (['users', 'projects', 'hour_banks', 'tasks'] as $table) {
            Dimension::ensureJoin($query, $table);
        }

        return $query->toBase()
            ->leftJoin('clients as report_clients', 'report_clients.id', '=', 'report_projects.client_id')
            ->leftJoin('task_types as report_task_types', 'report_task_types.id', '=', 'report_tasks.task_type_id')
            ->select(['time_entries.id', 'time_entries.date', 'time_entries.user_id', 'time_entries.project_id', 'time_entries.hour_bank_id',
                'time_entries.minutes', 'time_entries.overage_minutes', 'time_entries.is_billable', 'time_entries.status',
                'time_entries.description', 'time_entries.hourly_rate_snapshot', 'time_entries.hourly_cost_snapshot',
                'report_users.name as person_name', 'report_clients.name as client_name', 'report_projects.code as project_code',
                'report_projects.name as project_name', 'report_hour_banks.name as bank_name', 'report_tasks.title as task_title',
                'report_task_types.name as type_name']);
    }

    /**
     * @return array<int, string|int|float|bool|null>
     */
    private function row(stdClass $entry, string $status, ?EntryValuation $valuation, RunningCents $income, RunningCents $cost): array
    {
        $minutes = (int) $entry->minutes;
        $overage = (int) $entry->overage_minutes;
        $billable = (bool) $entry->is_billable;
        $bankId = $entry->hour_bank_id === null ? null : (int) $entry->hour_bank_id;
        $inBank = $bankId !== null;

        $row = [
            substr((string) $entry->date, 0, 10),
            (string) $entry->person_name,
            $entry->client_name === null ? null : (string) $entry->client_name,
            $entry->project_code.' · '.$entry->project_name,
            $inBank ? (string) $entry->bank_name : null,
            (string) $entry->task_title,
            $entry->type_name === null ? null : (string) $entry->type_name,
            TableExporter::hours($minutes),
            $inBank ? TableExporter::hours($minutes - $overage) : null,
            $inBank ? TableExporter::hours($overage) : null,
            $billable,
            $status,
            (string) $entry->description,
        ];

        if ($valuation !== null) {
            // La tarifa de D-043: la instantánea si está aprobada o bloqueada (también la del exceso
            // de una bolsa con precio); si no, la vigente. Sin tarifa si no es facturable ni en
            // proyectos internos o de precio cerrado (su ingreso es el reparto del importe).
            $valued = $valuation->next((int) $entry->project_id, $bankId, (int) $entry->user_id, $billable, $minutes, $overage,
                $entry->hourly_rate_snapshot, $entry->hourly_cost_snapshot);

            $row[] = TableExporter::money($valued['rate']);
            $row[] = TableExporter::money(Valuation::snapshot($entry->hourly_rate_snapshot));
            $row[] = TableExporter::money(Valuation::snapshot($entry->hourly_cost_snapshot));
            $row[] = TableExporter::money($income->next($valued['income']));
            $row[] = TableExporter::money($cost->next($valued['cost']));
        }

        return $row;
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
