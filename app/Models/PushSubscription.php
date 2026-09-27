<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suscripción Web Push de un navegador (D-072). Las claves (p256dh y auth) son del navegador y
 * sirven para cifrar el contenido del aviso: solo ese navegador puede leerlo.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 * @property string|null $user_agent
 * @property string|null $session_hash
 * @property int $failures
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'content_encoding', 'user_agent', 'session_hash', 'failures', 'last_used_at'])]
#[Hidden(['endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'session_hash'])]
class PushSubscription extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'content_encoding' => 'aes128gcm',
        'failures' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'failures' => 'integer',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
