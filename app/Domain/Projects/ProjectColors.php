<?php

namespace App\Domain\Projects;

/**
 * Paleta de colores de proyecto: la categórica de marca (D-012, tema claro), en su orden fijo.
 * Gemela de PROJECT_COLORS en resources/js/components/projects-list/project-colors.ts.
 */
final class ProjectColors
{
    public const array PALETTE = ['#0171FF', '#179FA5', '#5E2DAD', '#E65FB3', '#3C41AE', '#0892C4'];

    /**
     * Color por defecto de un proyecto nuevo: rota por la paleta para que los proyectos seguidos
     * no se confundan.
     */
    public static function next(int $existing): string
    {
        return self::PALETTE[$existing % count(self::PALETTE)];
    }
}
