<?php

namespace Database\Factories;

use App\Enums\BillingType;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * El gestor principal (owner) se añade siempre como miembro gestor (D-032).
 *
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'name' => 'Proyecto '.fake()->unique()->city(),
            'code' => 'P'.fake()->unique()->numberBetween(1000, 999999),
            'description' => null,
            'color' => fake()->randomElement(['#0171FF', '#179FA5', '#5E2DAD', '#E65FB3', '#3C41AE', '#0892C4']),
            'billing_type' => BillingType::TimeAndMaterials,
            'status' => ProjectStatus::Active,
            'start_date' => now()->subMonths(2)->toDateString(),
            'due_date' => null,
            'budget_minutes' => null,
            'fixed_price_amount' => null,
            'hourly_rate' => null,
            'owner_user_id' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Project $project): void {
            $project->addMember($project->owner_user_id, isManager: true);
        });
    }

    public function hourBank(): static
    {
        return $this->state(fn (array $attributes) => ['billing_type' => BillingType::HourBank]);
    }

    public function fixedPrice(): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_type' => BillingType::FixedPrice,
            'fixed_price_amount' => '4800.00',
        ]);
    }

    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'billing_type' => BillingType::Internal,
            'client_id' => null,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes) => ['status' => ProjectStatus::Archived]);
    }

    /**
     * Con estos usuarios como miembros (no gestores).
     *
     * @param  iterable<User>  $users
     */
    public function withMembers(iterable $users): static
    {
        return $this->afterCreating(function (Project $project) use ($users): void {
            foreach ($users as $user) {
                $project->addMember($user);
            }
        });
    }
}
