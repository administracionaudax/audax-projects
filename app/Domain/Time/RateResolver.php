<?php

namespace App\Domain\Time;

use App\Models\Client;
use App\Models\HourBank;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;

/**
 * Tarifa y coste que se congelan al aprobar una entrada (SPEC §4.4, D-034):
 * - tarifa: la de la bolsa; si no, la del proyecto; si no, la del cliente; si no, la del usuario;
 *   null si ninguna la tiene,
 * - coste: el coste por hora de la persona (users.hourly_cost), o null.
 * Los importes son decimales como string ("45.00"), nunca float.
 */
final class RateResolver
{
    public function rate(?HourBank $bank, ?Project $project, ?Client $client, ?User $user): ?string
    {
        foreach ([$bank?->hourly_rate, $project?->hourly_rate, $client?->default_hourly_rate, $user?->default_hourly_rate] as $rate) {
            if ($rate !== null && $rate !== '') {
                return (string) $rate;
            }
        }

        return null;
    }

    public function cost(User $user): ?string
    {
        return $user->hourly_cost !== null && $user->hourly_cost !== '' ? (string) $user->hourly_cost : null;
    }

    /**
     * Tarifa de una entrada. Requiere hourBank y project.client cargados (evita consultas por fila);
     * la persona se pasa aparte porque en una semana es siempre la misma.
     */
    public function rateFor(TimeEntry $entry, User $user): ?string
    {
        $project = $entry->project;

        return $this->rate($entry->hourBank, $project, $project->client, $user);
    }
}
