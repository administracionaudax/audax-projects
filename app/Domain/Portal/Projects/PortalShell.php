<?php

namespace App\Domain\Portal\Projects;

use App\Domain\Billing\Issuing\PortalInvoices;
use App\Domain\Identity\CompanyIdentity;
use App\Domain\Portal\PortalScope;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Prop compartida `portal` de las páginas de un usuario del portal (layout del portal, D-067):
 * - la identidad de la empresa (nombre y logo) para la cabecera,
 * - los proyectos de su cliente abiertos al portal, para la navegación («Proyectos») y para la
 *   tarjeta «Tus proyectos» del Inicio,
 * - si ve sus facturas (E2, D-443), para «Facturas» en la navegación.
 * Nunca falla: si el usuario no tiene alcance (cliente desactivado, sin cliente), no hay proyectos.
 *
 * Contrato: resources/js/components/portal/projects/types.ts (PortalShellProps).
 */
final class PortalShell
{
    public function __construct(
        private readonly CompanyIdentity $identity,
        private readonly PortalProjects $projects,
    ) {}

    /**
     * @return array{company: array{name: string, logo: array{url: string, width: int, height: int}|null}, projects: list<array{id: int, code: string, name: string, view: bool, gantt: bool}>, invoices: bool}
     */
    public function for(User $user): array
    {
        try {
            $scope = PortalScope::for($user);
            $projects = $this->projects->navigation($scope);
            // «Facturas» (E2, D-443): con la emisión encendida y las facturas abiertas al cliente.
            $invoices = PortalInvoices::enabledFor($scope);
        } catch (HttpException) {
            $projects = [];
            $invoices = false;
        }

        return [
            'company' => $this->identity->forPortal(),
            'projects' => $projects,
            'invoices' => $invoices,
        ];
    }
}
