<?php

namespace App\Http\Requests\Chat;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base de las peticiones del chat. Antes de validar nada comprueba que quien pide puede ver la
 * conversación de la URL (la del mensaje, si es una acción sobre un mensaje): así nadie distingue,
 * por los errores de validación, una conversación que no ve (el admin y las directas ajenas, D-071)
 * de una que no existe. Lo demás lo autorizan el controlador y MessageWriter (MessagePolicy).
 * Los nombres de los campos salen de lang/es/conversations.php.
 */
abstract class ChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        $message = $this->route('message');
        $conversation = $message instanceof Message ? $message->conversation : $this->route('conversation');

        return ! $conversation instanceof Conversation || Gate::allows('view', $conversation);
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
