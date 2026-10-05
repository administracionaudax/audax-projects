<?php

namespace App\Models;

use App\Enums\WeeklyEntrySource;
use Carbon\CarbonImmutable;
use Database\Factories\WeeklyEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apunte de una weekly por cliente (D-150), con proyecto opcional. client_id nulo = «General /
 * Interno» (F-044).
 *
 * @property int $id
 * @property int $weekly_submission_id
 * @property int|null $client_id
 * @property int|null $project_id
 * @property string $body
 * @property WeeklyEntrySource $source
 * @property int $position
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WeeklySubmission $submission
 * @property-read Client|null $client
 * @property-read Project|null $project
 */
#[Fillable(['weekly_submission_id', 'client_id', 'project_id', 'body', 'source', 'position'])]
class WeeklyEntry extends Model
{
    /** @use HasFactory<WeeklyEntryFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => 'text',
        'position' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => WeeklyEntrySource::class,
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WeeklySubmission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(WeeklySubmission::class, 'weekly_submission_id');
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
}
