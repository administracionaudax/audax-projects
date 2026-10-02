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
| - online: presencia de la plantilla (en línea / ausente / desconectado).
*/

Broadcast::channel('App.Models.User.{id}', fn (User $user, int $id): bool => $user->id === $id);

Broadcast::channel('conversation.{conversation}', fn (User $user, Conversation $conversation): bool => Gate::forUser($user)->allows('view', $conversation));

Broadcast::channel('online', fn (User $user): array|false => $user->isInternal() && $user->is_active
    ? ['id' => $user->id, 'name' => $user->name, 'avatar' => $user->avatar_url]
    : false);
