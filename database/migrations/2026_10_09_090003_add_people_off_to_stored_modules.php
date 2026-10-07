<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * «Personas» (módulo `people`, Fase 11, D-330) llega apagado donde el ajuste `modules` ya está
 * guardado (instalaciones en uso): un módulo que falta en el ajuste cuenta como activo y se abriría
 * el registro de jornada a toda la plantilla al desplegar. Se enciende en /admin/ajustes cuando R1 y
 * R2 estén en producción (PLAN-FASE-11 §12). En una instalación nueva también empieza apagado
 * (Setting::DEFAULTS).
 */
return new class extends Migration
{
    public function up(): void
    {
        $modules = Setting::query()->where('key', 'modules')->value('value');

        if (is_array($modules) && $modules !== [] && ! array_key_exists('people', $modules)) {
            Setting::set('modules', [...$modules, 'people' => false]);
        }
    }

    public function down(): void
    {
        // Nada: el valor queda como lo dejó el admin.
    }
};
