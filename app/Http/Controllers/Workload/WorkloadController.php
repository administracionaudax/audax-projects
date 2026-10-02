<?php

namespace App\Http\Controllers\Workload;

use App\Domain\Workload\WorkloadBoard;
use App\Domain\Workload\WorkloadFilters;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Inertia\Support\Header;

/**
 * Vista «Carga» (SPEC §9, D-051, D-052): /carga?horizonte=…&departamento[]=…&persona[]=…
 * &cliente[]=…&proyecto[]=…&celda=persona:fecha. Por defecto, la semana que viene.
 *
 * Qué ve cada uno lo decide WorkloadScope (admin: todo; responsable: su departamento y él mismo; el
 * resto: su fila). El panel de una celda llega en la prop `cell` con una recarga parcial
 * (?celda=…, sin cambiar de página): fuera del alcance, esa recarga responde 403; en una visita
 * completa (un enlace compartido, por ejemplo) la celda se ignora y la página se abre sin panel.
 */
class WorkloadController extends Controller
{
    public function __invoke(Request $request): Response
    {
        Gate::authorize('viewAny', Task::class);

        /** @var User $user */
        $user = $request->user();

        /** @var array<string, mixed> $query */
        $query = $request->query();
        $board = WorkloadBoard::for($user, WorkloadFilters::fromQuery($query));
        $opensCell = self::asksForCell($request);

        return Inertia::render('workload/index', [
            'horizon' => $board->horizon(),
            'filters' => $board->filterProps(),
            'matrix' => fn (): array => $board->matrix(),
            'people' => fn (): array => $board->people(),
            'options' => fn (): array => $board->options(),
            'trays' => fn (): array => $board->trays(),
            'cell' => fn (): ?array => $board->cell(strict: $opensCell),
        ]);
    }

    /**
     * ¿Es la recarga parcial de esta página que pide el panel (only: ['cell'])?
     */
    private static function asksForCell(Request $request): bool
    {
        if ($request->header(Header::PARTIAL_COMPONENT) !== 'workload/index') {
            return false;
        }

        $only = array_map('trim', explode(',', (string) $request->header(Header::PARTIAL_ONLY, '')));

        return in_array('cell', $only, true);
    }
}
