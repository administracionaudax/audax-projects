<?php

namespace App\Http\Requests\HourBanks;

use App\Enums\HourBankStatus;
use App\Http\Requests\HourBanks\Concerns\HourBankRules;
use App\Models\HourBank;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Edición de una bolsa. Cambiar el total recalcula su consumo y exceso (HourBank::booted →
 * HourBankLedger). Una bolsa renovada no se edita: su histórico queda como estaba.
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
        return $this->hourBankRules();
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->hourBank()->status === HourBankStatus::Renewed) {
                $validator->errors()->add('hour_bank', $this->transText('hour_banks.errors.renewed_not_editable'));
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
