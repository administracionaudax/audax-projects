<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Http\Middleware\ThrottlePasswordResetLinkRequests;
use App\Http\Responses\PasswordResetLinkRequestResponse;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;
use Throwable;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // «He olvidado mi contraseña» responde igual exista o no el correo (SPEC §15).
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkRequestResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureAuthentication();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Inicio de sesión con email y contraseña que además rechaza a los usuarios desactivados
     * (SPEC §14). Sin registro público: la feature registration no está activa en config/fortify.php.
     *
     * Si el correo no existe se comprueba igualmente la contraseña contra un hash ficticio del mismo
     * coste: así la respuesta tarda lo mismo y no revela qué correos tienen cuenta.
     */
    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            return (new Timebox)->call(function () use ($request): ?User {
                $email = self::stringInput($request, Fortify::username());
                $password = self::stringInput($request, 'password', normalize: false);

                $user = $email === '' ? null : User::query()->where('email', $email)->first();

                if ($user === null) {
                    Hash::check($password, self::dummyPasswordHash());

                    return null;
                }

                if (! Hash::check($password, $user->password)) {
                    return null;
                }

                if (! $user->isActive()) {
                    event(new Failed((string) config('fortify.guard', 'web'), $user, [Fortify::username() => $email]));
                    app(LoginRateLimiter::class)->increment($request);

                    throw ValidationException::withMessages([
                        Fortify::username() => __('app.account_inactive'),
                    ]);
                }

                if (Hash::needsRehash($user->password)) {
                    $user->forceFill(['password' => $password])->save();
                }

                return $user;
            }, 200_000);
        });
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/two-factor-challenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/confirm-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        // Se ejecuta antes de validar la petición: el correo puede llegar como array o no llegar.
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(self::stringInput($request, Fortify::username()).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // POST /forgot-password: por IP y por correo (exista o no), para que no sirva para sondear
        // la plantilla ni para inundar un buzón.
        RateLimiter::for(ThrottlePasswordResetLinkRequests::LIMITER, function (Request $request) {
            $email = Str::transliterate(self::stringInput($request, Fortify::email()));

            return [
                Limit::perMinute(5)->by('ip:'.$request->ip()),
                Limit::perHour(5)->by('email:'.$email),
            ];
        });
    }

    /**
     * Campo de texto de la petición (en minúsculas y sin espacios si se normaliza), o '' si no es una cadena.
     */
    private static function stringInput(Request $request, string $key, bool $normalize = true): string
    {
        $value = $request->input($key);

        if (! is_string($value)) {
            return '';
        }

        return $normalize ? Str::lower(trim($value)) : $value;
    }

    /**
     * Hash ficticio con el algoritmo y el coste actuales, calculado una vez y guardado en caché.
     */
    private static function dummyPasswordHash(): string
    {
        $make = fn (): string => Hash::make(Str::random(40));

        try {
            $key = 'auth:dummy-password-hash:'.md5((string) json_encode([config('hashing.driver'), config('hashing.bcrypt'), config('hashing.argon')]));

            return (string) Cache::rememberForever($key, $make);
        } catch (Throwable) {
            return $make();
        }
    }
}
