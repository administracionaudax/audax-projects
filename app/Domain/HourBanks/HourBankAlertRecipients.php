<?php

namespace App\Domain\HourBanks;

use App\Enums\ProjectAlert;
use App\Enums\Role;
use App\Models\HourBank;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Destinatarios de los avisos de una bolsa (SPEC §8.5 y §8.6, D-023, D-035):
 * - los gestores del proyecto que tengan activada esa alerta,
 * - los responsables del departamento de la bolsa (si la bolsa tiene departamento),
 * - los administradores (sus preferencias llegan en la Fase 7).
 * Solo personas internas y activas, y cada una una sola vez.
 */
final class HourBankAlertRecipients
{
    /**
     * @return Collection<int, User>
     */
    public function for(HourBank $bank, ProjectAlert $alert): Collection
    {
        /** @var Collection<int, User> $recipients */
        $recipients = new Collection;

        $managers = User::query()
            ->active()
            ->internal()
            ->whereHas('projects', fn (Builder $projects) => $projects
                ->whereKey($bank->project_id)
                ->where('project_members.is_manager', true))
            ->with(['projects' => fn ($projects) => $projects->whereKey($bank->project_id)])
            ->get();

        foreach ($managers as $manager) {
            $membership = $manager->projects->first()?->membership;

            if ($membership !== null && $membership->wantsAlert($alert)) {
                $recipients->push($manager);
            }
        }

        if ($bank->department_id !== null) {
            $recipients = $recipients->concat(
                User::query()
                    ->active()
                    ->internal()
                    ->whereHas('managedDepartments', fn (Builder $departments) => $departments->whereKey($bank->department_id))
                    ->get(),
            );
        }

        $recipients = $recipients->concat(User::query()->active()->role(Role::Admin->value)->get());

        return $recipients->unique('id')->values();
    }
}
