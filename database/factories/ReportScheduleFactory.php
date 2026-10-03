<?php

namespace Database\Factories;

use App\Domain\Reports\Delivery\RelativePeriod;
use App\Domain\Reports\Delivery\ReportKind;
use App\Domain\Reports\Delivery\ScheduleFrequency;
use App\Models\ReportSchedule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Envío programado (D-141): por defecto, el informe detallado del mes anterior, el día 1 de cada
 * mes a las 08:00, a un correo externo.
 *
 * @extends Factory<ReportSchedule>
 */
class ReportScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_user_id' => User::factory()->admin(),
            'title' => 'Informe detallado',
            'request' => ['kind' => ReportKind::Detail->value, 'route_params' => [], 'query' => ['periodo' => 'mes']],
            'formats' => ['pdf'],
            'relative_period' => RelativePeriod::Previous,
            'recipient_user_ids' => [],
            'recipient_emails' => ['cliente@example.com'],
            'subject' => null,
            'message' => null,
            'frequency' => ScheduleFrequency::Monthly,
            'run_at' => null,
            'weekday' => null,
            'month_day' => 1,
            'time' => '08:00',
            'is_active' => true,
            'paused_reason' => null,
            'next_run_at' => CarbonImmutable::now()->subMinute(),
            'last_run_at' => null,
        ];
    }

    /**
     * Ya le toca enviarse.
     */
    public function due(): static
    {
        return $this->state(fn (): array => ['next_run_at' => CarbonImmutable::now()->subMinute()]);
    }

    public function future(): static
    {
        return $this->state(fn (): array => ['next_run_at' => CarbonImmutable::now()->addDay()]);
    }
}
