<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Client;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as RoleModel;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'is_active' => true,
            'theme_preference' => 'system',
            'locale' => 'es',
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Usuario desactivado (SPEC §14: los usuarios se desactivan, no se borran).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            // Clave base32 válida: con 'secret' la verificación de un código lanzaba una excepción.
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function inDepartment(?Department $department = null): static
    {
        return $this->state(fn (array $attributes) => [
            'department_id' => $department ?? Department::factory(),
        ]);
    }

    /**
     * Asigna un rol global. Crea el rol si no existe para que los tests no dependan de los seeders.
     */
    public function withRole(Role $role): static
    {
        return $this->afterCreating(function (User $user) use ($role): void {
            RoleModel::findOrCreate($role->value, 'web');
            $user->assignRole($role->value);
        });
    }

    public function admin(): static
    {
        return $this->withRole(Role::Admin);
    }

    public function departmentManager(): static
    {
        return $this->withRole(Role::DepartmentManager);
    }

    public function employee(): static
    {
        return $this->withRole(Role::Employee);
    }

    public function client(): static
    {
        return $this->withRole(Role::Client);
    }

    /**
     * Usuario del portal de ese cliente (Fase 5).
     */
    public function portalOf(Client $client): static
    {
        return $this->client()->state(['client_id' => $client->id]);
    }
}
