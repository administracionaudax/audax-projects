<?php

namespace App\Domain\Import\WeeklySync\Dump;

/**
 * Abre el origen y el Storage con las credenciales. Está en el contenedor para que los tests del
 * comando lo sustituyan por dobles: los tests nunca se conectan a Supabase.
 */
class WeeklySyncConnector
{
    public function source(WeeklySyncCredentials $credentials): WeeklySyncSource
    {
        return new PostgresWeeklySyncSource($credentials->databaseUrl, $credentials->databasePassword);
    }

    public function storage(WeeklySyncCredentials $credentials): ?WeeklySyncStorage
    {
        if ($credentials->projectUrl === null || $credentials->serviceKey === null) {
            return null;
        }

        return new SupabaseStorage($credentials->projectUrl, $credentials->serviceKey);
    }
}
