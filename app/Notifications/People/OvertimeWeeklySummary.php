<?php

namespace App\Notifications\People;

use App\Domain\People\Reports\PeopleFormat;
use App\Notifications\AppNotification;

/**
 * Resumen semanal de tus horas extra (art. 35.5 ET y convenio de publicidad: totalización semanal
 * con copia a la persona; D-349 y D-356). Obligatorio. Solo si hubo horas extra o complementarias
 * reconocidas, o exceso aún sin clasificar.
 *
 * @phpstan-type Summary array{week: string, days: list<array{date: string, overtime_minutes: int, complementary_minutes: int, destination: string|null, unclassified_minutes: int}>, overtime_minutes: int, complementary_minutes: int, unclassified_minutes: int}
 */
class OvertimeWeeklySummary extends AppNotification
{
    /**
     * @param  Summary  $summary
     */
    public function __construct(
        public readonly array $summary,
        public readonly string $weekLabel,
        public readonly int $yearMinutes,
    ) {}

    public function kind(): string
    {
        return 'people.overtime_weekly_summary';
    }

    public function title(object $notifiable): string
    {
        return (string) __('people.notifications.overtime_week_title', ['week' => $this->weekLabel]);
    }

    public function body(object $notifiable): ?string
    {
        $lines = [(string) __('people.notifications.overtime_week_body', [
            'overtime' => PeopleFormat::hm($this->summary['overtime_minutes']),
            'complementary' => PeopleFormat::hm($this->summary['complementary_minutes']),
            'unclassified' => PeopleFormat::hm($this->summary['unclassified_minutes']),
            'year' => PeopleFormat::hm($this->yearMinutes),
        ])];

        foreach ($this->summary['days'] as $day) {
            $lines[] = '· '.PeopleFormat::day($day['date']).': '.(string) __('people.notifications.overtime_week_day', [
                'overtime' => PeopleFormat::hm($day['overtime_minutes'] + $day['complementary_minutes']),
                'destination' => $day['destination'] === null ? '—' : (string) __("people.overtime.destinations.{$day['destination']}"),
                'unclassified' => PeopleFormat::hm($day['unclassified_minutes']),
            ]);
        }

        return implode("\n", $lines);
    }

    public function url(object $notifiable): ?string
    {
        return '/personas/registro#horas-extra';
    }

    public function icon(): ?string
    {
        return 'timer';
    }
}
