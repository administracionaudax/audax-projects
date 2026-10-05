<?php

namespace App\Models;

use App\Enums\WeeklyAudioSectionKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sección de la locución del informe (F-084 a F-087): entrada, un cliente o cierre, con su guion
 * (Gemini) y su MP3 (Google TTS) en disco privado. key: "intro", "client-{id}" u "outro".
 *
 * @property int $id
 * @property int $weekly_cycle_id
 * @property string $key
 * @property WeeklyAudioSectionKind $kind
 * @property int|null $client_id
 * @property int $position
 * @property string|null $script
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $mime
 * @property int|null $size
 * @property int|null $duration_ms
 * @property string|null $voice
 * @property CarbonImmutable|null $generated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WeeklyCycle $cycle
 * @property-read Client|null $client
 */
#[Fillable(['weekly_cycle_id', 'key', 'kind', 'client_id', 'position', 'script', 'disk', 'path', 'mime', 'size', 'duration_ms', 'voice', 'generated_at'])]
class WeeklyAudioSection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => WeeklyAudioSectionKind::class,
            'position' => 'integer',
            'size' => 'integer',
            'duration_ms' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WeeklyCycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(WeeklyCycle::class, 'weekly_cycle_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }
}
