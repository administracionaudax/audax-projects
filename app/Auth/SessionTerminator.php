<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cierra sesiones de un usuario (SPEC §15). Lo usan la pantalla de sesiones activas, el cambio de
 * contraseña y el restablecimiento por correo.
 *
 * Borrar la fila de la tabla sessions no basta: un dispositivo con «Recordarme» volvería a entrar
 * con su cookie. Por eso, cada cierre cambia también el remember_token del usuario y, si el
 * dispositivo actual usaba «Recordarme», se le reenvía la cookie con el token nuevo.
 *
 * Solo el driver de sesión "database" permite listar y borrar sesiones (D-014).
 */
class SessionTerminator
{
    public const string GUARD = 'web';

    /**
     * Duración estándar de la cookie de «Recordarme» del guard (SessionGuard::$rememberDuration),
     * que no es accesible desde fuera: 400 días.
     */
    public const int REMEMBER_COOKIE_MINUTES = 576000;

    public function supported(): bool
    {
        return config('session.driver') === 'database';
    }

    public function table(): string
    {
        return (string) config('session.table', 'sessions');
    }

    /**
     * Cierra una sesión concreta del usuario (nunca la actual: eso es «Cerrar sesión»).
     */
    public function destroy(Request $request, User $user, string $sessionId): void
    {
        if ($this->supported()) {
            DB::table($this->table())
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', $sessionId)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        $this->cycleRememberToken($request, $user);
    }

    /**
     * Cierra todas las sesiones del usuario salvo la actual, que sigue abierta.
     */
    public function destroyOthers(Request $request, User $user): void
    {
        if ($this->supported()) {
            DB::table($this->table())
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        $this->cycleRememberToken($request, $user);

        // La sesión actual sigue siendo válida para AuthenticateSession aunque cambie la contraseña.
        $guard = Auth::guard(self::GUARD);

        if ($guard instanceof SessionGuard && $request->hasSession()) {
            $request->session()->put(
                'password_hash_'.self::GUARD,
                $guard->hashPasswordForCookie($user->getAuthPassword()),
            );
        }
    }

    /**
     * Cierra todas las sesiones del usuario, sin excepción (restablecimiento de contraseña).
     */
    public function destroyAll(User $user): void
    {
        if ($this->supported()) {
            DB::table($this->table())->where('user_id', $user->getAuthIdentifier())->delete();
        }

        $this->storeNewRememberToken($user);
    }

    /**
     * Cambia el remember_token (los demás dispositivos pierden el «Recordarme») y, si este dispositivo
     * lo usaba, le reenvía la cookie con el formato y la duración del guard.
     */
    private function cycleRememberToken(Request $request, User $user): void
    {
        $this->storeNewRememberToken($user);

        $guard = Auth::guard(self::GUARD);

        if (! $guard instanceof SessionGuard || ! $request->cookies->has($guard->getRecallerName())) {
            return;
        }

        Cookie::queue(
            $guard->getRecallerName(),
            $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
            self::REMEMBER_COOKIE_MINUTES,
        );
    }

    /**
     * Guarda un remember_token nuevo con una consulta directa, sin eventos de modelo: destroyAll()
     * se llama desde el evento "updated" del propio usuario (al desactivarlo) y un save() ahí
     * volvería a dispararlo.
     */
    private function storeNewRememberToken(User $user): void
    {
        $column = $user->getRememberTokenName();

        $user->setRememberToken(Str::random(60));
        $user->newQuery()->whereKey($user->getKey())->update([$column => $user->getRememberToken()]);
        $user->syncOriginalAttribute($column);
    }
}
