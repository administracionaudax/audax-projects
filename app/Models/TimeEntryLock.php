<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bloqueo de horas hecho por un admin por cliente o proyecto y rango de fechas (SPEC §7, D-034).
 *
 * @property int $id
 * @property int|null $client_id
 * @property int|null $project_id
 * @property CarbonImmutable $date_from
 * @property CarbonImmutable $date_to
 * @property int $locked_by
 * @property int $entries_count
 * @property string|null $reference
 * @property CarbonImmutable|null $unlocked_at
 * @property int|null $unlocked_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client|null $client
 * @property-read Project|null $project
 * @property-read User $locker
 */
#[Fillable(['client_id', 'project_id', 'date_from', 'date_to', 'locked_by', 'entries_count', 'reference', 'unlocked_at', 'unlocked_by'])]
class TimeEntryLock extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
            'entries_count' => 'integer',
            'unlocked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }
}
