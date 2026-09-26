<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property int|null $department_id
 * @property string|null $hourly_cost
 * @property string|null $default_hourly_rate
 * @property bool $is_active
 * @property string $theme_preference
 * @property string $locale
 * @property array<string, mixed>|null $notification_preferences
 * @property string|null $avatar_path
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $avatar_url
 * @property-read Department|null $department
 */
#[Fillable([
    'name',
    'email',
    'password',
    'department_id',
    'hourly_cost',
    'default_hourly_rate',
    'is_active',
    'theme_preference',
    'locale',
    'notification_preferences',
    'avatar_path',
])]
#[Hidden([
    'password',
    'two_factor_secret',
    'two_factor_recovery_codes',
    'remember_token',
    // Datos económicos: solo con el permiso view-financials (SPEC §5). Ver revealFinancialsTo().
    'hourly_cost',
    'default_hourly_rate',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    public const array THEMES = ['light', 'dark', 'system'];

    /**
     * Atributos económicos que solo ve quien tiene view-financials.
     */
    public const array FINANCIAL_ATTRIBUTES = ['hourly_cost', 'default_hourly_rate'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'theme_preference' => 'system',
        'locale' => 'es',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'hourly_cost' => 'decimal:2',
            'default_hourly_rate' => 'decimal:2',
            'is_active' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Departamentos de los que es responsable (D-024).
     *
     * @return BelongsToMany<Department, $this>
     */
    public function managedDepartments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_managers')->withTimestamps();
    }

    /**
     * ¿Es responsable de ese departamento?
     */
    public function managesDepartment(Department|int|null $department): bool
    {
        if ($department === null) {
            return false;
        }

        $id = $department instanceof Department ? $department->id : $department;

        return $this->managedDepartments()->whereKey($id)->exists();
    }

    /**
     * Los clientes solo acceden al portal (SPEC §5 y §11).
     */
    public function isClient(): bool
    {
        return $this->hasRole(Role::Client->value);
    }

    public function isInternal(): bool
    {
        return ! $this->isClient();
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::Admin->value);
    }

    /**
     * Muestra los datos económicos en la serialización solo si $viewer puede verlos.
     */
    public function revealFinancialsTo(?User $viewer): static
    {
        if ($viewer !== null && Gate::forUser($viewer)->allows('view-financials')) {
            $this->makeVisible(self::FINANCIAL_ATTRIBUTES);
        }

        return $this;
    }

    /**
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Usuarios internos (todos salvo los de rol cliente).
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function internal(Builder $query): void
    {
        $query->whereDoesntHave('roles', fn (Builder $roles) => $roles->where('name', Role::Client->value));
    }

    /**
     * URL del avatar mediante ruta firmada (los ficheros nunca son públicos, SPEC §15), o null.
     * La ruta avatars.show llega cuando se implemente la subida de avatares.
     *
     * @return Attribute<string|null, never>
     */
    protected function avatarUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->avatar_path === null || ! Route::has('avatars.show')) {
                return null;
            }

            return URL::temporarySignedRoute('avatars.show', now()->addHour(), ['user' => $this->id]);
        });
    }
}
