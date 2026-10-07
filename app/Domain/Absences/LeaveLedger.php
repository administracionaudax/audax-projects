<?php

namespace App\Domain\Absences;

use App\Domain\People\PeopleAccess;
use App\Enums\LeaveMovementKind;
use App\Models\EmploymentProfile;
use App\Models\LeaveMovement;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El libro de saldos de ausencias (Fase 11, R3; W-060 a W-066; D-362 y D-363): el **único** que
 * escribe en `leave_movements`, que es de solo alta (modelo y *trigger*) y sellado (cada fila
 * guarda la huella de su contenido; verify() la comprueba). Un saldo nunca se edita: se corrige con
 * un movimiento nuevo, y todo queda en la auditoría (`leave-balances`).
 *
 * - **Asignación anual** (syncAccrual): lo que corresponde (LeaveAccrual) menos lo ya asignado de
 *   ese año; si cambia el alta, la baja, la jornada o el tipo, se anota la diferencia. Solo desde el
 *   año de inicio de los saldos (`people_leave_starts_on`): el de antes viene en el saldo inicial.
 * - **Ajuste** y **saldo inicial** (adjust): solo RR. HH. (`manage-people`), con motivo.
 * - **Arrastre** (carryOver): pasa una cantidad a otra fecha de caducidad (IT o nacimiento, art.
 *   38.3 ET: hasta 18 meses tras el final del año): un cargo y un abono, con el mismo motivo.
 * - **Importación** del saldo inicial desde Woffu (importOpening), para R5.
 */
final class LeaveLedger
{
    public const string VERSION = 'v1';

    /** Meses tras el final del año de devengo para disfrutar las vacaciones aplazadas por una IT (art. 38.3 ET). */
    public const int MAX_CARRY_MONTHS = 18;

    /**
     * Asigna (o recalcula) el año de una persona en un tipo. Devuelve el movimiento anotado, o null
     * si ya estaba bien.
     */
    public function syncAccrual(User $user, LeaveType $type, int $year, ?User $actor = null): ?LeaveMovement
    {
        if (! $type->hasAllowance() || ! PeopleAccess::internalStaff($user) || ! self::accruesYear($year)) {
            return null;
        }

        return DB::transaction(function () use ($user, $type, $year, $actor): ?LeaveMovement {
            User::query()->whereKey($user->id)->lockForUpdate()->value('id');

            $profile = EmploymentProfile::query()->where('user_id', $user->id)->first();
            /** @var Collection<int, WorkSchedule> $schedules */
            $schedules = WorkSchedule::query()->where('user_id', $user->id)->orderByDesc('valid_from')->get();
            $expected = LeaveAccrual::amount($type, $year, $profile, $schedules);
            $current = (int) LeaveMovement::query()
                ->where('user_id', $user->id)
                ->where('leave_type_id', $type->id)
                ->where('year', $year)
                ->where('kind', LeaveMovementKind::Accrual->value)
                ->sum('amount');

            $delta = $expected - $current;

            if ($delta === 0) {
                return null;
            }

            $reason = $current === 0
                ? (string) __('leave.reasons.accrual', ['year' => $year, 'amount' => LeaveFormat::amount($expected, $type->unit)])
                : (string) __('leave.reasons.recalculated', ['year' => $year, 'amount' => LeaveFormat::amount($expected, $type->unit)]);

            $movement = $this->append($user->id, $type, $year, LeaveMovementKind::Accrual, $delta, sprintf('%04d-01-01', $year), $type->expiryFor($year), $reason, $actor?->id);
            $this->log($actor, $movement, 'leave_accrued');

            return $movement;
        });
    }

    /**
     * Asigna (o recalcula) un año a toda la plantilla interna (o a una persona) en todos los tipos con
     * saldo. Lo hace `people:leave-daily` cada noche (el año en curso y el siguiente) y al cambiar los
     * datos laborales o un tipo.
     *
     * @return int Movimientos anotados.
     */
    public function syncYear(int $year, ?User $only = null, ?LeaveType $type = null, ?User $actor = null): int
    {
        $types = $type !== null ? collect([$type])->filter(fn (LeaveType $item): bool => $item->hasAllowance()) : LeaveBalances::allowanceTypes();

        if ($types->isEmpty() || ! self::accruesYear($year)) {
            return 0;
        }

        $people = $only !== null ? collect([$only]) : self::staffForYear($year);
        $count = 0;

        foreach ($people as $person) {
            foreach ($types as $item) {
                $count += $this->syncAccrual($person, $item, $year, $actor) !== null ? 1 : 0;
            }
        }

        return $count;
    }

