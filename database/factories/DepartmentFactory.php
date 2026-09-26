<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Diseño', 'Desarrollo', 'Marketing', 'Cuentas', 'Producción']),
            'color' => fake()->randomElement(['#0171FF', '#179FA5', '#5E2DAD']),
        ];
    }
}
