<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La búsqueda de personas ignora los acentos en PostgreSQL con la extensión unaccent («lucia»
 * encuentra a «Lucía»). En PostgreSQL 13+ es una extensión «trusted»: la puede crear el propietario
 * de la base sin ser superusuario. En SQLite (tests locales) no hace falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    /**
     * No se borra: otra base u objeto podría depender de ella y no molesta.
     */
    public function down(): void
    {
        //
    }
};
