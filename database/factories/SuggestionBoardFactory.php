<?php

namespace Database\Factories;

use App\Models\SuggestionBoard;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SuggestionBoard>
 */
class SuggestionBoardFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => Str::ucfirst($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'position' => 0,
            'is_active' => true,
        ];
    }
}
