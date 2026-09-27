<?php

namespace App\Domain\Absences;

use App\Domain\Reports\ReportCache;
use App\Enums\AbsenceStatus;
use App\Enums\Role;
use App\Models\Absence;
use App\Models\User;
use App\Notifications\Absences\AbsenceApprovedNotification;
use App\Notifications\Absences\AbsenceCancelledNotification;
use App\Notifications\Absences\AbsenceRejectedNotification;
use App\Notifications\Absences\AbsenceRequestedNotification;
use App\Notifications\Absences\AbsenceUpdatedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Flujo de las ausencias (SPEC §4.1 y §13, D-049): solicitar, aprobar, rechazar, cancelar o anular,
 * registrar una ya aprobada y modificar una aprobada.
 *
 * - Cada paso lo autoriza AbsencePolicy y va en una transacción: al crear, con la persona
 *   bloqueada (dos solicitudes a la vez no se solapan); al revisar o cancelar, con la ausencia
 *   bloqueada y su estado comprobado de nuevo.
 * - Los cambios quedan en la auditoría de Absence (LogsDomainActivity: quién, antes y después).
 * - Los avisos (en la app y por email, por cola) salen después de confirmar la transacción.
 * - Las ausencias aprobadas restan capacidad (Capacity): cada cambio que las toca invalida la caché
 *   de los informes (ReportCache::bump(), D-046) tras confirmar la transacción.
 * - Las ausencias de responsables y admins se aprueban solas al solicitarlas (sin aviso a nadie,
 *   como las semanas de horas, D-041). `approved_by` guarda quién la revisó: quien la aprueba, la
 *   rechaza, la registra o la modifica; null en la aprobación automática.
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
            $this->lockPerson($actor->id);
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

        if ($autoApproved) {
            ReportCache::bump();
        } else {
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
            $this->lockPerson($target->id);
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
        ReportCache::bump();

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

        ReportCache::bump();
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
     * Quien puede aprobarla modifica una ausencia aprobada de otra persona: tipo, fechas, parte del
     * día y notas (acortar una baja que termina antes, por ejemplo). Con las mismas reglas que al
     * crearla, sin contar la propia ausencia como solape. Si cambia algo, queda como revisada por
     * quien la modifica y se avisa a la persona con lo que había antes; si no cambia nada, no se
     * guarda ni se avisa (wasChanged() lo dice).
     *
     * @throws ValidationException
     */
    public function update(User $actor, Absence $absence, AbsenceData $data): Absence
    {
        Gate::forUser($actor)->authorize('update', $absence);

        /** @var array{0: Absence, 1: string|null} $result */
        $result = DB::transaction(function () use ($actor, $absence, $data): array {
            $this->lockPerson($absence->user_id);
            $current = $this->relock($absence);

            if (Gate::forUser($actor)->denies('update', $current)) {
                throw ValidationException::withMessages(['absence' => AbsenceText::get('absences.errors.not_editable', [
                    'status' => AbsenceText::status($current->status),
                ])]);
            }

            $this->rules->check($actor, $current->user, $data, ignoreId: $current->id);

            $before = $current->type->label().' '.AbsenceText::period(
                $current->start_date->toDateString(),
                $current->end_date->toDateString(),
                $current->partial_minutes,
            );

            $current->fill($data->attributes());

            if (! $current->isDirty()) {
                return [$current, null];
            }

            $current->fill(['approved_by' => $actor->id, 'reviewed_at' => now()])->save();

            return [$current, $before];
        });

        [$absence, $before] = $result;

        if ($before === null) {
            return $absence;
        }

        ReportCache::bump();

        if ($absence->user->id !== $actor->id && $absence->user->is_active) {
            $absence->user->notify(new AbsenceUpdatedNotification($absence, $actor, $before));
        }

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
            ReportCache::bump();

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
    private function lockPerson(int $userId): void
    {
        User::query()->whereKey($userId)->lockForUpdate()->first(['id']);
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
