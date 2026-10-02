<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base de las peticiones del chat: autoriza el controlador (ConversationPolicy y MessagePolicy a
 * través de MessageWriter) y los nombres de los campos salen de lang/es/conversations.php.
 */
abstract class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        /** @var array<string, string> */
        return (array) __('conversations.attributes');
    }
}
