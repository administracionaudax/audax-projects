<?php

namespace App\Http\Requests\HourBanks;

use App\Http\Requests\HourBanks\Concerns\HourBankRules;
use App\Models\HourBank;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Renovar una bolsa (SPEC §8.7): los parámetros de la bolsa nueva (editables en el diálogo) y si
 * se mueven las tareas abiertas. Las horas nunca se mueven.
 */
class RenewHourBankRequest extends FormRequest
{
    use HourBankRules;

    public function authorize(): bool
    {
        /** @var HourBank $bank */
        $bank = $this->route('hourBank');

        return $this->user()?->can('renew', $bank) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->hourBankRules(),
            'move_open_tasks' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->hourBankMessages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->hourBankAttributes();
    }

    /**
     * Datos de la bolsa nueva (sin la opción de mover tareas).
     *
     * @return array<string, mixed>
     */
    public function bankAttributes(): array
    {
        return collect($this->validated())->except('move_open_tasks')->all();
    }
}
