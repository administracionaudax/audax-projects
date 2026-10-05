<?php

namespace Database\Factories;

use App\Enums\SuggestionStatus;
use App\Models\SuggestionBoard;
use App\Models\SuggestionPost;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SuggestionPost>
 */
class SuggestionPostFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->sentence(4);

        return [
            'suggestion_board_id' => SuggestionBoard::factory(),
            'suggestion_category_id' => null,
            'author_id' => User::factory()->employee(),
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'body' => fake()->paragraph(),
            'status' => SuggestionStatus::Open,
            'last_activity_at' => now(),
        ];
    }
}
