<?php

namespace App\Http\Requests\Tasks;

use App\Support\RichText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Comentario nuevo (HTML de Tiptap, saneado después en el servidor) con adjuntos opcionales.
 * Autoriza el controlador (TaskPolicy::comment).
 */
class StoreCommentRequest extends FormRequest
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
        return [
            'body' => ['nullable', 'string', 'max:'.RichText::MAX_LENGTH],
            ...$this->attachmentRules(required: false),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $files = $this->file('files');

                if (RichText::isBlank(RichText::sanitize($this->string('body')->toString())) && empty($files)) {
                    $validator->errors()->add('body', __('tasks.errors.comment_empty'));
                }
            },
        ];
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
