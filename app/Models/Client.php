<?php

namespace App\Models;

use App\Enums\PortalEntryVisibility;
use App\Enums\PortalPersonDisplay;
use App\Models\Concerns\HasFinancialAttributes;
use App\Models\Concerns\LogsDomainActivity;
use Carbon\CarbonImmutable;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cliente (SPEC §4.2). Se desactiva, no se borra (D-037).
 *
 * @property int $id
 * @property string $name
 * @property string|null $icon Emoji del cliente (F-126, Fase 10)
 * @property int|null $owner_user_id Responsable elegido a mano (D-232); null = se deduce de los proyectos
 * @property string|null $tax_id
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $phone
 * @property string|null $notes
 * @property bool $is_active
 * @property int $satisfaction_score Satisfacción actual 0-100 (F-096); el histórico, en satisfactionSnapshots
 * @property string|null $default_hourly_rate
 * @property PortalPersonDisplay $portal_person_display
 * @property PortalEntryVisibility $portal_entry_visibility
 * @property bool $portal_notify_thresholds
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read User|null $owner
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, User> $portalUsers
 * @property-read Collection<int, ClientSatisfactionSnapshot> $satisfactionSnapshots
 * @property-read ClientBillingProfile|null $billingProfile
 */
#[Fillable([
    'name',
    'icon',
    'owner_user_id',
    'tax_id',
    'contact_name',
    'contact_email',
    'phone',
    'notes',
    'is_active',
    'satisfaction_score',
    'default_hourly_rate',
    'portal_person_display',
    'portal_entry_visibility',
    'portal_notify_thresholds',
])]
class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, HasFinancialAttributes, LogsDomainActivity, SoftDeletes;

    public const array FINANCIAL_ATTRIBUTES = ['default_hourly_rate'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'satisfaction_score' => 50,
        'portal_person_display' => 'name',
        'portal_entry_visibility' => 'approved',
        'portal_notify_thresholds' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'satisfaction_score' => 'integer',
            'default_hourly_rate' => 'decimal:2',
            'portal_person_display' => PortalPersonDisplay::class,
            'portal_entry_visibility' => PortalEntryVisibility::class,
            'portal_notify_thresholds' => 'boolean',
        ];
    }

    /**
     * Responsable elegido a mano (D-232). Mejor con ClientInsights::ownerOf(), que lo deduce si no.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * Ficha fiscal (Fase 12, D-381).
     *
     * @return HasOne<ClientBillingProfile, $this>
     */
    public function billingProfile(): HasOne
    {
        return $this->hasOne(ClientBillingProfile::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasManyThrough<HourBank, Project, $this>
     */
    public function hourBanks(): HasManyThrough
    {
        return $this->hasManyThrough(HourBank::class, Project::class);
    }

    /**
     * @return HasManyThrough<TimeEntry, Project, $this>
     */
    public function timeEntries(): HasManyThrough
    {
        return $this->hasManyThrough(TimeEntry::class, Project::class);
    }

    /**
     * Contactos con acceso al portal (Fase 5).
     *
     * @return HasMany<User, $this>
     */
    public function portalUsers(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Satisfacción al cerrar cada weekly (F-094 y F-132).
     *
     * @return HasMany<ClientSatisfactionSnapshot, $this>
     */
    public function satisfactionSnapshots(): HasMany
    {
        return $this->hasMany(ClientSatisfactionSnapshot::class);
    }

    /**
     * @param  Builder<Client>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
