<?php

namespace App\Models;

use App\Enums\BillingType;
use App\Enums\ProjectStatus;
use App\Models\Concerns\HasFinancialAttributes;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Proyecto (SPEC §4.2). Todos los internos lo ven (D-021); imputan sus miembros, o cualquier
 * interno si es un proyecto interno (D-033). owner_user_id es el gestor principal (D-032).
 *
 * @property int $id
 * @property int|null $client_id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property string $color
 * @property BillingType $billing_type
 * @property ProjectStatus $status
 * @property CarbonImmutable|null $start_date
 * @property CarbonImmutable|null $due_date
 * @property int|null $budget_minutes
 * @property string|null $fixed_price_amount
 * @property string|null $hourly_rate
 * @property int $owner_user_id
 * @property bool $portal_project_visible
 * @property bool $portal_show_task_hours
 * @property bool $portal_gantt_visible
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Client|null $client
 * @property-read User $owner
 * @property-read ProjectMember|null $membership
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, User> $managers
 * @property-read Collection<int, HourBank> $hourBanks
 * @property-read Collection<int, Task> $tasks
 * @property-read Collection<int, Allocation> $allocations
 * @property-read ForecastProject|null $forecastProject
 */
#[Fillable([
    'client_id',
    'name',
    'code',
    'description',
    'color',
    'billing_type',
    'status',
    'start_date',
    'due_date',
    'budget_minutes',
    'fixed_price_amount',
    'hourly_rate',
    'owner_user_id',
    'portal_project_visible',
    'portal_show_task_hours',
    'portal_gantt_visible',
])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasFinancialAttributes, LogsDomainActivity, SoftDeletes;

    public const array FINANCIAL_ATTRIBUTES = ['fixed_price_amount', 'hourly_rate'];

    /**
     * Proyecto interno por defecto que crea app:install (SPEC §7).
     */
    public const string INTERNAL_CODE = 'INTERNO';

    public const array INTERNAL_TASKS = ['Reuniones', 'Formación', 'Gestión', 'Comercial'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'portal_project_visible' => false,
        'portal_show_task_hours' => false,
        'portal_gantt_visible' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_type' => BillingType::class,
            'status' => ProjectStatus::class,
            'start_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'budget_minutes' => 'integer',
            'fixed_price_amount' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'portal_project_visible' => 'boolean',
            'portal_show_task_hours' => 'boolean',
            'portal_gantt_visible' => 'boolean',
        ];
    }

    /**
     * El código siempre en mayúsculas y sin espacios (ACME-WEB).
     *
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => mb_strtoupper(preg_replace('/\s+/u', '-', trim($value)) ?? $value),
        );
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
     * @return BelongsToMany<User, $this, ProjectMember, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->using(ProjectMember::class)
            ->as('membership')
            ->withPivot(['is_manager', 'alert_preferences'])
            ->withTimestamps();
    }

    /**
     * Gestores: principal y co-gestores (D-005, D-032).
     *
     * @return BelongsToMany<User, $this, ProjectMember, 'membership'>
     */
    public function managers(): BelongsToMany
    {
        return $this->members()->wherePivot('is_manager', true);
    }

    /**
     * @return HasMany<HourBank, $this>
     */
    public function hourBanks(): HasMany
    {
        return $this->hasMany(HourBank::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /**
     * Asignaciones de horas del proyecto (pestaña Planificación, D-282).
     *
     * @return HasMany<Allocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class)->orderBy('start_date')->orderBy('id');
    }

    /**
     * El proyecto previsto del que viene, si se vinculó (como mucho uno, D-286).
     *
     * @return HasOne<ForecastProject, $this>
     */
    public function forecastProject(): HasOne
    {
        return $this->hasOne(ForecastProject::class);
    }

    public function isInternal(): bool
    {
        return $this->billing_type === BillingType::Internal;
    }

    public function usesHourBanks(): bool
    {
        return $this->billing_type === BillingType::HourBank;
    }

    public function acceptsTime(): bool
    {
        return $this->status->acceptsTime();
    }

    public function hasMember(User|int $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        return $this->members()->whereKey($id)->exists();
    }

    public function isManagedBy(User|int $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        return $this->managers()->whereKey($id)->exists();
    }

    /**
     * Añade (o actualiza) un miembro. El gestor principal siempre es gestor (D-032).
     *
     * @param  array<string, bool>|null  $alertPreferences
     */
    public function addMember(User|int $user, bool $isManager = false, ?array $alertPreferences = null): void
    {
        $id = $user instanceof User ? $user->id : $user;

        $attributes = ['is_manager' => $isManager || $id === $this->owner_user_id];

        // Sin preferencias explícitas se conservan las que tuviera (o las de por defecto).
        if ($alertPreferences !== null) {
            $attributes['alert_preferences'] = $alertPreferences;
        }

        $this->members()->syncWithoutDetaching([$id => $attributes]);
    }

    /**
     * @param  Builder<Project>  $query
     */
    #[Scope]
    protected function notArchived(Builder $query): void
    {
        $query->where('status', '!=', ProjectStatus::Archived->value);
    }

    /**
     * Proyectos que $viewer puede ver (D-134): todos, salvo para un colaborador externo, que solo
     * ve aquellos de los que es miembro.
     *
     * @param  Builder<Project>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $viewer): void
    {
        $ids = $viewer->visibleProjectIds();

        if ($ids !== null) {
            $query->whereIn('projects.id', $ids);
        }
    }

    /**
     * Proyectos donde el usuario es miembro.
     *
     * @param  Builder<Project>  $query
     */
    #[Scope]
    protected function withMember(Builder $query, User|int $user): void
    {
        $id = $user instanceof User ? $user->id : $user;

        $query->whereHas('members', fn (Builder $members) => $members->whereKey($id));
    }
}
