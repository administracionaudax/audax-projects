<?php

namespace App\Domain\Import\WeeklySync\Stages;

use App\Domain\Import\WeeklySync\WeeklySyncContext;
use App\Domain\Import\WeeklySync\WeeklySyncImportReport as Report;
use App\Domain\Weeklies\WeeklyClientSubscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Colaboradores de cada cliente (`client_team_members`, D-149 y D-221): pasan a la suscripción de la
 * Weekly (`weekly_client_subscriptions`), NUNCA a miembros de proyecto, así que no dan acceso al chat,
 * las horas, las tareas ni las bolsas. Idempotente: la clave única (cliente, persona) evita duplicar.
 * Se omiten las filas cuya persona o cliente no se ha podido casar.
 */
final class ClientTeamStage
{
    public function run(WeeklySyncContext $context): void
    {
        $now = CarbonImmutable::now();

        foreach ($context->rows('client_team_members') as $row) {
            $userId = $context->user(WeeklySyncContext::id($row['user_id'] ?? null));
            $clientId = $context->client(WeeklySyncContext::id($row['client_id'] ?? null));

            if ($userId === null || $clientId === null) {
                $context->report->skip('client_team', 'Colaboradores de un cliente o de una persona sin casar');

                continue;
            }

            $created = DB::table(WeeklyClientSubscriptions::TABLE)->insertOrIgnore([
                'client_id' => $clientId,
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $context->report->count('client_team', $created > 0 ? Report::CREATED : Report::UNCHANGED);
        }
    }
}
