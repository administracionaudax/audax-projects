<?php

namespace Database\Factories;

use App\Models\SuggestionBoard;
use App\Models\SuggestionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SuggestionCategory>
 */
class SuggestionCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'suggestion_board_id' => SuggestionBoard::factory(),
            'name' => Str::ucfirst($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'position' => 0,
            'is_active' => true,
        ];
    }
}
