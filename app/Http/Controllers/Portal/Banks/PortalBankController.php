<?php

namespace App\Http\Controllers\Portal\Banks;

use App\Domain\HourBanks\HourBankHistory;
use App\Domain\HourBanks\HourBankLedger;
use App\Domain\Portal\PortalBankFigures;
use App\Domain\Portal\PortalScope;
use App\Enums\PortalPersonDisplay;
use App\Http\Controllers\Controller;
use App\Http\Resources\Projects\Paginated;
use App\Models\HourBank;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Detalle de una bolsa en el portal (SPEC §11, D-064): cifras, barra (dentro y exceso por
 * separado), estado y fechas, consumo por mes, las entradas que ve el cliente (fecha, tarea, tipo,
 * persona según su ajuste, duración y descripción; nunca importes, tarifas ni costes) y el histórico
 * de renovaciones de su cadena, solo con bolsas de su cliente.
 *
 * Una bolsa que no es de su cliente (o que no existe) da 404: nunca un 403 que revele que existe.
 * Consultas fijas (sin N+1): el cliente, sus bolsas con los proyectos, las cifras de la cadena, el
 * consumo por mes y la página de entradas con sus tareas, tipos y personas.
 */
class PortalBankController extends Controller
{
    public const int ENTRIES_PER_PAGE = 25;

    public function show(Request $request, int $bank, HourBankLedger $ledger, HourBankHistory $history): Response
    {
        /** @var User $user */
        $user = $request->user();
        $scope = PortalScope::for($user);

        // Todas las bolsas del cliente en una consulta: la pedida y su cadena de renovaciones.
        $banks = PortalBankData::banks($scope);
        $current = $banks->firstWhere('id', $bank);
        abort_unless($current instanceof HourBank, 404);

        $chain = $this->chain($history, $banks, $current);
        $figures = PortalBankFigures::many($scope, $chain === [] ? [$current] : $chain);
        $month = self::month($request->query('mes'));
        $named = $scope->client->portal_person_display !== PortalPersonDisplay::Team;

        $entries = $scope->bankEntries($current)
            ->with([
                'task' => fn ($task) => $task->select(['id', 'title', 'task_type_id']),
                'task.type' => fn ($type) => $type->select(['id', 'name', 'color']),
            ])
            ->when($named, fn (Builder $query) => $query->with(['user' => fn ($person) => $person->select(['id', 'name'])]))
            ->when($month !== null, fn (Builder $query) => $query->whereBetween('date', [$month['from'] ?? '', $month['to'] ?? '']))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(self::ENTRIES_PER_PAGE, ['id', 'user_id', 'task_id', 'date', 'minutes', 'overage_minutes', 'description'], 'pagina')
            ->withQueryString();

        $items = [];
        foreach ($entries->items() as $entry) {
            $items[] = $this->entry($scope, $entry, $named);
        }

        return Inertia::render('portal/banks/show', [
            'bank' => PortalBankData::item($current, $figures[$current->id]),
            'visibility' => $scope->client->portal_entry_visibility->value,
            'thresholds' => $ledger->thresholds(),
            'months' => PortalBankFigures::byMonth($scope, $current),
            'entries' => Paginated::props($entries, $items),
            'filters' => ['mes' => $month['value'] ?? null],
            'history' => array_map(fn (HourBank $link): array => [
                ...PortalBankData::item($link, $figures[$link->id]),
                'current' => $link->id === $current->id,
            ], $chain),
        ]);
    }

    /**
     * La cadena de renovaciones de la bolsa (de la más antigua a la más reciente), solo con bolsas
     * del cliente (HourBankHistory trabaja sobre las que recibe). Vacía si nunca se ha renovado.
     *
     * @param  Collection<int, HourBank>  $banks
     * @return list<HourBank>
     */
    private function chain(HourBankHistory $history, Collection $banks, HourBank $current): array
    {
        $byId = $banks->keyBy('id');

        foreach ($history->chains($banks) as $chain) {
            $ids = array_column($chain, 'id');

            if (in_array($current->id, $ids, true)) {
                $links = [];
                foreach ($ids as $id) {
                    $link = $byId->get($id);
                    if ($link instanceof HourBank) {
                        $links[] = $link;
                    }
                }

                return $links;
            }
        }

        return [];
    }

    /**
     * Una entrada tal como la ve el cliente: la persona según su ajuste y sin ningún dato económico.
     *
     * @return array{id: int, date: string, task: string, type: array{name: string, color: string}|null, person: string, minutes: int, overage_minutes: int, description: string|null}
     */
    private function entry(PortalScope $scope, TimeEntry $entry, bool $named): array
    {
        $type = $entry->task->type;

        return [
            'id' => $entry->id,
            'date' => $entry->date->toDateString(),
            'task' => $entry->task->title,
            'type' => $type === null ? null : ['name' => $type->name, 'color' => $type->color],
            'person' => $scope->personLabel($named ? $entry->user : null),
            'minutes' => $entry->minutes,
            'overage_minutes' => $entry->overage_minutes,
            'description' => $entry->description,
        ];
    }

    /**
     * Filtro de mes de las entradas (?mes=AAAA-MM). Un valor que no es un mes se ignora.
     *
     * @return array{value: string, from: string, to: string}|null
     */
    public static function month(mixed $value): ?array
    {
        if (! is_string($value) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return null;
        }

        $start = CarbonImmutable::parse($value.'-01');

        return ['value' => $value, 'from' => $start->toDateString(), 'to' => $start->endOfMonth()->toDateString()];
    }
}
