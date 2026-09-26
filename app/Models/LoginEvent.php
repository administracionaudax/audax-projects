<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Inicio de sesión correcto o fallido (SPEC §15). Registro inmutable: solo created_at.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $email
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property bool $succeeded
 * @property Carbon $created_at
 * @property-read User|null $user
 */
#[Fillable(['user_id', 'email', 'ip_address', 'user_agent', 'succeeded'])]
class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
