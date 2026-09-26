<?php

namespace App\Models;

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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cliente (SPEC §4.2). Se desactiva, no se borra (D-037).
 *
 * @property int $id
 * @property string $name
 * @property string|null $tax_id
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $phone
 * @property string|null $notes
 * @property bool $is_active
 * @property string|null $default_hourly_rate
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Collection<int, Project> $projects
 * @property-read Collection<int, User> $portalUsers
 */
#[Fillable([
    'name',
    'tax_id',
    'contact_name',
    'contact_email',
    'phone',
    'notes',
    'is_active',
    'default_hourly_rate',
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'default_hourly_rate' => 'decimal:2',
        ];
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
     * @param  Builder<Client>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
