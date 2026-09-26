<?php

namespace App\Http\Requests\Projects;

use App\Http\Requests\Projects\Concerns\TranslatesAttributes;
use App\Http\Requests\Projects\Rules\ActiveInternalUser;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Añadir un miembro (y, si se marca, gestor) a un proyecto: solo personas internas activas.
 */
class StoreProjectMemberRequest extends FormRequest
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
            'user_id' => ['required', 'integer', new ActiveInternalUser],
            'is_manager' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->translatedAttributes('projects', ['user_id', 'is_manager']);
    }

    public function project(): Project
    {
        /** @var Project */
        return $this->route('project');
    }
}
