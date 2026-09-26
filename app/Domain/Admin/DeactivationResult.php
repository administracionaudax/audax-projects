<?php

namespace App\Domain\Admin;

/**
 * Resumen de una desactivación (UserDeactivator) para el aviso que ve el admin.
 */
final class DeactivationResult
{
    public const string TIMER_NONE = 'none';

    /** Se paró e imputó lo medido. */
    public const string TIMER_STOPPED = 'stopped';

    /** La imputación falló (semana cerrada, bolsa sin saldo…): se descartó. */
    public const string TIMER_DISCARDED = 'discarded';

    /** Duró menos de lo que se redondea: no había nada que imputar. */
    public const string TIMER_TOO_SHORT = 'too_short';

    public int $reassigned = 0;

    public int $unassigned = 0;

    public int $departmentsLeft = 0;

    public string $timer = self::TIMER_NONE;

    public int $timerMinutes = 0;

    /**
     * @var list<string>
     */
    public array $timerErrors = [];
}
