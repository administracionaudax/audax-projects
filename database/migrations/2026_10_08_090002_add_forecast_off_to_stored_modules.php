<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * La Previsión (módulo `forecast`, D-280) llega apagada donde el ajuste `modules` ya está guardado
 * (instalaciones en uso): un módulo que falta en el ajuste cuenta como activo y se abriría a toda la
 * plantilla al desplegar. La enciende un admin en /admin/ajustes cuando estén las pantallas. En una
 * instalación nueva también empieza apagada (Setting::DEFAULTS).
 */
return new class extends Migration
{
    public function up(): void
    {
        $modules = Setting::query()->where('key', 'modules')->value('value');

        if (is_array($modules) && $modules !== [] && ! array_key_exists('forecast', $modules)) {
            Setting::set('modules', [...$modules, 'forecast' => false]);
        }
    }

    public function down(): void
    {
        // Nada: el valor queda como lo dejó el admin.
    }
};
