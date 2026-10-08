<?php

namespace App\Policies;

use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Clientes (D-021, D-022): los ven todos los internos; los crean y editan admins y responsables.
 * No se borran: se desactivan (D-037). Un colaborador externo no ve clientes (D-134).
 */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isInternal() && ! $user->isCollaborator();
    }

    public function view(User $user, Client $client): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isDepartmentManager();
    }

    public function update(User $user, Client $client): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Client $client): bool
    {
        return false;
    }

    /**
     * Informe del cliente (/informes/clientes/{client}, D-044): admins y responsables; un gestor,
     * solo si gestiona algún proyecto del cliente (el informe se limita a esos proyectos).
     */
    public function viewReport(User $user, Client $client): bool
    {
        if ($user->isAdmin() || $user->isDepartmentManager()) {
            return true;
        }

        $managed = $user->managedProjectIds();

        return $managed !== [] && Project::query()->withTrashed()
            ->where('client_id', $client->id)
            ->whereKey($managed)
            ->exists();
    }

    /**
     * Usuarios del portal del cliente (Fase 5, D-063): invitar, reenviar la invitación, revocar y
     * reactivar. Admins, responsables y los gestores de algún proyecto (sin borrar) de ese cliente.
     * Los ajustes del portal del cliente (qué horas ve, cómo se nombra a las personas y los avisos)
     * son datos del cliente: los cambia quien lo edita (update).
     */
    public function managePortal(User $user, Client $client): bool
    {
        if (! $user->isInternal()) {
            return false;
        }

        if ($user->isAdmin() || $user->isDepartmentManager()) {
            return true;
        }

        $managed = $user->managedProjectIds();

        return $managed !== [] && Project::query()
            ->where('client_id', $client->id)
            ->whereKey($managed)
            ->exists();
    }

    /**
     * Exportación de horas para facturar (/informes/facturacion, D-045): admins y quien tenga
     * view-financials. Las tarifas e importes, además, solo con view-financials. Nunca quien está
     * excluido de Facturación (D-245, D-247), aunque sea admin.
     */
    public function viewBilling(User $user): bool
    {
        return ($user->isAdmin() || Gate::forUser($user)->allows('view-financials'))
            && ! AppModules::excluded($user, AppModule::Billing);
    }
}
