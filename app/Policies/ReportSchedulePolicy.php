<?php

namespace App\Policies;

use App\Models\ReportSchedule;
use App\Models\User;

/**
 * Envíos programados de informes (D-141): cualquier persona de la plantilla programa los informes
 * que ve; cada envío lo ven y gestionan su propietario y los admins. Ni clientes ni colaboradores
 * externos (D-134; la ruta ya los corta).
 */
class ReportSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->staff($user);
    }

    public function create(User $user): bool
    {
        return $this->staff($user);
    }

    public function view(User $user, ReportSchedule $schedule): bool
    {
        return $this->manage($user, $schedule);
    }

    public function update(User $user, ReportSchedule $schedule): bool
    {
        return $this->manage($user, $schedule);
    }

    public function delete(User $user, ReportSchedule $schedule): bool
    {
        return $this->manage($user, $schedule);
    }

    /** Ver los de todos (la lista del admin). */
    public function viewAll(User $user): bool
    {
        return $this->staff($user) && $user->isAdmin();
    }

    private function manage(User $user, ReportSchedule $schedule): bool
    {
        return $this->staff($user) && ($schedule->owner_user_id === $user->id || $user->isAdmin());
    }

    private function staff(User $user): bool
    {
        return $user->isActive() && $user->isInternal() && ! $user->isCollaborator();
    }
}
