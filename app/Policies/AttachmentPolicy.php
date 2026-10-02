<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\Message;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Adjuntos (SPEC §15): se descargan con ruta firmada Y esta política. Los internos ven los de
 * cualquier proyecto (D-021); el portal de cliente (Fase 5) añadirá su propia regla. Los del chat
 * (Fase 6, D-071), solo quien puede ver su conversación.
 * Los borra quien los subió, quien gestiona el proyecto o un admin; los del chat, nunca sueltos.
 */
class AttachmentPolicy
{
    /**
     * Solo si lo que lo contiene sigue existiendo: una URL firmada (válida 1 h) de un adjunto de
     * una tarea o un comentario ya borrados deja de servir el fichero en cuanto se borran (D-040).
     * Al borrar una tarea sus comentarios se quedan, así que el de un comentario exige además que
     * su tarea exista (como la pestaña Archivos).
     */
    public function view(User $user, Attachment $attachment): bool
    {
        if (! $user->isInternal()) {
            return false;
        }

        if ($attachment->attachable_type === (new Message)->getMorphClass()) {
            return $this->viewInChat($user, $attachment);
        }

        $attachable = $attachment->attachable;

        if ($attachable instanceof TaskComment) {
            return $attachable->task()->exists();
        }

        return $attachable !== null;
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        // Los del chat se quitan borrando su mensaje (MessageWriter, D-069), nunca sueltos: así
        // nadie borra un archivo de una conversación que no ve (el admin no ve las directas).
        if ($attachment->attachable_type === (new Message)->getMorphClass()) {
            return false;
        }

        if ($attachment->user_id === $user->id || $user->isAdmin()) {
            return true;
        }

        return $attachment->project !== null && $user->canManageProject($attachment->project);
    }

    /**
     * Adjuntos y audios del chat (D-069, D-071): quien puede ver la conversación (sus participantes;
     * el admin, en las de proyecto y de grupo). Los de un mensaje borrado u ocultado dejan de
     * servirse aunque la URL firmada siga vigente, salvo a quien modera la conversación.
     */
    private function viewInChat(User $user, Attachment $attachment): bool
    {
        $message = Message::withTrashed()->with('conversation')->find($attachment->attachable_id);

        if ($message === null) {
            return false;
        }

        $gate = Gate::forUser($user);

        if (! $gate->allows('view', $message->conversation)) {
            return false;
        }

        if ($message->trashed() || $message->hidden_at !== null) {
            return $gate->allows('moderate', $message->conversation);
        }

        return true;
    }
}
