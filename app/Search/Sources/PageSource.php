<?php

namespace App\Search\Sources;

use App\Models\User;
use App\Search\SearchResult;
use App\Search\SearchSource;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Secciones de la aplicación (barra lateral y ajustes) a las que el usuario tiene acceso.
 */
class PageSource implements SearchSource
{
    public function search(User $user, string $query, int $limit): array
    {
        $needle = self::normalize($query);
        $results = [];

        foreach ($this->pages() as $page) {
            if (! Route::has($page['route']) || ! ($page['allowed'])($user)) {
                continue;
            }

            $haystack = self::normalize($page['title'].' '.$page['keywords']);

            if (! str_contains($haystack, $needle)) {
                continue;
            }

            $results[] = new SearchResult(
                type: 'page',
                id: $page['route'],
                title: $page['title'],
                subtitle: $page['subtitle'],
                url: route($page['route'], absolute: false),
            );

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * @return list<array{route: string, title: string, subtitle: string, keywords: string, allowed: Closure(User): bool}>
     */
    protected function pages(): array
    {
        $always = fn (User $user): bool => true;

        return [
            ['route' => 'home', 'title' => 'Inicio', 'subtitle' => 'Tu panel personal', 'keywords' => 'panel dashboard resumen', 'allowed' => $always],
            ['route' => 'my-tasks.index', 'title' => 'Mis tareas', 'subtitle' => 'Tareas asignadas a ti', 'keywords' => 'tareas pendientes', 'allowed' => $always],
            ['route' => 'projects.index', 'title' => 'Proyectos', 'subtitle' => 'Proyectos y tareas', 'keywords' => 'proyecto', 'allowed' => $always],
            ['route' => 'clients.index', 'title' => 'Clientes', 'subtitle' => 'Clientes de Audax Studio', 'keywords' => 'cliente empresa', 'allowed' => $always],
            ['route' => 'hour-banks.index', 'title' => 'Bolsas', 'subtitle' => 'Bolsas de horas', 'keywords' => 'bolsas de horas consumo', 'allowed' => fn (User $user): bool => Gate::forUser($user)->allows('view-hour-banks')],
            ['route' => 'time.index', 'title' => 'Horas', 'subtitle' => 'Imputación de horas', 'keywords' => 'imputar temporizador hoja semanal', 'allowed' => $always],
            ['route' => 'workload.index', 'title' => 'Carga', 'subtitle' => 'Capacidad y carga de trabajo', 'keywords' => 'capacidad planificacion ausencias', 'allowed' => $always],
            ['route' => 'reports.index', 'title' => 'Informes', 'subtitle' => 'Informes y dashboards', 'keywords' => 'dashboard productividad rentabilidad', 'allowed' => $always],
            ['route' => 'chat.index', 'title' => 'Chat', 'subtitle' => 'Conversaciones', 'keywords' => 'mensajes conversaciones', 'allowed' => $always],
            ['route' => 'admin.index', 'title' => 'Administración', 'subtitle' => 'Usuarios, departamentos y ajustes', 'keywords' => 'admin usuarios departamentos ajustes configuracion', 'allowed' => fn (User $user): bool => $user->isAdmin()],
            ['route' => 'profile.edit', 'title' => 'Perfil', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes nombre correo', 'allowed' => $always],
            ['route' => 'security.edit', 'title' => 'Seguridad', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes contrasena 2fa doble factor', 'allowed' => $always],
            ['route' => 'appearance.edit', 'title' => 'Apariencia', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes tema claro oscuro', 'allowed' => $always],
            ['route' => 'sessions.index', 'title' => 'Sesiones activas', 'subtitle' => 'Ajustes', 'keywords' => 'ajustes dispositivos cerrar sesion', 'allowed' => $always],
        ];
    }

    /**
     * Minúsculas y sin acentos, para comparar "administracion" con "Administración".
     */
    private static function normalize(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}
