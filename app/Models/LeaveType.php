<?php

namespace App\Models;

use App\Enums\AbsenceType;
use App\Enums\LeaveUnit;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de ausencia del catálogo (Fase 11, R3; PLAN-FASE-11 §8.3; W-057; D-360 y D-361). Su
 * `category` es la de la Fase 3 (App\Enums\AbsenceType) y es lo que se guarda en `absences.type`.
 * Las cantidades van en las unidades del tipo (LeaveUnit): centésimas de día o minutos. Nunca se
 * borra (las ausencias lo citan): se desactiva. Los cambios quedan en la auditoría.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property AbsenceType $category
 * @property LeaveUnit $unit
 * @property string|null $description
 * @property string|null $legal_basis
 * @property int|null $default_amount
 * @property int|null $travel_extra
 * @property bool $paid
 * @property bool $requires_document
 * @property int|null $notice_days
 * @property bool $health_data
 * @property int|null $annual_allowance
 * @property bool $allowance_in_days
 * @property string|null $carry_over_until
 * @property bool $allow_without_balance
 * @property bool $second_approval
 * @property bool $respects_blocked_days
 * @property bool $advisor_pending
 * @property string|null $advisor_note
 * @property bool $active
 * @property int $sort
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'category', 'unit', 'description', 'legal_basis', 'default_amount', 'travel_extra', 'paid', 'requires_document', 'notice_days', 'health_data', 'annual_allowance', 'allowance_in_days', 'carry_over_until', 'allow_without_balance', 'second_approval', 'respects_blocked_days', 'advisor_pending', 'advisor_note', 'active', 'sort'])]
class LeaveType extends Model
{
    use LogsDomainActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => AbsenceType::class,
            'unit' => LeaveUnit::class,
            'default_amount' => 'integer',
            'travel_extra' => 'integer',
            'paid' => 'boolean',
            'requires_document' => 'boolean',
            'notice_days' => 'integer',
            'health_data' => 'boolean',
            'annual_allowance' => 'integer',
            'allowance_in_days' => 'boolean',
            'allow_without_balance' => 'boolean',
            'second_approval' => 'boolean',
            'respects_blocked_days' => 'boolean',
            'advisor_pending' => 'boolean',
            'active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** ¿Tiene saldo anual (vacaciones, fuerza mayor…)? */
    public function hasAllowance(): bool
    {
        return $this->annual_allowance !== null && $this->annual_allowance > 0;
    }

    /** El segundo nivel de aprobación solo se puede activar en las vacaciones (P4). */
    public function supportsSecondApproval(): bool
    {
        return $this->category === AbsenceType::Vacation;
    }

    /** ¿Pide el segundo nivel de aprobación (RR. HH.)? */
    public function needsSecondApproval(): bool
    {
        return $this->second_approval && $this->supportsSecondApproval();
    }

    /** Hasta cuándo se puede gastar la asignación del año $year: su arrastre o el 31/12. */
    public function expiryFor(int $year): string
    {
        return $this->carry_over_until !== null
            ? sprintf('%04d-%s', $year + 1, $this->carry_over_until)
            : sprintf('%04d-12-31', $year);
    }
}
