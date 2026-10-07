<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acceso temporal de solo lectura de la Inspección de Trabajo (Fase 11, R2; PLAN-FASE-11 §6.4 y
 * §11.3; D-353): a quién se da (nombre, email y referencia de la actuación), su ámbito (personas y
 * fechas), cuándo empieza y caduca y si se ha revocado. Se entra con un enlace (`token_hash`) y un
 * código que RR. HH. da por otra vía (`code_hash`): las dos cosas hacen falta. Solo lo crean un
 * admin o RR. HH. con el acceso de la Inspección encendido (apagado por defecto). Cada consulta
 * queda en la auditoría.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $reference
 * @property string $token_hash
 * @property string $code_hash
 * @property list<int>|null $scope_user_ids
 * @property CarbonImmutable $scope_from
 * @property CarbonImmutable $scope_to
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable $valid_until
 * @property int $created_by
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by
 * @property int $failed_attempts
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $creator
 * @property-read User|null $revoker
 */
class InspectionAccess extends Model
{
    /** Intentos de código fallidos tras los que el acceso queda bloqueado. */
    public const int MAX_ATTEMPTS = 5;

    /** Días como mucho que puede durar un acceso. */
    public const int MAX_DAYS = 30;

    protected $guarded = ['*'];

    protected $hidden = ['token_hash', 'code_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope_user_ids' => 'array',
            'scope_from' => 'date:Y-m-d',
            'scope_to' => 'date:Y-m-d',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'failed_attempts' => 'integer',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** ¿Se puede usar ahora (no revocado, dentro de su plazo y sin bloquear por intentos)? */
    public function usable(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->revoked_at === null
            && $this->failed_attempts < self::MAX_ATTEMPTS
            && $now->greaterThanOrEqualTo($this->valid_from)
            && $now->lessThan($this->valid_until);
    }

    /** Estado para la pantalla: active, scheduled, expired, revoked o locked. */
    public function state(?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();

        return match (true) {
            $this->revoked_at !== null => 'revoked',
            $this->failed_attempts >= self::MAX_ATTEMPTS => 'locked',
            $now->lessThan($this->valid_from) => 'scheduled',
            $now->greaterThanOrEqualTo($this->valid_until) => 'expired',
            default => 'active',
        };
    }
}