    /**
     * La plantilla interna con derecho a asignación en el año: activa, o de baja con una fecha de baja
     * dentro del año (su parte proporcional). Nunca colaboradores externos ni clientes (D-330).
     *
     * @return Collection<int, User>
     */
    public static function staffForYear(int $year): Collection
    {
        return User::query()
            ->with(['employmentProfile', 'roles'])
            ->orderBy('name')
            ->get()
            ->filter(function (User $user) use ($year): bool {
                if (! PeopleAccess::internalStaff($user)) {
                    return false;
                }

                if ($user->isActive()) {
                    return true;
                }

                $end = $user->employmentProfile?->termination_date;

                return $end !== null && (int) $end->format('Y') >= $year;
            })
            ->values();
    }

    /**
     * ¿Se asigna solo ese año? Desde el año del inicio de los saldos en la app, si empieza el 1 de
     * enero; si empieza a mitad de año, ese año ya viene en el saldo inicial (R5).
     */
    public static function accruesYear(int $year): bool
    {
        $start = LeaveBalances::startsOn();

        return $start === null || sprintf('%04d-01-01', $year) >= $start;
    }

    /**
     * Un ajuste o un saldo inicial a mano: solo RR. HH., con motivo.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function adjust(User $actor, User $subject, LeaveType $type, LeaveMovementKind $kind, int $amount, int $year, string $reason, ?string $validFrom = null, ?string $expiresOn = null): LeaveMovement
    {
        if (! PeopleAccess::managesAll($actor) || ! PeopleAccess::internalStaff($subject)) {
            throw new AuthorizationException;
        }

        if (! in_array($kind, LeaveMovementKind::manual(), true)) {
            throw ValidationException::withMessages(['kind' => __('leave.errors.kind')]);
        }

        $reason = trim($reason);
        $errors = [];

        if (mb_strlen($reason) < 5) {
            $errors['reason'][] = __('leave.errors.reason');
        }
        if ($amount === 0) {
            $errors['amount'][] = __('leave.errors.zero');
        }

        $validFrom ??= sprintf('%04d-01-01', $year);
        $expiresOn = $expiresOn === '' ? null : ($expiresOn ?? $type->expiryFor($year));

        if ($expiresOn !== null && $expiresOn < $validFrom) {
            $errors['expires_on'][] = __('leave.errors.expiry_before');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $movement = DB::transaction(fn (): LeaveMovement => $this->append($subject->id, $type, $year, $kind, $amount, $validFrom, $expiresOn, $reason, $actor->id));
        $this->log($actor, $movement, 'leave_adjusted');

        return $movement;
    }

    /**
     * Arrastre a otra fecha de caducidad (art. 38.3 ET): un cargo en el año de origen y un abono con
     * la caducidad nueva, como mucho 18 meses tras el final de ese año. Solo RR. HH.
     *
     * @return array{0: LeaveMovement, 1: LeaveMovement}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function carryOver(User $actor, User $subject, LeaveType $type, int $year, int $amount, string $expiresOn, string $reason): array
    {
        if (! PeopleAccess::managesAll($actor) || ! PeopleAccess::internalStaff($subject)) {
            throw new AuthorizationException;
        }

        $reason = trim($reason);
        $max = CarbonImmutable::create($year, 12, 31)->addMonthsNoOverflow(self::MAX_CARRY_MONTHS)->toDateString();
        $errors = [];

        if (mb_strlen($reason) < 5) {
            $errors['reason'][] = __('leave.errors.reason');
        }
        if ($amount <= 0) {
            $errors['amount'][] = __('leave.errors.zero');
        }
        if ($expiresOn <= $type->expiryFor($year) || $expiresOn > $max) {
            $errors['expires_on'][] = __('leave.errors.carry_range', ['max' => CarbonImmutable::parse($max)->format('d/m/Y')]);
        }

        if ($errors === []) {
            // Lo que queda de ese año (aunque ya haya caducado: para eso se arrastra).
            $summary = collect(app(LeaveBalances::class)->forUser($subject, $year, types: collect([$type])))->first();
            $left = array_sum(array_map(fn (array $lot): int => max($lot['remaining'], 0), array_filter($summary['lots'] ?? [], fn (array $lot): bool => $lot['year'] === $year)));

            if ($amount > $left) {
                $errors['amount'][] = __('leave.errors.carry_left', ['amount' => LeaveFormat::amount($left, $type->unit), 'year' => $year]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // El cargo, en una fecha en la que aún vale lo de ese año: hoy, o su caducidad si ya pasó.
        $from = min(max(LocalTime::todayString(), sprintf('%04d-01-01', $year)), $type->expiryFor($year));

        $movements = DB::transaction(fn (): array => [
            $this->append($subject->id, $type, $year, LeaveMovementKind::CarryOver, -$amount, $from, null, $reason, $actor->id),
            $this->append($subject->id, $type, $year, LeaveMovementKind::CarryOver, $amount, $from, $expiresOn, $reason, $actor->id),
        ]);

        $this->log($actor, $movements[1], 'leave_carried_over');

        return $movements;
    }

    /**
     * Saldo inicial traído de Woffu (R5): una fila por persona y tipo con la cantidad (en días o en
     * horas, como en Woffu) y, opcionalmente, su caducidad. Con $dryRun solo dice qué haría.
     *
     * @param  list<array{email: string, type: string, amount: string, expires_on?: string|null}>  $rows
     * @return list<array{line: int, email: string, type: string, amount: int|null, status: string, message: string|null}>
     */
    public function importOpening(User $actor, array $rows, string $cutDate, bool $dryRun = false): array
    {
        if (! PeopleAccess::managesAll($actor)) {
            throw new AuthorizationException;
        }

        $types = LeaveType::query()->get()->keyBy('key');
        $year = (int) substr($cutDate, 0, 4);
        $result = [];

        foreach ($rows as $index => $row) {
            $user = User::query()->where('email', mb_strtolower(trim($row['email'])))->first();
            /** @var LeaveType|null $type */
            $type = $types->get(trim($row['type']));
            $expires = isset($row['expires_on']) && trim((string) $row['expires_on']) !== '' ? trim((string) $row['expires_on']) : null;
            $entry = ['line' => $index + 1, 'email' => $row['email'], 'type' => $row['type'], 'amount' => null, 'status' => 'error', 'message' => null];

            if ($user === null || ! PeopleAccess::internalStaff($user)) {
                $result[] = [...$entry, 'message' => self::text('leave.import.unknown_person')];

                continue;
            }

            if ($type === null) {
                $result[] = [...$entry, 'message' => self::text('leave.import.unknown_type')];

                continue;
            }

            $amount = LeaveFormat::parse($row['amount'], $type->unit);

            if ($amount === null) {
                $result[] = [...$entry, 'message' => self::text('leave.import.bad_amount')];

                continue;
            }

            if ($expires !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) !== 1) {
                $result[] = [...$entry, 'amount' => $amount, 'message' => self::text('leave.import.bad_date')];

                continue;
            }

            if (! $dryRun && $amount !== 0) {
                $this->adjust($actor, $user, $type, LeaveMovementKind::OpeningBalance, $amount, $year, self::text('leave.reasons.opening', ['date' => CarbonImmutable::parse($cutDate)->format('d/m/Y')]), $cutDate, $expires ?? $type->expiryFor($year));
            }

            $result[] = [...$entry, 'amount' => $amount, 'status' => $dryRun ? 'ok' : 'imported'];
        }

