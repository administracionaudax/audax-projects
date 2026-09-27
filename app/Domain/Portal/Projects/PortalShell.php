<?php

namespace App\Domain\Portal\Projects;

use App\Domain\Identity\CompanyIdentity;
use App\Domain\Portal\PortalScope;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Prop compartida `portal` de las páginas de un usuario del portal (layout del portal, D-067):
 * - la identidad de la empresa (nombre y logo) para la cabecera,
 * - los proyectos de su cliente abiertos al portal, para la navegación («Proyectos») y para la
 *   tarjeta «Tus proyectos» del Inicio.
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
     * @return array{company: array{name: string, logo: array{url: string, width: int, height: int}|null}, projects: list<array{id: int, code: string, name: string, view: bool, gantt: bool}>}
     */
    public function for(User $user): array
    {
        try {
            $projects = $this->projects->navigation(PortalScope::for($user));
        } catch (HttpException) {
            $projects = [];
        }

        return [
            'company' => $this->identity->forPortal(),
            'projects' => $projects,
        ];
    }
}
