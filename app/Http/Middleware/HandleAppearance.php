<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tema inicial para app.blade.php (evita el parpadeo): cookie "appearance" y, si no hay cookie,
 * la preferencia guardada del usuario (users.theme_preference). Solo admite valores conocidos.
 */
class HandleAppearance
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        View::share('appearance', $this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $cookie = $request->cookie('appearance');

        if (is_string($cookie) && in_array($cookie, User::THEMES, true)) {
            return $cookie;
        }

        $user = $request->user();

        if ($user instanceof User && in_array($user->theme_preference, User::THEMES, true)) {
            return $user->theme_preference;
        }

        return 'system';
    }
}
