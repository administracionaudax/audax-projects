<?php

namespace App\Domain\Recurring;

use App\Models\RecurringTaskRule;
use Carbon\CarbonImmutable;

/**
 * Cómo se lee una regla recurrente (D-059) en las listas: la frase («Cada 2 semanas, los lunes»,
 * «Cada mes, el día 31 (o el último)») y la próxima fecha en que se creará una tarea. Gemelo de
 * resources/js/components/recurring/recurrence.ts, que la enseña en vivo en el formulario.
 */
final class RecurrenceDescriber
{
    /** La repetición más larga (cada 12 meses) cabe siempre en este horizonte. */
    public const int NEXT_HORIZON_MONTHS = 13;

    /** Desde este día del mes, algún mes no lo tiene y se usa su último día. */
    public const int LAST_DAY_FROM = 29;

    public function describe(RecurringTaskRule $rule): string
    {
        $interval = max($rule->interval, 1);

        if ($rule->frequency === RecurringTaskRule::WEEKLY) {
            $weekday = min(max($rule->weekday ?? $rule->starts_on->dayOfWeekIso, 1), 7);

            return $this->text('templates.recurrence.weekly', [
                'every' => $interval === 1
                    ? $this->text('templates.recurrence.every_week')
                    : $this->text('templates.recurrence.every_n_weeks', ['count' => $interval]),
                'day' => $this->text("templates.recurrence.weekdays.{$weekday}"),
            ]);
        }

        $day = min(max($rule->month_day ?? $rule->starts_on->day, 1), 31);

        return $this->text($day >= self::LAST_DAY_FROM ? 'templates.recurrence.monthly_last' : 'templates.recurrence.monthly', [
            'every' => $interval === 1
                ? $this->text('templates.recurrence.every_month')
                : $this->text('templates.recurrence.every_n_months', ['count' => $interval]),
            'day' => $day,
        ]);
    }

    /**
     * Próxima fecha (Y-m-d) en que se creará una tarea: hoy si toca y aún no se ha generado, o la
     * siguiente. Null si la regla está desactivada o ya no tiene más fechas (hasta ends_on).
     */
    public function nextDate(RecurringTaskRule $rule, CarbonImmutable $today): ?string
    {
        if (! $rule->is_active) {
            return null;
        }

        $from = $rule->last_generated_on !== null && $rule->last_generated_on->toDateString() >= $today->toDateString()
            ? $today->addDay()
            : $today;

        return $rule->occurrencesBetween($from, $from->addMonthsNoOverflow(self::NEXT_HORIZON_MONTHS))[0] ?? null;
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function text(string $key, array $replace = []): string
    {
        $line = __($key, $replace);

        return is_string($line) ? $line : $key;
    }
}
