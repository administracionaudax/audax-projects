<?php

namespace App\Http\Requests\PortalAccess;

use App\Http\Requests\Admin\Concerns\NormalizesInput;
use App\Models\Client;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Invitar a una persona del cliente al portal (D-063): nombre y correo. Quién: ClientPolicy::managePortal.
 * Que el correo no exista (ni en otro cliente ni en el equipo) lo comprueba PortalUsers::invite
 * dentro de la transacción, con un mensaje distinto si es de una persona del equipo.
 */
class InvitePortalUserRequest extends FormRequest
{
    use NormalizesInput;

    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client && Gate::allows('managePortal', $client);
    }

    protected function prepareForValidation(): void
    {
        $this->trimStrings(['name']);

        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('portal.access.attributes.name'),
            'email' => __('portal.access.attributes.email'),
        ];
    }
}
