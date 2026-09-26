<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

test('sin filas, Setting::get devuelve los valores por defecto del SPEC', function () {
    expect(Setting::get('require_2fa'))->toBeFalse()
        ->and(Setting::get('timer_rounding_minutes'))->toBe(1)
        ->and(Setting::get('max_attachment_mb'))->toBe(50)
        ->and(Setting::get('clave_desconocida'))->toBeNull()
        ->and(Setting::get('clave_desconocida', 'x'))->toBe('x');
});

test('Setting::set guarda valores JSON e invalida la caché', function () {
    expect(Setting::get('require_2fa'))->toBeFalse();

    Setting::set('require_2fa', true);
    Setting::set('hour_bank_alert_thresholds', [80, 95]);

    expect(Setting::get('require_2fa'))->toBeTrue()
        ->and(Setting::get('hour_bank_alert_thresholds'))->toBe([80, 95])
        ->and(Setting::query()->where('key', 'require_2fa')->count())->toBe(1);
});

test('Setting::get lee de la caché', function () {
    Setting::set('company_name', 'Audax');
    Setting::get('company_name');

    DB::table('settings')->where('key', 'company_name')->update(['value' => json_encode('Cambiado por fuera')]);

    expect(Setting::get('company_name'))->toBe('Audax');

    Cache::forget(Setting::CACHE_KEY);

    expect(Setting::get('company_name'))->toBe('Cambiado por fuera');
});

test('un valor falso guardado no se confunde con uno ausente', function () {
    Setting::set('allow_hour_bank_overage', false);

    expect(Setting::get('allow_hour_bank_overage', true))->toBeFalse();
});
