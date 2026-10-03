<?php

namespace App\Http\Requests\Projects;

use App\Http\Requests\Projects\Concerns\TranslatesAttributes;
use App\Http\Requests\Projects\Rules\ActiveInternalUser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambiar de gestor principal (D-032): una persona interna activa.
 */
class UpdateProjectOwnerRequest extends FormRequest
{
    use TranslatesAttributes;

    public function authorize(): bool
    {
        return $this->user()?->can('manageMembers', $this->route('project')) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'owner_user_id' => ['required', 'integer', new ActiveInternalUser(manager: true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->translatedAttributes('projects', ['owner_user_id']);
    }
}
