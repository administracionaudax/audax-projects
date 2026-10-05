<?php

namespace App\Domain\Weeklies\Report;

/**
 * Estado de un proyecto del cliente al generar el informe (F-074 y D-148): presupuesto, consumido y
 * esperado en MINUTOS, con los datos reales de Audax (HourBankLedger y horas). Se guarda en el JSON
 * para que un informe pasado muestre lo que había entonces. billingType es el tipo de la vista
 * (WeeklyProjectStatus::KIND_*: hour_bank, monthly_fee, fixed_price o time_and_materials) y
 * weekMinutes, las horas de la semana del informe (10.3, D-188).
 */
final readonly class WeeklyProjectSnapshot
{
    public function __construct(
        public int $projectId,
        public string $code,
        public string $name,
        public string $billingType,
        public ?int $budgetMinutes,
        public int $consumedMinutes,
        public ?int $expectedMinutes,
        public int $weekMinutes = 0,
    ) {}

    /** Consumido menos esperado (positivo = por encima de lo esperado); null sin esperado. */
    public function deviationMinutes(): ?int
    {
        return $this->expectedMinutes === null ? null : $this->consumedMinutes - $this->expectedMinutes;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $int = fn (string $key): ?int => is_numeric($data[$key] ?? null) ? (int) $data[$key] : null;

        return new self(
            projectId: $int('project_id') ?? 0,
            code: (string) ($data['code'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            billingType: (string) ($data['billing_type'] ?? ''),
            budgetMinutes: $int('budget_minutes'),
            consumedMinutes: $int('consumed_minutes') ?? 0,
            expectedMinutes: $int('expected_minutes'),
            weekMinutes: $int('week_minutes') ?? 0,
        );
    }

    /**
     * @return array{project_id: int, code: string, name: string, billing_type: string, budget_minutes: int|null, consumed_minutes: int, expected_minutes: int|null, deviation_minutes: int|null, week_minutes: int}
     */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'code' => $this->code,
            'name' => $this->name,
            'billing_type' => $this->billingType,
            'budget_minutes' => $this->budgetMinutes,
            'consumed_minutes' => $this->consumedMinutes,
            'expected_minutes' => $this->expectedMinutes,
            'deviation_minutes' => $this->deviationMinutes(),
            'week_minutes' => $this->weekMinutes,
        ];
    }
}
