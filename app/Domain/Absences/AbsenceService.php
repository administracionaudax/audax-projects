<?php

namespace App\Domain\Absences;

use App\Enums\AbsenceStatus;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\User;
use App\Notifications\Absences\AbsenceApprovedNotification;
use App\Notifications\Absences\AbsenceCancelledNotification;
use App\Notifications\Absences\AbsenceRejectedNotification;
use App\Notifications\Absences\AbsenceRequestedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Flujo de las ausencias (SPEC §4.1 y §13, D-049): solicitar, aprobar, rechazar, cancelar o anular
 * y registrar una ya aprobada.
 *
 * - Cada paso lo autoriza AbsencePolicy y va en una transacción: al crear, con la persona
 *   bloqueada (dos solicitudes a la vez no se solapan); al revisar o cancelar, con la ausencia
 *   bloqueada y su estado comprobado de nuevo.
 * - Los cambios quedan en la auditoría de Absence (LogsDomainActivity: quién, antes y después).
 * - Los avisos (en la app y por email, por cola) salen después de confirmar la transacción.
 * - Las ausencias de responsables y admins se aprueban solas al solicitarlas (sin aviso a nadie,
 *   como las semanas de horas, D-041). `approved_by` guarda quién la revisó: quien la aprueba, la
 *   rechaza o la registra; null en la aprobación automática.
 */
final class AbsenceService
{
    public function __construct(
        private readonly AbsenceRules $rules,
        private readonly AbsenceApprovers $approvers,
    ) {}

    /**
     * ¿Se aprueban solas las ausencias de $user? Las de responsables y admins (D-049).
     */
    public function selfApproves(User $user): bool
    {
        return $user->hasAnyRole([Role::Admin->value, Role::DepartmentManager->value]);
    }

    /**
     * La persona solicita una ausencia suya. Queda solicitada (y se avisa a quien puede aprobarla)
     * o, si es responsable o admin, aprobada.
     *
     * @throws ValidationException
     */
    public function request(User $actor, AbsenceData $data): Absence
    {
        Gate::forUser($actor)->authorize('create', Absence::class);

        $autoApproved = $this->selfApproves($actor);

        $absence = DB::transaction(function () use ($actor, $data, $autoApproved): Absence {
            $this->lockPerson($actor);
            $this->rules->check($actor, $actor, $data);

            return Absence::query()->create([
                ...$data->attributes(),
                'user_id' => $actor->id,
                'status' => $autoApproved ? AbsenceStatus::Approved : AbsenceStatus::Requested,
                'approved_by' => null,
                'reviewed_at' => $autoApproved ? now() : null,
            ]);
        });

        $absence->setRelation('user', $actor);

        if (! $autoApproved) {
            Notification::send($this->approvers->for($actor), new AbsenceRequestedNotification($absence, $actor));
        }

        return $absence;
    }

    /**
     * Un responsable (de su equipo) o un admin registra una ausencia ya aprobada de $target: una
     * baja, por ejemplo. Se avisa a la persona.
     *
     * @throws ValidationException
     */
    public function register(User $actor, User $target, AbsenceData $data): Absence
    {
        if (Gate::forUser($actor)->denies('register', [Absence::class, $target])) {
            throw ValidationException::withMessages(['user_id' => AbsenceText::get('absences.errors.cannot_register')]);
        }

        $absence = DB::transaction(function () use ($actor, $target, $data): Absence {
            $this->lockPerson($target);
            $this->rules->check($actor, $target, $data);

            return Absence::query()->create([
                ...$data->attributes(),
                'user_id' => $target->id,
                'status' => AbsenceStatus::Approved,
                'approved_by' => $actor->id,
                'reviewed_at' => now(),
            ]);
        });

        $absence->setRelation('user', $target);

        if ($target->id !== $actor->id) {
            $target->notify(new AbsenceApprovedNotification($absence, $actor, registered: true));
        }

        return $absence;
    }

    /**
     * @throws ValidationException
     */
    public function approve(User $reviewer, Absence $absence): Absence
    {
        Gate::forUser($reviewer)->authorize('review', $absence);

        $absence = DB::transaction(function () use ($reviewer, $absence): Absence {
            $current = $this->relock($absence);
            $this->assertRequested($current);

            $current->fill([
                'status' => AbsenceStatus::Approved,
                'approved_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_comment' => null,
            ])->save();

            return $current;
        });

        $absence->user->notify(new AbsenceApprovedNotification($absence, $reviewer));

        return $absence;
    }

    /**
     * Rechaza una solicitud con un comentario obligatorio, que recibe la persona.
     *
     * @throws ValidationException
     */
    public function reject(User $reviewer, Absence $absence, string $comment): Absence
    {
        Gate::forUser($reviewer)->authorize('review', $absence);

        $comment = trim($comment);
        if ($comment === '') {
            throw ValidationException::withMessages(['comment' => AbsenceText::get('absences.errors.reject_comment_required')]);
        }

        $absence = DB::transaction(function () use ($reviewer, $absence, $comment): Absence {
            $current = $this->relock($absence);
            $this->assertRequested($current);

            $current->fill([
                'status' => AbsenceStatus::Rejected,
                'approved_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_comment' => $comment,
            ])->save();

            return $current;
        });

        $absence->user->notify(new AbsenceRejectedNotification($absence, $reviewer, $comment));

        return $absence;
    }

    /**
     * La persona cancela una suya (solicitada, o aprobada que aún no ha empezado) o quien puede
     * aprobarla anula una aprobada. Si era aprobada, se avisa a la otra parte.
     *
     * @throws ValidationException
     */
    public function cancel(User $actor, Absence $absence): Absence
    {
        Gate::forUser($actor)->authorize('cancel', $absence);

        $previous = $absence->status;

        $absence = DB::transaction(function () use ($actor, $absence, &$previous): Absence {
            $current = $this->relock($absence);
            $previous = $current->status;

            if (Gate::forUser($actor)->denies('cancel', $current)) {
                throw ValidationException::withMessages(['absence' => AbsenceText::get('absences.errors.not_cancellable')]);
            }

            $current->fill(['status' => AbsenceStatus::Cancelled])->save();

            return $current;
        });

        if ($previous === AbsenceStatus::Approved) {
            $byOwner = $actor->id === $absence->user_id;
            $recipient = $byOwner ? $this->approverOf($absence) : $absence->user;

            if ($recipient !== null && $recipient->id !== $actor->id && $recipient->is_active) {
                $recipient->notify(new AbsenceCancelledNotification($absence, $actor, $byOwner));
            }
        }

        return $absence;
    }

    /**
     * Quien aprobó o registró la ausencia (null si se aprobó sola).
     */
    private function approverOf(Absence $absence): ?User
    {
        return $absence->approved_by !== null ? User::query()->find($absence->approved_by) : null;
    }

    /**
     * Bloquea a la persona mientras se comprueban los solapes y se crea su ausencia.
     */
    private function lockPerson(User $person): void
    {
        User::query()->whereKey($person->id)->lockForUpdate()->first(['id']);
    }

    private function relock(Absence $absence): Absence
    {
        /** @var Absence $current */
        $current = Absence::query()->with('user')->whereKey($absence->id)->lockForUpdate()->firstOrFail();

        return $current;
    }

    /**
     * @throws ValidationException
     */
    private function assertRequested(Absence $absence): void
    {
        if ($absence->status !== AbsenceStatus::Requested) {
            throw ValidationException::withMessages(['absence' => AbsenceText::get('absences.errors.not_requested', [
                'status' => AbsenceText::status($absence->status),
            ])]);
        }
    }
}
