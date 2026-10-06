<?php

namespace App\Models;

use App\Enums\ForecastConfidence;
use App\Enums\ForecastStatus;
use App\Enums\LoadLayer;
use App\Models\Concerns\HasFinancialAttributes;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Database\Factories\ForecastProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Proyecto previsto (docs/PLAN-CARGAS.md §5 y §7.2, D-280 y D-281): un contenedor ligero de
 * asignaciones para algo que aún no es un proyecto real, con cliente existente o nombre libre.
 * Nunca lleva tareas ni horas: la ejecución va siempre en el proyecto real. Al vincularse, sus
 * asignaciones quedan congeladas y `baseline` guarda la foto de lo estimado (D-286).
 *
 * @property int $id
 * @property string $name
 * @property int|null $client_id
 * @property string|null $prospect_name
 * @property string $color
 * @property string|null $description
 * @property int $owner_user_id
 * @property ForecastConfidence $confidence
 * @property ForecastStatus $status
 * @property string|null $lost_reason
 * @property CarbonImmutable|null $lost_at
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $end_date
 * @property int|null $estimated_minutes
 * @property string|null $estimated_amount
 * @property int|null $project_id
 * @property CarbonImmutable|null $linked_at
 * @property int|null $linked_by
 * @property array<string, mixed>|null $baseline
 * @property int|null $created_by
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Client|null $client
 * @property-read User $owner
 * @property-read Project|null $project
 * @property-read User|null $linker
 * @property-read Collection<int, Allocation> $allocations
 */
#[Fillable([
    'name',
    'client_id',
    'prospect_name',
    'color',
    'description',
    'owner_user_id',
    'confidence',
    'status',
    'lost_reason',
    'lost_at',
    'start_date',
    'end_date',
    'estimated_minutes',
    'estimated_amount',
    'project_id',
    'linked_at',
    'linked_by',
    'baseline',
    'created_by',
])]
class ForecastProject extends Model
{
    /** @use HasFactory<ForecastProjectFactory> */
    use HasFactory, HasFinancialAttributes, LogsDomainActivity, SoftDeletes;

    public const array FINANCIAL_ATTRIBUTES = ['estimated_amount'];

    public const int NAME_MAX = 160;

    public const int REASON_MAX = 200;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'confidence' => 'tentative',
        'status' => 'open',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence' => ForecastConfidence::class,
            'status' => ForecastStatus::class,
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'lost_at' => 'datetime',
            'linked_at' => 'datetime',
            'estimated_minutes' => 'integer',
            'estimated_amount' => 'decimal:2',
            'baseline' => 'array',
        ];
    }

    /**
     * La foto de la línea base es grande y no se audita campo a campo (se audita el vínculo).
     *
     * @return list<string>
     */
    protected static function activityExcept(): array
    {
        return ['baseline'];
    }

    /**
     * La capa de la carga de sus asignaciones, o null si no cuentan (perdido o vinculado).
     */
    public function layer(): ?LoadLayer
    {
        return $this->status->counts() ? $this->confidence->layer() : null;
    }

    /** Nombre del cliente: el del cliente existente o el nombre libre. */
    public function clientName(): ?string
    {
        return $this->client->name ?? $this->prospect_name;
    }

    /**
     * Previstos que cuentan en la carga (abiertos o confirmados).
     *
     * @param  Builder<ForecastProject>  $query
     */
    #[Scope]
    protected function counting(Builder $query): void
    {
        $query->whereIn('status', [ForecastStatus::Open->value, ForecastStatus::Confirmed->value]);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
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
    public function linker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }

    /**
     * @return HasMany<Allocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class)->orderBy('start_date')->orderBy('id');
    }
}
