<?php

namespace App\Http\Requests\Time;

use App\Support\Duration;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base de las peticiones del área de horas: nombres de campo en español en los errores y
 * duraciones que llegan como minutos o como texto («1:30», «1,5», «90m»; SPEC §7, D-036).
 */
abstract class TimeRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = __('time.attributes');

        /** @var array<string, string> */
        return is_array($attributes) ? $attributes : [];
    }

    /**
     * Normaliza un campo de duración a minutos enteros. Si no se entiende, lo deja tal cual para que
     * la validación (integer) lo rechace con su mensaje.
     */
    protected function normalizeDuration(string $field): void
    {
        $value = $this->input($field);

        if (is_string($value) && trim($value) !== '' && ! ctype_digit(trim($value))) {
            $minutes = Duration::parse($value);

            if ($minutes !== null) {
                $this->merge([$field => $minutes]);
            }
        }
    }
}
