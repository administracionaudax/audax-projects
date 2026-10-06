<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Un módulo nuevo llega apagado donde el ajuste `modules` ya está guardado (instalaciones en uso):
 * desplegarlo no lo abre a la plantilla; lo enciende un admin en /admin/ajustes cuando quiera. En una
 * instalación nueva (sin ajuste) todos los módulos siguen activos por defecto.
 */
return new class extends Migration
{
    public function up(): void
    {
        $modules = Setting::query()->where('key', 'modules')->value('value');

        if (is_array($modules) && $modules !== [] && ! array_key_exists('day_plan', $modules)) {
            Setting::set('modules', [...$modules, 'day_plan' => false]);
        }
    }

    public function down(): void
    {
        // Nada: el valor queda como lo dejó el admin.
    }
};
