<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * En PostgreSQL el índice único de users.email distingue mayúsculas: «ana@x.com» y «Ana@x.com»
 * podrían convivir. Se pasan a minúsculas los correos guardados y se añade un índice único sobre
 * lower(email). En SQLite (tests locales) no hace falta: la app ya guarda los correos en minúsculas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('UPDATE users SET email = lower(email) WHERE email <> lower(email)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique ON users (lower(email))');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    }
};
