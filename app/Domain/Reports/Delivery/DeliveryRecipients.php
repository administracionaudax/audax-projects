<?php

namespace App\Domain\Reports\Delivery;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Destinatarios de un envío (D-141): personas de la app y correos externos.
 * - Personas: solo las activas de la plantilla (ni clientes ni colaboradores externos, D-134).
 *   Las que dejan de estarlo se quitan de los envíos programados al ejecutarse.
 * - Correos externos: en minúsculas y sin repetir; quedan en la auditoría y la interfaz avisa de
 *   que el informe sale de la empresa.
 * Como mucho MAX en total.
 */
final class DeliveryRecipients
{
    public const int MAX = 20;

    /**
     * Personas que pueden recibir informes.
     *
     * @return Builder<User>
     */
    public static function eligibleUsers(): Builder
    {
        return User::query()->active()->internal()->withoutCollaborators();
    }

    /**
     * Las personas de $ids que siguen pudiendo recibirlo, en el orden de $ids.
     *
     * @param  list<int>  $ids
     * @return Collection<int, User>
     */
    public static function users(array $ids): Collection
    {
        if ($ids === []) {
            return new Collection;
        }

        $users = self::eligibleUsers()->whereKey($ids)->get(['id', 'name', 'email'])->keyBy('id');

        return new Collection(array_values(array_filter(array_map(fn (int $id): ?User => $users->get($id), $ids))));
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    public static function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
    }

    /**
     * @param  array<int, mixed>  $emails
     * @return list<string>
     */
    public static function normalizeEmails(array $emails): array
    {
        $clean = [];

        foreach ($emails as $email) {
            if (is_string($email) && trim($email) !== '') {
                $clean[] = mb_strtolower(trim($email));
            }
        }

        return array_values(array_unique($clean));
    }
}
