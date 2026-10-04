<?php

namespace App\Domain\Reports\Delivery\Documents;

use App\Domain\Reports\Delivery\ReportRequest;
use App\Domain\Reports\Pdf\PdfFormat;
use App\Domain\Reports\ReportFilters;
use App\Models\Client;
use App\Models\Department;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TaskType;
use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Piezas comunes de los documentos de informe (D-139, D-140): permisos con Gate::forUser (las
 * mismas políticas que la página), modelos de la ruta, filtros de la URL, portada y nombres.
 */
abstract class BaseDocument implements ReportDocument
{
    /**
     * Comprueba un permiso de $as. Lanza AuthorizationException si no lo tiene.
     */
    protected static function authorizeFor(User $as, string $ability, mixed $arguments): void
    {
        Gate::forUser($as)->authorize($ability, $arguments);
    }

    /**
     * El modelo de un parámetro de la ruta (p. ej. ['client' => 12]). Si ya no existe, el informe
     * ya no se puede ver: AuthorizationException (un envío programado se pausa, D-141).
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return TModel
     */
    protected static function routeModel(ReportRequest $request, string $param, string $class): Model
    {
        $id = $request->routeParams[$param] ?? null;
        $model = is_numeric($id) ? $class::query()->find((int) $id) : null;

        if (! $model instanceof $class) {
            throw new AuthorizationException(PdfFormat::text('report_pdf.errors.missing'));
        }

        return $model;
    }

    protected static function filters(ReportRequest $request): ReportFilters
    {
        return ReportFilters::fromQuery($request->query);
    }

    /**
     * Valor de texto de la query (o null).
     */
    protected static function queryString(ReportRequest $request, string $key): ?string
    {
        $value = $request->query[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Portada del PDF (reports.pdf.partials.cover): antetítulo, título, subtítulo (el periodo) y
     * los datos (periodo exacto, filtros, quién lo genera y cuándo, y si lleva importes).
     *
     * @param  list<array{0: string, 1: string}>  $facts
     * @return array{kicker: string, title: string, subtitle: string, facts: list<array{0: string, 1: string}>, note: string|null}
     */
    protected static function cover(string $kicker, string $title, string $subtitle, array $facts, User $as, ?bool $financials = null, ?string $note = null): array
    {
        $facts[] = [PdfFormat::text('report_pdf.cover.generated'), PdfFormat::text('report_pdf.cover.generated_value', [
            'date' => LocalTime::now()->format('d/m/Y H:i'),
            'name' => $as->name,
        ])];

        if ($financials !== null) {
            $facts[] = [PdfFormat::text('report_pdf.cover.financials'), PdfFormat::text($financials ? 'report_pdf.cover.financials_yes' : 'report_pdf.cover.financials_no')];
        }

        return ['kicker' => $kicker, 'title' => $title, 'subtitle' => $subtitle, 'facts' => $facts, 'note' => $note];
    }

    /**
     * Periodo exacto y filtros aplicados con sus nombres (también los borrados), sin los fijos del
     * informe ($skip: persona, departamento, cliente, proyecto, bolsa, tipo, facturable).
     *
     * @param  list<string>  $skip
     * @return list<array{0: string, 1: string}>
     */
    protected static function filterFacts(ReportFilters $filters, array $skip = []): array
    {
        $facts = [[PdfFormat::text('report_pdf.cover.period'), PdfFormat::text('report_pdf.period.range', [
            'from' => $filters->from->format('d/m/Y'),
            'to' => $filters->to->format('d/m/Y'),
        ])]];

        $lists = [
            'persona' => [User::class, $filters->userIds, 'name'],
            'departamento' => [Department::class, $filters->departmentIds, 'name'],
            'cliente' => [Client::class, $filters->clientIds, 'name'],
            'proyecto' => [Project::class, $filters->projectIds, 'code'],
            'bolsa' => [HourBank::class, $filters->bankIds, 'name'],
            'tipo' => [TaskType::class, $filters->taskTypeIds, 'name'],
        ];

        foreach ($lists as $key => [$class, $ids, $column]) {
            if (in_array($key, $skip, true) || $ids === []) {
                continue;
            }

            $query = $class::query();
            if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                $query->withoutGlobalScopes();
            }
            $names = $query->whereKey($ids)->orderBy($column)->pluck($column)->map(fn (mixed $name): string => (string) $name)->all();
            $facts[] = [PdfFormat::text('report_pdf.filters.'.$key), $names === [] ? '—' : implode(', ', $names)];
        }

        if (! in_array('facturable', $skip, true) && $filters->billable !== null) {
            $facts[] = [PdfFormat::text('report_pdf.filters.facturable'), PdfFormat::text($filters->billable ? 'report_pdf.filters.billable_yes' : 'report_pdf.filters.billable_no')];
        }

        return $facts;
    }

    /**
     * Nombre de fichero legible y seguro: «informe-cliente-monto-2026-09».
     */
    protected static function filename(string ...$parts): string
    {
        return Str::slug(implode(' ', array_filter($parts, fn (string $part): bool => $part !== '')), '-', 'es');
    }

    /**
     * Título del PDF: «Informe de cliente · Montó · septiembre de 2026».
     */
    protected static function joinTitle(string ...$parts): string
    {
        return implode(' · ', array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    protected static function t(string $key, array $replace = []): string
    {
        return PdfFormat::text($key, $replace);
    }
}
