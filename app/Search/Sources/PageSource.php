<?php

namespace App\Search\Sources;

use App\Domain\Access\CollaboratorAccess;
use App\Domain\Weeklies\AppModules;
use App\Enums\AppModule;
use App\Models\Absence;
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

            // Un colaborador externo solo ve las secciones abiertas para él (D-134).
            if ($user->isCollaborator() && ! CollaboratorAccess::allowsRouteName($page['route'])) {
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
     * Secciones con su clave de traducción en lang/es/search.php (pages.<clave>.title|subtitle|keywords).
     *
     * @return list<array{route: string, title: string, subtitle: string, keywords: string, allowed: Closure(User): bool}>
     */
    protected function pages(): array
    {
        $always = fn (User $user): bool => true;
        $dayPlan = fn (User $user): bool => Gate::forUser($user)->allows('use-day-plan');

        $pages = [
            ['route' => 'home', 'key' => 'home', 'allowed' => $always],
            ['route' => 'my-tasks.index', 'key' => 'my_tasks', 'allowed' => $always],
            ['route' => 'calendar.index', 'key' => 'calendar', 'allowed' => $always],
            ['route' => 'projects.index', 'key' => 'projects', 'allowed' => $always],
            ['route' => 'clients.index', 'key' => 'clients', 'allowed' => $always],
            ['route' => 'hour-banks.index', 'key' => 'hour_banks', 'allowed' => fn (User $user): bool => Gate::forUser($user)->allows('view-hour-banks')],
            ['route' => 'time.index', 'key' => 'time', 'allowed' => $always],
            ['route' => 'workload.index', 'key' => 'workload', 'allowed' => $always],
            ['route' => 'absences.index', 'key' => 'absences', 'allowed' => $always],
            ['route' => 'absences.team.index', 'key' => 'team_absences', 'allowed' => fn (User $user): bool => Gate::forUser($user)->allows('viewTeam', Absence::class)],
            ['route' => 'reports.index', 'key' => 'reports', 'allowed' => $always],
            ['route' => 'chat.index', 'key' => 'chat', 'allowed' => $always],
            ['route' => 'admin.index', 'key' => 'admin', 'allowed' => fn (User $user): bool => $user->isAdmin()],
            ['route' => 'admin.holidays.index', 'key' => 'holidays', 'allowed' => fn (User $user): bool => Gate::forUser($user)->allows('manage-settings')],
            // Fase 7 (D-074 y D-075): auditoría y privacidad.
            ['route' => 'admin.audit.index', 'key' => 'audit', 'allowed' => fn (User $user): bool => $user->isAdmin()],
            ['route' => 'admin.privacy.edit', 'key' => 'privacy_admin', 'allowed' => fn (User $user): bool => $user->isAdmin()],
            ['route' => 'privacy.show', 'key' => 'privacy', 'allowed' => $always],
            ['route' => 'privacy.exports.index', 'key' => 'my_data', 'allowed' => $always],
            ['route' => 'profile.edit', 'key' => 'profile', 'allowed' => $always],
            ['route' => 'security.edit', 'key' => 'security', 'allowed' => $always],
            ['route' => 'appearance.edit', 'key' => 'appearance', 'allowed' => $always],
            // Solo internos, como toda la búsqueda (D-073).
            ['route' => 'notification-settings.edit', 'key' => 'notification_settings', 'allowed' => fn (User $user): bool => $user->isInternal()],
            ['route' => 'sessions.index', 'key' => 'sessions', 'allowed' => $always],
            // La Weekly (Fase 10, D-239): para quien la usa y con el módulo visible para él (encendido
            // o, en modo de prueba, solo para los admins).
            ['route' => 'my-space.index', 'key' => 'my_space', 'allowed' => self::weekly(AppModule::Weeklies)],
            ['route' => 'weeklies.index', 'key' => 'weeklies', 'allowed' => self::weekly(AppModule::Weeklies)],
            ['route' => 'team.index', 'key' => 'weekly_team', 'allowed' => self::weekly(AppModule::Weeklies)],
            ['route' => 'assistant.index', 'key' => 'assistant', 'allowed' => self::weekly(AppModule::Assistant)],
            ['route' => 'help.index', 'key' => 'help', 'allowed' => self::weekly(AppModule::Help)],
            // Plan del día (D-250): la gate ya mira el módulo (visible para esa persona).
            ['route' => 'day-plan.show', 'key' => 'day_plan', 'allowed' => $dayPlan],
            ['route' => 'day-plan.team', 'key' => 'day_plan_team', 'allowed' => $dayPlan],
            ['route' => 'day-plan.week', 'key' => 'day_plan_week', 'allowed' => $dayPlan],
            // Previsión (D-306): la gate ya mira el módulo (visible para esa persona).
            ['route' => 'forecast.index', 'key' => 'forecast', 'allowed' => fn (User $user): bool => Gate::forUser($user)->allows('view-forecast')],
            ['route' => 'forecast.projects.index', 'key' => 'forecast_projects', 'allowed' => fn (User $user): bool => Gate::forUser($user)->allows('view-forecast')],
        ];

        $translated = [];

        foreach ($pages as $page) {
            $translated[] = [
                'route' => $page['route'],
                'title' => self::text($page['key'], 'title'),
                'subtitle' => self::text($page['key'], 'subtitle'),
                'keywords' => self::text($page['key'], 'keywords'),
                'allowed' => $page['allowed'],
            ];
        }

        return $translated;
    }

    /**
     * @return Closure(User): bool
     */
    private static function weekly(AppModule $module): Closure
    {
        return fn (User $user): bool => AppModules::visibleTo($user, $module) && Gate::forUser($user)->allows('use-weeklies');
    }

    private static function text(string $key, string $field): string
    {
        $text = __("search.pages.{$key}.{$field}");

        return is_string($text) ? $text : '';
    }

    /**
     * Minúsculas y sin acentos, para comparar "administracion" con "Administración".
     */
    private static function normalize(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}
