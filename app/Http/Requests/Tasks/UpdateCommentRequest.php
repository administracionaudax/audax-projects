<?php

namespace App\Http\Requests\Tasks;

use App\Support\RichText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Editar el texto de un comentario propio (TaskCommentPolicy::update, en el controlador).
 */
class UpdateCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.RichText::MAX_LENGTH],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('body') && RichText::isBlank(RichText::sanitize($this->string('body')->toString()))) {
                    $validator->errors()->add('body', __('tasks.errors.comment_empty'));
                }
            },
        ];
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
