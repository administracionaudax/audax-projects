<?php

namespace App\Domain\Weeklies;

use App\Enums\AppModule;
use App\Enums\WeeklyExemptionReason;
use App\Enums\WeeklyPersonStatus;
use App\Models\Absence;
use App\Models\User;
use App\Models\WeeklyCycle;
use App\Models\WeeklyExemption;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Mi weekly de una semana (F-003, F-030, F-032, F-042, F-053 y F-054): mi estado, si debo enviar, si
 * estoy exento (y si puedo quitarme la exención) y mi envío. Lo usan «Mi espacio», la tarjeta de
 * Inicio y el contador de la barra lateral.
 *
 * El contador (pendingCount) no hace consultas con la caché caliente (va en todas las páginas):
 * - la semana activa se guarda aparte (activeSnapshot) y se olvida al guardar o borrar una semana,
 * - quién tiene pendiente la semana activa es UNA lista compartida por toda la plantilla (D-187),
 *   guardada 5 minutos: la calcula la primera página que la necesita (cinco consultas) y las demás
 *   personas no pagan nada, ni siquiera con su caché personal fría (D-160 la guardaba por persona),
 * - la clave lleva el plazo y el updated_at de la semana: ampliar el plazo la renueva,
 * - se olvida al enviar, al cambiar una exención (forget) y al guardar o borrar una ausencia
 *   (forgetActive, desde WeekliesServiceProvider),
 * - lo demás (alta, baja o cambio de rol) se nota, como mucho, a los 5 minutos.
 */
final class MyWeeklyStatus
{
    public const int CACHE_SECONDS = 300;

    public const string ACTIVE_KEY = 'weeklies:active-cycle';

    public const int ACTIVE_SECONDS = 600;

    public function __construct(
        private readonly WeeklyEligibility $eligibility,
        private readonly WeeklyTiming $timing = new WeeklyTiming,
    ) {}

    /**
     * @return array{
     *     status: string, participates: bool, must_submit: bool, exemption_reason: string|null,
     *     exemption_id: int|null, exemption_until: string|null, waived: bool, submission_id: int|null, submitted_at: string|null,
     *     resubmitted_at: string|null, draft_saved_at: string|null, entries_count: int
     * }
     */
    public function for(User $user, WeeklyCycle $cycle, ?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $roster = $this->eligibility->rosterForUser($cycle, $user);
        $submission = WeeklySubmission::query()
            ->withCount('entries')
            ->where('weekly_cycle_id', $cycle->id)
            ->where('user_id', $user->id)
            ->first();
        $row = WeeklyExemption::query()
            ->where('weekly_cycle_id', $cycle->id)
            ->where('user_id', $user->id)
            ->first(['id', 'reason']);

        $exempt = $roster->isExempt($user->id);
        $submittedAt = $submission?->submitted_at === null ? null : CarbonImmutable::instance($submission->submitted_at);
        $status = $this->timing->personStatus($cycle->status, $cycle->deadline_date, $submittedAt, $exempt, $roster->participates($user->id), $now);
        $reason = $roster->reasonFor($user->id);

        return [
            'status' => $status->value,
            'participates' => $roster->participates($user->id),
            'must_submit' => $roster->mustSubmit($user->id),
            'exemption_reason' => $reason === WeeklyExemptionReason::Waived ? null : $reason?->value,
            // Fila propia que se puede quitar (manual o renuncia); la de ausencia de una semana
            // activa no tiene fila: se renuncia con waive.
            'exemption_id' => $row !== null && $row->reason !== WeeklyExemptionReason::Absence ? $row->id : null,
            // Hasta cuándo dura la exención (el aviso del editor, como WeeklySync): la vuelta de la
            // ausencia o de «Estoy fuera»; null si es manual o no tiene fecha.
            'exemption_until' => ! $cycle->isActive() ? null : match ($reason) {
                WeeklyExemptionReason::Absence => ($absenceId = $roster->absenceFor($user->id)) !== null
                    ? Absence::query()->whereKey($absenceId)->first(['id', 'end_date'])?->end_date->toDateString()
                    : null,
                WeeklyExemptionReason::Away => $user->weekly_away_until?->toDateString(),
                default => null,
            },
            'waived' => $row?->reason === WeeklyExemptionReason::Waived,
            'submission_id' => $submission?->id,
            'submitted_at' => $submittedAt?->toIso8601String(),
            'resubmitted_at' => $submission?->resubmitted_at?->toIso8601String(),
            'draft_saved_at' => $submission?->draft_saved_at?->toIso8601String(),
            'entries_count' => (int) ($submission->entries_count ?? 0),
        ];
    }

