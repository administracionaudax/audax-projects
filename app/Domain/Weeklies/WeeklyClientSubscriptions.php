<?php

namespace App\Domain\Weeklies;

use App\Models\Client;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * «Unirme a clientes» de la Weekly (D-221, sustituye a la membresía de D-156; el
 * `client_team_members` de WeeklySync): una suscripción propia de la Weekly, en
 * `weekly_client_subscriptions`. Solo sirve para que el cliente salga:
 *   - propuesto en «Mi weekly» (MyWeeklyClients),
 *   - en «Mis clientes» (colaboro) y en los clientes de la ficha de persona,
 *   - en el equipo del cliente de la Weekly (ClientInsights::teamMembers).
 * NUNCA da acceso al proyecto: ni chat, ni imputar horas, ni tareas, ni bolsas. Todo eso sigue
 * dependiendo de `project_members`, que la Weekly ya no toca.
 */
final class WeeklyClientSubscriptions
{
    public const string TABLE = 'weekly_client_subscriptions';

    /**
     * Clientes ACTIVOS a los que se ha unido la persona.
     *
     * @return list<int>
     */
    public function clientIds(User $user): array
    {
        return array_values(DB::table(self::TABLE)
            ->join('clients', 'clients.id', '=', self::TABLE.'.client_id')
            ->where(self::TABLE.'.user_id', $user->id)
            ->where('clients.is_active', true)
            ->whereNull('clients.deleted_at')
            ->orderBy(self::TABLE.'.client_id')
            ->pluck(self::TABLE.'.client_id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * Personas unidas al cliente (de cualquier rol; quien filtra es el que llama).
     *
     * @return list<int>
     */
    public function userIds(Client $client): array
    {
        return array_values(DB::table(self::TABLE)
            ->where('client_id', $client->id)
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * Por persona, los clientes a los que se ha unido (para el filtro por cliente del equipo).
     *
     * @param  list<int>|array<int, int>  $userIds
     * @return array<int, list<int>>
     */
    public function clientIdsByUser(array $userIds): array
    {
        $result = [];

        foreach (DB::table(self::TABLE)->whereIn('user_id', array_values($userIds))->get(['user_id', 'client_id']) as $row) {
            $result[(int) $row->user_id][] = (int) $row->client_id;
        }

        return $result;
    }

    public function isSubscribed(User $user, Client $client): bool
    {
        return DB::table(self::TABLE)->where('user_id', $user->id)->where('client_id', $client->id)->exists();
    }

    /**
     * Une a la persona a los clientes; devuelve cuántos son nuevos. Idempotente.
     *
     * @param  list<int>  $clientIds
     */
    public function subscribe(User $user, array $clientIds): int
    {
        $now = CarbonImmutable::now();
        $rows = array_map(fn (int $clientId): array => [
            'client_id' => $clientId,
            'user_id' => $user->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_values(array_unique($clientIds)));

        return $rows === [] ? 0 : DB::table(self::TABLE)->insertOrIgnore($rows);
    }

    /** Deja el cliente; devuelve si estaba unida. */
    public function unsubscribe(User $user, Client $client): bool
    {
        return DB::table(self::TABLE)->where('user_id', $user->id)->where('client_id', $client->id)->delete() > 0;
    }
}
