<?php

namespace App\Domain\HourBanks;

use App\Models\HourBank;
use Illuminate\Support\Collection;

/**
 * Histórico de renovaciones (SPEC §8.8): cadenas de bolsas enlazadas por renewed_from_id, de la
 * más antigua a la más reciente. Solo las cadenas con al menos una renovación. Sirve igual para
 * las bolsas de un proyecto que para las de todos los proyectos de un cliente: no hace consultas,
 * trabaja sobre las bolsas que recibe.
 */
final class HourBankHistory
{
    /**
     * @param  Collection<int, HourBank>  $banks
     * @return list<list<array{id: int, project_id: int, name: string, status: string, start_date: string, end_date: string|null}>>
     */
    public function chains(Collection $banks): array
    {
        $byId = $banks->keyBy('id');
        $next = [];

        foreach ($banks as $bank) {
            if ($bank->renewed_from_id !== null && $byId->has($bank->renewed_from_id)) {
                $next[$bank->renewed_from_id] = $bank;
            }
        }

        $chains = [];

        foreach ($banks->sortBy([['start_date', 'asc'], ['id', 'asc']]) as $bank) {
            $isStart = $bank->renewed_from_id === null || ! $byId->has($bank->renewed_from_id);

            if (! $isStart || ! isset($next[$bank->id])) {
                continue;
            }

            $chain = [];
            $current = $bank;
            $seen = [];

            while ($current !== null && ! isset($seen[$current->id])) {
                $seen[$current->id] = true;
                $chain[] = [
                    'id' => $current->id,
                    'project_id' => $current->project_id,
                    'name' => $current->name,
                    'status' => $current->status->value,
                    'start_date' => $current->start_date->toDateString(),
                    'end_date' => $current->end_date?->toDateString(),
                ];
                $current = $next[$current->id] ?? null;
            }

            $chains[] = $chain;
        }

        return $chains;
    }
}
