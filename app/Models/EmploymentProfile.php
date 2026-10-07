<?php

namespace App\Models;

use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Datos laborales de una persona (Fase 11, D-331 y D-343; W-124 y W-125): fecha de alta y de baja y
 * si está sujeta al registro de jornada (lo está salvo que RR. HH. diga lo contrario con un motivo,
 * por ejemplo un socio que no es asalariado). Sin fila, cuenta como sujeta y sin fechas. R2 y R3
 * añaden la retención por litigio, el contrato y el calendario.
 *
 * @property int $id
 * @property int $user_id
 * @property CarbonImmutable|null $hire_date
 * @property CarbonImmutable|null $termination_date
 * @property bool $subject_to_register
 * @property string|null $register_exemption_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'hire_date', 'termination_date', 'subject_to_register', 'register_exemption_reason'])]
class EmploymentProfile extends Model
{
    use LogsDomainActivity;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'subject_to_register' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hire_date' => 'date:Y-m-d',
            'termination_date' => 'date:Y-m-d',
            'subject_to_register' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** ¿Estaba de alta ese día? Sin fechas, sí. */
    public function employedOn(CarbonInterface|string $date): bool
    {
        $day = is_string($date) ? $date : $date->toDateString();

        return ($this->hire_date === null || $this->hire_date->toDateString() <= $day)
            && ($this->termination_date === null || $this->termination_date->toDateString() >= $day);
    }
}
