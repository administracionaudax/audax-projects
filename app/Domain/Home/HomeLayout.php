<?php

namespace App\Domain\Home;

use App\Models\User;

/**
 * Orden de las tarjetas de Inicio de cada persona (D-138). Se guarda en `users.home_layout` como
 * una lista ordenada de ids de tarjeta. La interfaz pinta primero las guardadas en su orden y
 * después, en su orden por defecto, las que falten (tarjetas nuevas o que dependen del rol).
 *
 * La lista blanca es el contrato con `HOME_CARD_IDS` de resources/js/components/home/home-layout.ts:
 * tests/fixtures/home-cards.json las compara (Pest y Vitest).
 */
final class HomeLayout
{
    /**
     * Tarjetas de Inicio en su orden por defecto.
     */
    public const array CARDS = [
        'today-tasks',
        'timer',
        // Plan del día (D-250): «Mi día», con el módulo day_plan.
        'day-plan',
        'week-hours',
        'weekly',
        'workload',
        'unlogged-days',
        'indicators',
        'absences',
        'milestones',
        'mentions',
    ];

    /**
     * Tarjetas que no llegan a un colaborador externo (D-134): la weekly (D-147), carga, informes,
     * ausencias y el plan del día (D-251).
     */
    public const array COLLABORATOR_HIDDEN = ['weekly', 'workload', 'indicators', 'absences', 'day-plan'];

    /**
     * Las tarjetas que ve esta persona, en su orden por defecto.
     *
     * @return list<string>
     */
    public static function cardsFor(User $user): array
    {
        if (! $user->isCollaborator()) {
            return self::CARDS;
        }

        return array_values(array_diff(self::CARDS, self::COLLABORATOR_HIDDEN));
    }

    /**
     * El orden guardado, sin las tarjetas que ya no existen o que no le corresponden ni repetidas.
     * Null si no hay ninguno: la interfaz usa el orden por defecto.
     *
     * @return list<string>|null
     */
    public static function for(User $user): ?array
    {
        $saved = $user->home_layout;

        if (! is_array($saved)) {
            return null;
        }

        $known = self::cardsFor($user);
        $layout = array_values(array_unique(array_filter(
            $saved,
            fn (mixed $id): bool => is_string($id) && in_array($id, $known, true),
        )));

        return $layout === [] ? null : $layout;
    }
}
