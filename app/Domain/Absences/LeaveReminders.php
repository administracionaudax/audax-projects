<?php

namespace App\Domain\Absences;

use App\Domain\People\PeopleAccess;
use App\Enums\AbsenceStatus;
use App\Models\AbsenceReminder;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\Absences\AbsenceDocumentMissingNotification;
use App\Notifications\Absences\LeaveExpiringNotification;
use App\Support\LocalTime;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * La tarea diaria de vacaciones y permisos (Fase 11, R3; D-362 y D-369), `people:leave-daily`:
 * 1. asigna (o recalcula) el año en curso y el siguiente a toda la plantilla (LeaveLedger);
 * 2. avisa a cada persona, una vez por asignación, de lo que le caduca en 30 días sin gastar;
 * 3. avisa a cada persona, una vez por ausencia, del justificante que falta en una ausencia
 *    aprobada de un tipo que lo pide y que ya ha empezado.
 * Nada con el módulo `people` apagado de verdad (tampoco en modo de prueba, D-239).
 */
final class LeaveReminders
{
    public function __construct(
        private readonly LeaveLedger $ledger,
        private readonly LeaveBalances $balances,
    ) {}

    /**
     * @return array{accrued: int, expiring: int, documents: int}
     */
    public function run(?string $today = null): array
    {
        if (! LeaveMode::enabled()) {
            return ['accrued' => 0, 'expiring' => 0, 'documents' => 0];
        }

        $today ??= LocalTime::todayString();
        $year = (int) substr($today, 0, 4);
        $accrued = $this->ledger->syncYear($year) + $this->ledger->syncYear($year + 1);

        return [
            'accrued' => $accrued,
            'expiring' => $this->expiring($today),
            'documents' => $this->documents($today),
        ];
    }

    private function expiring(string $today): int
    {
        $people = array_values(User::query()->active()->with('roles')->get()->filter(fn (User $user): bool => PeopleAccess::staff($user))->all());
        $sent = 0;

        foreach ($this->balances->expiringSoon($people, $today) as $row) {
            /** @var LeaveType $type */
            $type = $row['type'];

            if (! $this->claim('expiring', 'lot:'.$row['lot_id'], $row['user_id'])) {
                continue;
            }

            User::query()->find($row['user_id'])?->notify(new LeaveExpiringNotification($type->name, $type->unit->value, $row['amount'], $row['expires_on']));
            $sent++;
        }

        return $sent;
    }

    private function documents(string $today): int
    {
        $sent = 0;

        foreach (AbsenceDocuments::missing(null, null, $today) as $absence) {
            if ($absence->status !== AbsenceStatus::Approved) {
                continue;
            }

            $owner = User::query()->find($absence->user_id);

            if ($owner === null || ! $owner->is_active || ! $this->claim('document', 'absence:'.$absence->id, $owner->id)) {
                continue;
            }

            $owner->notify(new AbsenceDocumentMissingNotification($absence, $owner));
            $sent++;
        }

        return $sent;
    }

    /** Anota el aviso; false si ya se había enviado (también si otro proceso se adelanta). */
    private function claim(string $kind, string $key, int $userId): bool
    {
        if (AbsenceReminder::query()->where('kind', $kind)->where('key', $key)->exists()) {
            return false;
        }

        try {
            $reminder = new AbsenceReminder;
            $reminder->forceFill(['kind' => $kind, 'key' => $key, 'user_id' => $userId, 'sent_at' => now()])->save();
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
