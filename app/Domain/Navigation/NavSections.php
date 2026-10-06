<?php

namespace App\Domain\Navigation;

use App\Models\User;

/**
 * Secciones plegables de la barra lateral (D-260). Se guarda en `users.nav_collapsed` la lista de
 * las que cada persona tiene plegadas; mientras no toque nada (null), solo Proyectos va desplegada
 * (D-261).
 *
 * La lista blanca es el contrato con `NAV_SECTION_IDS` de resources/js/hooks/use-nav-sections.ts.
 * «Personas» (RR. HH., nombre provisional) y «Facturación» están preparadas: sin entradas visibles
 * no se pintan, pero su estado se puede guardar.
 */
final class NavSections
{
    /** @var list<string> */
    public const SECTIONS = ['projects', 'weekly', 'people', 'billing', 'admin'];

    /** @var list<string> Plegadas por defecto (D-261): todas menos Proyectos. */
    public const DEFAULT_COLLAPSED = ['weekly', 'people', 'billing', 'admin'];

    /**
     * Secciones plegadas de esta persona, en el orden de la barra y sin ids desconocidos (una
     * sección que ya no existe se ignora).
     *
     * @return list<string>
     */
    public static function collapsedFor(User $user): array
    {
        return $user->nav_collapsed === null
            ? self::DEFAULT_COLLAPSED
            : self::normalize($user->nav_collapsed);
    }

    /**
     * @param  array<array-key, mixed>  $sections
     * @return list<string>
     */
    public static function normalize(array $sections): array
    {
        return array_values(array_filter(
            self::SECTIONS,
            fn (string $section): bool => in_array($section, $sections, true),
        ));
    }
}
