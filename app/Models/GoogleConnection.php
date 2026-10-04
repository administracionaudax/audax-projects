<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuenta de Google de Workspace conectada por una persona (Fase 9, D-142), con el alcance mínimo
 * `drive.file`: la app solo toca los archivos que ella misma crea en su Drive. Los tokens van
 * cifrados (cast `encrypted`) y nunca salen en JSON ni en la exportación de datos personales. La
 * fila se borra al desconectar o si Google retira el acceso (invalid_grant).
 *
 * @property int $id
 * @property int $user_id
 * @property string $google_email
 * @property string $refresh_token
 * @property string|null $access_token
 * @property CarbonImmutable|null $expires_at
 * @property string|null $scopes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'google_email', 'refresh_token', 'access_token', 'expires_at', 'scopes'])]
#[Hidden(['refresh_token', 'access_token'])]
class GoogleConnection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'access_token' => 'encrypted',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * ¿Hay que renovar el token de acceso? Con un minuto de margen para la subida.
     */
    public function accessTokenExpired(): bool
    {
        return $this->access_token === null || $this->expires_at === null || $this->expires_at->subMinute()->isPast();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
