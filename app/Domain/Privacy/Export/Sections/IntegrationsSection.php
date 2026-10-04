<?php

namespace App\Domain\Privacy\Export\Sections;

use App\Models\GoogleConnection;
use App\Models\User;

/**
 * Integraciones (Fase 9, D-142): la cuenta de Google conectada para exportar a Google Sheets. Solo
 * el servicio, el correo de la cuenta y la fecha de conexión; nunca los tokens (D-075).
 */
final class IntegrationsSection extends Section
{
    public function key(): string
    {
        return 'integraciones';
    }

    protected function textKey(): string
    {
        return 'integrations';
    }

    protected function columnKeys(): array
    {
        return ['service', 'account', 'connected_at'];
    }

    public function rows(User $user): iterable
    {
        $connection = GoogleConnection::query()->where('user_id', $user->id)->first(['google_email', 'created_at']);

        if ($connection !== null) {
            yield [
                'service' => 'Google',
                'account' => $connection->google_email,
                'connected_at' => self::instant($connection->created_at),
            ];
        }
    }
}
