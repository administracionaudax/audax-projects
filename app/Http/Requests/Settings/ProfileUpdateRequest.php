<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ProfileUpdateRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * El correo se guarda en minúsculas y sin espacios: el login y el restablecimiento lo buscan así
     * (fortify.lowercase_usernames), y en PostgreSQL la comparación distingue mayúsculas.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        $rules = $this->profileRules($user->id);
        // Puesto (Fase 10, F-026 y F-027): opcional.
        $rules['job_title'] = ['nullable', 'string', 'max:120'];

        // Cambiar el correo exige la contraseña actual: con una sesión robada no se puede desviar
        // el correo y, con él, el restablecimiento de la contraseña.
        if ($this->changesEmail($user)) {
            $rules['current_password'] = $this->currentPasswordRules();
        }

        return $rules;
    }

    private function changesEmail(User $user): bool
    {
        $email = $this->input('email');

        return is_string($email) && $email !== $user->email;
    }
}
