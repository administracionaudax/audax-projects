<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Auth\Google\GoogleLogin;
use App\Domain\Integrations\Google\GoogleOAuth;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SettingsRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ajustes globales (SPEC §14, Setting::DEFAULTS). Setting::set invalida la caché al guardar.
 * Muestra también el límite de subida del servidor (PHP), que puede ser menor que el de la app.
 */
class SettingsController extends Controller
{
    public function edit(): Response
    {
        Gate::authorize('manage-settings');

        $settings = [];
        foreach (array_keys(Setting::DEFAULTS) as $key) {
            $settings[$key] = Setting::get($key);
        }

        return Inertia::render('admin/settings', [
            'settings' => $settings,
            'roundings' => SettingsRequest::ROUNDINGS,
            'serverUploadLimitMb' => self::serverUploadLimitMb(),
            // Entrar con Google (D-165): el interruptor solo tiene efecto con credenciales.
            'googleLogin' => [
                'configured' => GoogleOAuth::configured(),
                'domains' => GoogleLogin::allowedDomains(),
            ],
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $old = [];
            $new = [];

            foreach ($request->settings() as $key => $value) {
                if (Setting::get($key) !== $value) {
                    $old[$key] = Setting::get($key);
                    $new[$key] = $value;
                    Setting::set($key, $value);
                }
            }

            // Auditoría visible (D-074): los cambios de los ajustes, con el antes y el después.
            if ($new !== []) {
                activity('settings')
                    ->causedBy($request->user())
                    ->event('updated')
                    ->withProperties(['old' => $old, 'attributes' => $new])
                    ->log('settings.updated');
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('admin.settings.saved')]);

        return back();
    }

    /**
     * Menor de upload_max_filesize y post_max_size, en MB (null si no hay límite).
     */
    public static function serverUploadLimitMb(): ?int
    {
        $limits = array_filter(
            [self::iniBytes((string) ini_get('upload_max_filesize')), self::iniBytes((string) ini_get('post_max_size'))],
            fn (int $bytes): bool => $bytes > 0,
        );

        return $limits === [] ? null : intdiv(min($limits), 1024 * 1024);
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || $value === '0' || $value === '-1') {
            return 0;
        }

        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
