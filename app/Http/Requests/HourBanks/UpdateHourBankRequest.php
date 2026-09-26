<?php

namespace App\Http\Requests\HourBanks;

use App\Enums\HourBankStatus;
use App\Http\Requests\HourBanks\Concerns\HourBankRules;
use App\Models\HourBank;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Edición de una bolsa. Cambiar el total recalcula su consumo y exceso (HourBank::booted →
 * HourBankLedger). Una bolsa renovada no se edita: su histórico queda como estaba. En una cerrada
 * no se cambia el total (su saldo sin consumir ya está registrado).
 */
class UpdateHourBankRequest extends FormRequest
{
    use HourBankRules;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->hourBank()) ?? false;
    }

    public function hourBank(): HourBank
    {
        /** @var HourBank */
        return $this->route('hourBank');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->hourBankRules(keepDepartmentId: $this->hourBank()->department_id);
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $bank = $this->hourBank();

            if ($bank->status === HourBankStatus::Renewed) {
                $validator->errors()->add('hour_bank', $this->transText('hour_banks.errors.renewed_not_editable'));
            }

            // Cerrada: su saldo sin consumir quedó registrado al cerrarla (SPEC §8.9). Para cambiar
            // el total hay que reabrirla (solo admin); el resto de datos sí se editan.
            if ($bank->status === HourBankStatus::Closed
                && ! $validator->errors()->has('total_minutes')
                && (int) $this->input('total_minutes') !== $bank->total_minutes) {
                $validator->errors()->add('total_minutes', $this->transText('hour_banks.errors.closed_total'));
            }
        }];
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
}
