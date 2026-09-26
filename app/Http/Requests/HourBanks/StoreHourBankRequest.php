<?php

namespace App\Http\Requests\HourBanks;

use App\Http\Requests\HourBanks\Concerns\HourBankRules;
use App\Models\HourBank;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Nueva bolsa en un proyecto de bolsas (la crean quienes gestionan el proyecto).
 */
class StoreHourBankRequest extends FormRequest
{
    use HourBankRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', [HourBank::class, $this->route('project')]) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->hourBankRules();
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
