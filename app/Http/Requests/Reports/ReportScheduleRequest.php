<?php

namespace App\Http\Requests\Reports;

use App\Domain\Reports\Delivery\RelativePeriod;
use App\Domain\Reports\Delivery\ScheduleClock;
use App\Domain\Reports\Delivery\ScheduleFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Crear o editar un envío programado (D-141): lo común de ReportDeliveryRequest y, además,
 * - el periodo relativo (fijo, en curso o anterior, resuelto el día del envío),
 * - la frecuencia, en hora de Madrid: una vez (fecha y hora, en el futuro), semanal (día 1-7, con
 *   lunes = 1, y hora) o mensual (día 1-28, o 0 = el último, y hora).
 * Solo se guarda lo que usa la frecuencia elegida.
 */
class ReportScheduleRequest extends ReportDeliveryRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $frequency = $this->input('frequency');

        $this->merge([
            'run_date' => $frequency === ScheduleFrequency::Once->value ? $this->input('run_date') : null,
            'weekday' => $frequency === ScheduleFrequency::Weekly->value ? $this->input('weekday') : null,
            'month_day' => $frequency === ScheduleFrequency::Monthly->value ? $this->input('month_day') : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'relative_period' => ['required', Rule::enum(RelativePeriod::class)],
            'frequency' => ['required', Rule::enum(ScheduleFrequency::class)],
            'run_date' => ['nullable', 'required_if:frequency,'.ScheduleFrequency::Once->value, 'date_format:Y-m-d'],
            'weekday' => ['nullable', 'required_if:frequency,'.ScheduleFrequency::Weekly->value, 'integer', 'min:1', 'max:7'],
            'month_day' => ['nullable', 'required_if:frequency,'.ScheduleFrequency::Monthly->value, 'integer', 'min:0', 'max:28'],
            'time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            if ($this->input('frequency') !== ScheduleFrequency::Once->value || $validator->errors()->hasAny(['run_date', 'time'])) {
                return;
            }

            if (ScheduleClock::localInstant((string) $this->input('run_date'), (string) $this->input('time'))->lessThanOrEqualTo(CarbonImmutable::now())) {
                $validator->errors()->add('run_date', $this->text('report_deliveries.errors.run_at_past'));
            }
        }];
    }

    /**
     * Los datos de la programación para el modelo (sin propietario ni próximo envío).
     *
     * @return array<string, mixed>
     */
    public function scheduleAttributes(): array
    {
        $frequency = ScheduleFrequency::from((string) $this->validated('frequency'));
        $time = (string) $this->validated('time');

        return [
            'title' => (string) $this->validated('title'),
            'request' => $this->reportRequest()->toArray(),
            'formats' => $this->formats(),
            'relative_period' => RelativePeriod::from((string) $this->validated('relative_period')),
            'recipient_user_ids' => $this->recipientUserIds(),
            'recipient_emails' => $this->recipientEmails(),
            'subject' => $this->validated('subject'),
            'message' => $this->validated('message'),
            'frequency' => $frequency,
            'run_at' => $frequency === ScheduleFrequency::Once ? ScheduleClock::localInstant((string) $this->validated('run_date'), $time) : null,
            'weekday' => $frequency === ScheduleFrequency::Weekly ? (int) $this->validated('weekday') : null,
            'month_day' => $frequency === ScheduleFrequency::Monthly ? (int) $this->validated('month_day') : null,
            'time' => $time,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return parent::messages() + [
            'run_date.required_if' => $this->text('report_deliveries.errors.run_date'),
            'run_date.date_format' => $this->text('report_deliveries.errors.run_date'),
            'weekday.required_if' => $this->text('report_deliveries.errors.weekday'),
            'weekday.min' => $this->text('report_deliveries.errors.weekday'),
            'weekday.max' => $this->text('report_deliveries.errors.weekday'),
            'month_day.required_if' => $this->text('report_deliveries.errors.month_day'),
            'month_day.min' => $this->text('report_deliveries.errors.month_day'),
            'month_day.max' => $this->text('report_deliveries.errors.month_day'),
            'time.required' => $this->text('report_deliveries.errors.time'),
            'time.regex' => $this->text('report_deliveries.errors.time'),
        ];
    }
}
