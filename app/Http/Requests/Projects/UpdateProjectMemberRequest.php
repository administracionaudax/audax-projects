<?php

namespace App\Http\Requests\Projects;

use App\Http\Requests\Projects\Concerns\TranslatesAttributes;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Marcar o desmarcar a un miembro como gestor del proyecto (D-005).
 */
class UpdateProjectMemberRequest extends FormRequest
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
            'is_manager' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->translatedAttributes('projects', ['is_manager']);
    }
}
