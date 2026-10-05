<?php

namespace App\Domain\Weeklies;

use App\Enums\AppModule;
use App\Enums\WeeklyExemptionReason;
use App\Enums\WeeklyPersonStatus;
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
 * El contador (pendingCount) se guarda 5 minutos por persona y semana activa (D-160):
 * - la clave lleva el plazo y el updated_at de la semana: ampliar el plazo lo renueva para todos,
 * - se olvida al enviar, al cambiar una exención (forget) y al guardar o borrar una ausencia de esa
 *   persona (forgetActive, desde WeekliesServiceProvider),
 * - lo demás (alta, baja o cambio de rol) se nota, como mucho, a los 5 minutos.
 */
final class MyWeeklyStatus
{
    public const int CACHE_SECONDS = 300;

    public function __construct(
        private readonly WeeklyEligibility $eligibility,
        private readonly WeeklyTiming $timing = new WeeklyTiming,
    ) {}

    /**
     * @return array{
     *     status: string, participates: bool, must_submit: bool, exemption_reason: string|null,
     *     exemption_id: int|null, waived: bool, submission_id: int|null, submitted_at: string|null,
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
            'waived' => $row?->reason === WeeklyExemptionReason::Waived,
            'submission_id' => $submission?->id,
            'submitted_at' => $submittedAt?->toIso8601String(),
            'resubmitted_at' => $submission?->resubmitted_at?->toIso8601String(),
            'draft_saved_at' => $submission?->draft_saved_at?->toIso8601String(),
            'entries_count' => (int) ($submission->entries_count ?? 0),
        ];
    }

    /**
     * Weeklies pendientes de enviar (0 o 1) para el contador rojo de «Mi espacio» (F-003): la semana
     * activa, si debo enviarla y aún no lo he hecho.
     */
    public function pendingCount(User $user): int
    {
        if (! $user->writesWeeklies() || $user->isCollaborator() || ! AppModules::enabled(AppModule::Weeklies)) {
            return 0;
        }

        $cycle = WeeklyCycle::query()->active()->first();

        if ($cycle === null) {
            return 0;
        }

        return (int) Cache::remember(self::cacheKey($user->id, $cycle), self::CACHE_SECONDS, function () use ($user, $cycle): int {
            if (! $this->eligibility->rosterForUser($cycle, $user)->mustSubmit($user->id)) {
                return 0;
            }

            $sent = WeeklySubmission::query()
                ->where('weekly_cycle_id', $cycle->id)
                ->where('user_id', $user->id)
                ->whereNotNull('submitted_at')
                ->exists();

            return $sent ? 0 : 1;
        });
    }

    /** Olvida el contador de una persona en una semana (al enviar o al cambiar su exención). */
    public static function forget(int $userId, WeeklyCycle $cycle): void
    {
        Cache::forget(self::cacheKey($userId, $cycle));
    }

    /** Olvida el contador de una persona en la semana activa, si la hay (al cambiar sus ausencias). */
    public static function forgetActive(int $userId): void
    {
        $cycle = WeeklyCycle::query()->active()->first(['id', 'deadline_date', 'updated_at']);

        if ($cycle !== null) {
            self::forget($userId, $cycle);
        }
    }

    /** ¿Está pendiente (o con retraso) en esta semana? */
    public static function isPending(string $status): bool
    {
        return in_array($status, [WeeklyPersonStatus::Pending->value, WeeklyPersonStatus::Overdue->value, WeeklyPersonStatus::Upcoming->value], true);
    }

    private static function cacheKey(int $userId, WeeklyCycle $cycle): string
    {
        return "weekly-pending:{$cycle->id}:{$cycle->deadline_date->toDateString()}:".($cycle->updated_at?->getTimestamp() ?? 0).":{$userId}";
    }
}
