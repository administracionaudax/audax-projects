<?php

namespace Database\Factories;

use App\Models\HelpFaqSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HelpFaqSection>
 */
class HelpFaqSectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'position' => 0,
        ];
    }
}
