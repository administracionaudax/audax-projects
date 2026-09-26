<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

/**
 * Adjuntos (SPEC §15): se descargan con ruta firmada Y esta política. Los internos ven los de
 * cualquier proyecto (D-021); el portal de cliente (Fase 5) añadirá su propia regla.
 * Los borra quien los subió, quien gestiona el proyecto o un admin.
 */
class AttachmentPolicy
{
    /**
     * Solo si lo que lo contiene sigue existiendo: una URL firmada (válida 1 h) de un adjunto de
     * una tarea o un comentario ya borrados deja de servir el fichero en cuanto se borran.
     */
    public function view(User $user, Attachment $attachment): bool
    {
        return $user->isInternal() && $attachment->attachable !== null;
    }

    public function delete(User $user, Attachment $attachment): bool
    {
        if ($attachment->user_id === $user->id || $user->isAdmin()) {
            return true;
        }

        return $attachment->project !== null && $user->canManageProject($attachment->project);
    }
}
