<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * «Facturación» (módulo `billing`, Fase 12, D-380) llega apagado donde el ajuste `modules` ya está
 * guardado (instalaciones en uso): un módulo que falta en el ajuste cuenta como activo. Se enciende en
 * /admin/ajustes cuando la clave de Holded esté puesta (docs/PLAN-FASE-12.md §6). En una instalación
 * nueva también empieza apagado (Setting::DEFAULTS).
 */
return new class extends Migration
{
    public function up(): void
    {
        $modules = Setting::query()->where('key', 'modules')->value('value');

        if (is_array($modules) && $modules !== [] && ! array_key_exists('billing', $modules)) {
            Setting::set('modules', [...$modules, 'billing' => false]);
        }
    }

    public function down(): void
    {
        // Nada: el valor queda como lo dejó el admin.
    }
};
