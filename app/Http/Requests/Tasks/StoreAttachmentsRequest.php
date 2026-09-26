<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Subir adjuntos a una tarea (TaskPolicy::update, en el controlador).
 */
class StoreAttachmentsRequest extends FormRequest
{
    use ValidatesAttachments;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->attachmentRules(required: true);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->attachmentMessages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> */
        return (array) __('tasks.attributes');
    }
}