        return $result;
    }

    /**
     * Las filas cuya huella no cuadra con su contenido (alguien la cambió quitando el *trigger*).
     *
     * @return list<int>
     */
    public function verify(): array
    {
        $broken = [];

        foreach (LeaveMovement::query()->orderBy('id')->lazy() as $movement) {
            if (! hash_equals($movement->hash, self::seal($movement))) {
                $broken[] = $movement->id;
            }
        }

        return $broken;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }

    public static function seal(LeaveMovement $movement): string
    {
        return hash('sha256', implode("\n", [
            self::VERSION,
            'leave_movement',
            (string) $movement->user_id,
            (string) $movement->leave_type_id,
            (string) $movement->year,
            $movement->kind->value,
            (string) $movement->amount,
            $movement->valid_from->toDateString(),
            $movement->expires_on?->toDateString() ?? '',
            $movement->reason,
            (string) ($movement->created_by ?? ''),
            $movement->created_at instanceof DateTimeInterface ? CarbonImmutable::instance($movement->created_at)->utc()->format('Y-m-d\TH:i:s\Z') : '',
        ]));
    }

    private function append(int $userId, LeaveType $type, int $year, LeaveMovementKind $kind, int $amount, string $validFrom, ?string $expiresOn, string $reason, ?int $by): LeaveMovement
    {
        $movement = new LeaveMovement;
        $movement->forceFill([
            'user_id' => $userId,
            'leave_type_id' => $type->id,
            'year' => $year,
            'kind' => $kind,
            'amount' => $amount,
            'valid_from' => $validFrom,
            'expires_on' => $expiresOn,
            'reason' => mb_substr($reason, 0, 500),
            'created_by' => $by,
            'created_at' => CarbonImmutable::now('UTC')->startOfSecond(),
        ]);
        $movement->hash = self::seal($movement);
        $movement->save();

        return $movement;
    }

    private function log(?User $actor, LeaveMovement $movement, string $event): void
    {
        activity('leave-balances')
            ->causedBy($actor)
            ->performedOn($movement)
            ->event($event)
            ->withProperties([
                'user_id' => $movement->user_id,
                'leave_type_id' => $movement->leave_type_id,
                'year' => $movement->year,
                'kind' => $movement->kind->value,
                'amount' => $movement->amount,
                'valid_from' => $movement->valid_from->toDateString(),
                'expires_on' => $movement->expires_on?->toDateString(),
                'reason' => $movement->reason,
            ])
            ->log('leave.'.$event);
    }
}
