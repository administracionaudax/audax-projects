<?php

namespace Database\Factories;

use App\Enums\PersonalDataExportStatus;
use App\Models\PersonalDataExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonalDataExport>
 */
class PersonalDataExportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_user_id' => User::factory(),
            'requested_by' => null,
            'status' => PersonalDataExportStatus::Pending,
            'disk' => 'local',
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => PersonalDataExportStatus::Ready,
            'path' => 'exports/personal-data/'.fake()->uuid().'.zip',
            'size_bytes' => 2048,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now()->subMinute(),
            'expires_at' => now()->addDays(7),
        ]);
    }
}
