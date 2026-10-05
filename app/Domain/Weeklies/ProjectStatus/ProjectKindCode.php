<?php

namespace App\Domain\Weeklies\ProjectStatus;

use App\Domain\Weeklies\Report\WeeklyProjectStatus;

/**
 * Tipo de proyecto de WeeklySync (ws:src/lib/projectStatus.ts) a partir del código de Audax (D-148 y
 * D-194). Los códigos de ClickUp son «TIPO+N» (BH1, FE2…) y Audax los importó como `CLIENTE-FE1`
 * (D-135): el tipo es el prefijo de dos letras del último tramo. Si no es uno de los diez de
 * WeeklySync, se deduce del tipo de la vista: una bolsa es BH, un fee mensual es FE y lo demás, GE.
 *
 * Cada tipo tiene su etiqueta (`lang/es/weeklies.php`, `project_kinds`) y su grupo (tag): las tres
 * auditorías comparten «Auditoría», como en WeeklySync.
 */
final class ProjectKindCode
{
    /** Orden de los grupos (PROJECT_TAGS de WeeklySync). */
    public const array TAGS = ['product', 'ecommerce', 'web', 'audit', 'monthly_fee', 'hour_bank', 'branding', 'general'];

    /** Prefijo → grupo (PROJECT_PREFIX_TO_KIND y PROJECT_KIND_TO_TAG). */
    public const array PREFIX_TO_TAG = [
        'PR' => 'product',
        'EC' => 'ecommerce',
        'WE' => 'web',
        'AD' => 'audit',
        'AM' => 'audit',
        'AT' => 'audit',
        'FE' => 'monthly_fee',
        'BH' => 'hour_bank',
        'BR' => 'branding',
        'GE' => 'general',
    ];

    /** Prefijo de WeeklySync de un proyecto: el de su código o el de su tipo de vista. */
    public static function for(string $code, string $viewKind): string
    {
        $segments = explode('-', strtoupper(trim($code)));
        $last = end($segments);

        if (preg_match('/^([A-Z]{2})\d*$/', $last, $match) === 1 && isset(self::PREFIX_TO_TAG[$match[1]])) {
            return $match[1];
        }

        return match ($viewKind) {
            WeeklyProjectStatus::KIND_HOUR_BANK => 'BH',
            WeeklyProjectStatus::KIND_MONTHLY_FEE => 'FE',
            default => 'GE',
        };
    }

    public static function tag(string $prefix): string
    {
        return self::PREFIX_TO_TAG[$prefix] ?? 'general';
    }

    /** Etiqueta del tipo («Fee mensual», «Auditoría técnica»…). */
    public static function label(string $prefix): string
    {
        return __("weeklies.project_kinds.{$prefix}");
    }

    /** Etiqueta del grupo («Fee mensual», «Auditoría»…). */
    public static function tagLabel(string $tag): string
    {
        return __("weeklies.project_tags.{$tag}");
    }

    /**
     * Insignias de un cliente: cuántos proyectos tiene de cada grupo, en el orden de TAGS
     * (deriveProjectBadgesFromStatuses).
     *
     * @param  iterable<string>  $prefixes
     * @return list<array{tag: string, count: int}>
     */
    public static function badges(iterable $prefixes): array
    {
        $counts = [];

        foreach ($prefixes as $prefix) {
            $tag = self::tag($prefix);
            $counts[$tag] = ($counts[$tag] ?? 0) + 1;
        }

        $badges = [];

        foreach (self::TAGS as $tag) {
            if (isset($counts[$tag])) {
                $badges[] = ['tag' => $tag, 'count' => $counts[$tag]];
            }
        }

        return $badges;
    }

    /** Posición del grupo para ordenar (sortProjectStatuses). */
    public static function tagIndex(string $prefix): int
    {
        $index = array_search(self::tag($prefix), self::TAGS, true);

        return $index === false ? count(self::TAGS) : $index;
    }
}
