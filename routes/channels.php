<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Canales de tiempo real (Fase 6, D-068)
|--------------------------------------------------------------------------
| /broadcasting/auth va con auth, active e internal: un cliente nunca se suscribe a nada.
| - App.Models.User.{id}: notificaciones de la campana (el canal por defecto de Laravel),
| - conversation.{conversation}: mensajes, «escribiendo…» (whisper del cliente), leídos y reacciones,
| - online: presencia de la plantilla (en línea / ausente / desconectado). Un colaborador externo
|   (D-134) no entra: vería a toda la plantilla; conversation.{conversation} ya lo limita a sus
|   proyectos (ConversationPolicy::view).
*/

Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id): bool => $user->id === $id);

Broadcast::channel('conversation.{conversation}', fn (User $user, Conversation $conversation): bool => Gate::forUser($user)->allows('view', $conversation));

Broadcast::channel('online', fn (User $user): array|false => $user->isInternal() && $user->is_active && ! $user->isCollaborator()
    ? ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar_url]
    : false);
