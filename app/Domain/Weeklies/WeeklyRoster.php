<?php

namespace App\Domain\Weeklies;

use App\Enums\WeeklyExemptionReason;

/**
 * Quién participa en una semana y quién está exento (lo da WeeklyEligibility).
 * - participants(): quienes cuentan esa semana (deben enviar o están exentos),
 * - expected(): quienes deben enviar (participantes sin los exentos),
 * - exemptions(): exentos con su motivo (absence o manual) y, si es por ausencia, su id.
 */
final readonly class WeeklyRoster
{
    /**
     * @param  list<int>  $participants
     * @param  array<int, WeeklyExemptionReason>  $exemptions  persona → motivo (solo los que eximen)
     * @param  array<int, int>  $absenceIds  persona → ausencia que la exime
     */
    public function __construct(
        private array $participants,
        private array $exemptions,
        private array $absenceIds = [],
    ) {}

    /**
     * @return list<int>
     */
    public function participants(): array
    {
        return $this->participants;
    }

    /**
     * @return list<int>
     */
    public function expected(): array
    {
        return array_values(array_filter($this->participants, fn (int $id): bool => ! isset($this->exemptions[$id])));
    }

    /**
     * @return array<int, WeeklyExemptionReason>
     */
    public function exemptions(): array
    {
        return $this->exemptions;
    }

    public function participates(int $userId): bool
    {
        return in_array($userId, $this->participants, true);
    }

    public function isExempt(int $userId): bool
    {
        return isset($this->exemptions[$userId]);
    }

    /** ¿Debe enviar? Participa y no está exenta. */
    public function mustSubmit(int $userId): bool
    {
        return $this->participates($userId) && ! $this->isExempt($userId);
    }

    public function reasonFor(int $userId): ?WeeklyExemptionReason
    {
        return $this->exemptions[$userId] ?? null;
    }

    public function absenceFor(int $userId): ?int
    {
        return $this->absenceIds[$userId] ?? null;
    }
}
