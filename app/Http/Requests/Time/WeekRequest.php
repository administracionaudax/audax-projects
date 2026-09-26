<?php

namespace App\Http\Requests\Time;

use App\Domain\Time\Week;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * POST /horas/semana/enviar {week: "2026-W39"}: la semana ISO que se envía.
 */
class WeekRequest extends TimeRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'week' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || Week::fromIso($value) === null) {
                    $fail(__('time.errors.week_invalid'));
                }
            }],
        ];
    }

    public function week(): Week
    {
        $week = Week::fromIso($this->string('week')->toString());

        abort_if($week === null, 422);

        return $week;
    }
}
