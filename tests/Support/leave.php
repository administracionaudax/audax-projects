<?php

use App\Domain\Absences\LeaveLedger;
use App\Enums\LeaveMovementKind;
use App\Models\LeaveMovement;
use App\Models\LeaveType;
use App\Models\User;

/*
| Utilidades de los tests de vacaciones y permisos (Fase 11, R3).
*/

/** Un tipo del catálogo por su clave (vacation, force_majeure, marriage…). */
function leaveType(string $key): LeaveType
{
    return LeaveType::query()->where('key', $key)->firstOrFail();
}

/** RR. HH. sin ser admin: una empleada con `manage-people`. */
function hrUser(array $attributes = []): User
{
    return tap(userWithRole('employee', $attributes), fn (User $user) => $user->givePermissionTo('manage-people'));
}

/**
 * Un abono en el libro (como un saldo inicial), con su año, desde cuándo vale y su caducidad.
 */
function leaveCredit(User $user, string $key, int $year, int $amount, ?string $validFrom = null, ?string $expiresOn = null): LeaveMovement
{
    static $hr = null;
    $hr = $hr !== null && $hr->exists && User::query()->whereKey($hr->id)->exists() ? $hr : hrUser();

    return app(LeaveLedger::class)->adjust($hr, $user, leaveType($key), LeaveMovementKind::OpeningBalance, $amount, $year, 'Saldo de prueba', $validFrom, $expiresOn);
}
