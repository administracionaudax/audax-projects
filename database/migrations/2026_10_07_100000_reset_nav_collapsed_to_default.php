<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * D-261: la barra lateral nace con todo plegado menos Proyectos. Lo guardado con el comportamiento
 * anterior (todo desplegado, desde el 06/10) se olvida para que a todos les llegue el nuevo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNotNull('nav_collapsed')->update(['nav_collapsed' => null]);
    }

    public function down(): void
    {
        // Nada que deshacer: cada persona vuelve a plegar o desplegar a su gusto.
    }
};
