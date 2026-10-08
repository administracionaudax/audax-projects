<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una ejecución de la sincronización con Holded (Fase 12, D-387): quién o qué la lanzó, cómo acabó
 * y sus recuentos. El error se guarda sin la clave (HoldedRequestFailed nunca la incluye).
 *
 * @property int $id
 * @property string $trigger schedule | manual | command | seeder
 * @property int|null $user_id
 * @property string $status running | ok | failed
 * @property array<string, int>|null $stats
 * @property string|null $error
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property-read User|null $user
 */
#[Fillable(['trigger', 'user_id', 'status', 'stats', 'error', 'started_at', 'finished_at'])]
class HoldedSyncRun extends Model
{
    public const string RUNNING = 'running';

    public const string OK = 'ok';

    public const string FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
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
