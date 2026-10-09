<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * «Emisión de facturas» (módulo `invoicing`, PLAN-EMISION E1, D-417) llega apagado donde el ajuste
 * `modules` ya está guardado (instalaciones en uso): un módulo que falta en el ajuste cuenta como
 * activo. En modo de prueba (D-239) solo lo ven los admins con acceso a Facturación (D-245). En una
 * instalación nueva también empieza apagado (Setting::DEFAULTS).
 */
return new class extends Migration
{
    public function up(): void
    {
        $modules = Setting::query()->where('key', 'modules')->value('value');

        if (is_array($modules) && $modules !== [] && ! array_key_exists('invoicing', $modules)) {
            Setting::set('modules', [...$modules, 'invoicing' => false]);
        }
    }

    public function down(): void
    {
        // Nada: el valor queda como lo dejó el admin.
    }
};