    /**
     * Weeklies pendientes de enviar (0 o 1) para el contador de «Mi espacio» (F-003): la semana
     * activa, si debo enviarla y aún no lo he hecho. Con la lista compartida en caché, sin consultas.
     */
    public function pendingCount(User $user): int
    {
        if (! $user->writesWeeklies() || $user->isCollaborator() || ! AppModules::enabled(AppModule::Weeklies)) {
            return 0;
        }

        $active = self::activeSnapshot();

        if ($active === null) {
            return 0;
        }

        /** @var list<int> $pending */
        $pending = Cache::remember(self::cacheKey($active), self::CACHE_SECONDS, fn (): array => $this->pendingIds($active['id']));

        return in_array($user->id, $pending, true) ? 1 : 0;
    }

    /**
     * Quién debe enviar la semana activa y aún no lo ha hecho (la lista compartida del contador).
     *
     * @return list<int>
     */
    public function pendingIds(int $cycleId): array
    {
        $cycle = WeeklyCycle::query()->find($cycleId);

        if ($cycle === null || ! $cycle->isActive()) {
            return [];
        }

        $sent = WeeklySubmission::query()
            ->where('weekly_cycle_id', $cycle->id)
            ->whereNotNull('submitted_at')
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_diff($this->eligibility->rosterFor($cycle)->expected(), $sent));
    }

    /**
     * La semana activa (id, plazo y última modificación) en caché, para no consultarla en cada
     * página. Se olvida al guardar o borrar una semana (WeekliesServiceProvider) y caduca sola a los
     * 10 minutos por si alguien la toca sin Eloquent.
     *
     * @return array{id: int, deadline: string, updated: int}|null
     */
    public static function activeSnapshot(): ?array
    {
        /** @var array{id: int|null, deadline?: string, updated?: int} $snapshot */
        $snapshot = Cache::remember(self::ACTIVE_KEY, self::ACTIVE_SECONDS, function (): array {
            $cycle = WeeklyCycle::query()->active()->first(['id', 'deadline_date', 'updated_at']);

            return $cycle === null ? ['id' => null] : self::snapshotOf($cycle);
        });

        return $snapshot['id'] === null ? null : [
            'id' => (int) $snapshot['id'],
            'deadline' => (string) ($snapshot['deadline'] ?? ''),
            'updated' => (int) ($snapshot['updated'] ?? 0),
        ];
    }

    public static function forgetActiveSnapshot(): void
    {
        Cache::forget(self::ACTIVE_KEY);
    }

    /**
     * Olvida la lista de pendientes de una semana (al enviar o al cambiar una exención). $userId se
     * mantiene por compatibilidad: la lista es de toda la plantilla (D-187).
     */
    public static function forget(int $userId, WeeklyCycle $cycle): void
    {
        Cache::forget(self::cacheKey(self::snapshotOf($cycle)));

        // La semana en caché puede llevar un updated_at anterior (p. ej. tras guardar el informe).
        $active = self::activeSnapshot();
        if ($active !== null && $active['id'] === $cycle->id) {
            Cache::forget(self::cacheKey($active));
        }
    }

    /** Olvida la lista de pendientes de la semana activa, si la hay (al cambiar unas ausencias). */
    public static function forgetActive(int $userId): void
    {
        $active = self::activeSnapshot();

        if ($active !== null) {
            Cache::forget(self::cacheKey($active));
        }
    }

    /** ¿Está pendiente (o con retraso) en esta semana? */
    public static function isPending(string $status): bool
    {
        return in_array($status, [WeeklyPersonStatus::Pending->value, WeeklyPersonStatus::Overdue->value, WeeklyPersonStatus::Upcoming->value], true);
    }

    /**
     * @return array{id: int, deadline: string, updated: int}
     */
    private static function snapshotOf(WeeklyCycle $cycle): array
    {
        return [
            'id' => $cycle->id,
            'deadline' => $cycle->deadline_date->toDateString(),
            'updated' => $cycle->updated_at?->getTimestamp() ?? 0,
        ];
    }

    /**
     * @param  array{id: int, deadline: string, updated: int}  $cycle
     */
    private static function cacheKey(array $cycle): string
    {
        return "weekly-pending:{$cycle['id']}:{$cycle['deadline']}:{$cycle['updated']}";
    }
}
