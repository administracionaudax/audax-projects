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

    /**
     * Origen por la API REST con la clave secreta (D-237), sin la contraseña de la base.
     */
    public function apiSource(WeeklySyncCredentials $credentials): WeeklySyncSource
    {
        if ($credentials->projectUrl === null || $credentials->serviceKey === null) {
            throw new \RuntimeException('Para leer por la API hacen falta WEEKLYSYNC_URL y WEEKLYSYNC_SERVICE_KEY.');
        }

        return new RestWeeklySyncSource($credentials->projectUrl, $credentials->serviceKey);
    }

    public function storage(WeeklySyncCredentials $credentials): ?WeeklySyncStorage
    {
        if ($credentials->projectUrl === null || $credentials->serviceKey === null) {
            return null;
        }

        return new SupabaseStorage($credentials->projectUrl, $credentials->serviceKey);
    }
}
